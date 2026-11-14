<?php

namespace Wep\Router\Http;

use Wep\Router\RouteMap;

/**
 * Request HTTP do ciclo atual. Um único objeto por requisição, criado pela RouteCollection
 * depois do matching (antes era criado um por rota registrada).
 *
 * Precedência em data() e nas propriedades mágicas ($request->campo), do menor para o maior:
 *   query string  <  corpo (POST/JSON/form)  <  parâmetros da rota ({id})  <  "custom" da rota
 * Assim a query string não sobrescreve o corpo nem os parâmetros da rota.
 */
class RouterHttpRequest
{
    /** @var string[] métodos aceitos em form spoofing (_method) */
    private const SPOOFING = ["PUT", "PATCH", "DELETE"];

    /** @var string[] IPs/CIDRs de proxies confiáveis (únicos autorizados a definir X-Forwarded-*) */
    private static array $trustedProxies = [];

    /** @var string|null corpo bruto (php://input), lido uma única vez por processo */
    private static ?string $rawBody = null;

    private $fullUrl;

    private $httpMethod;

    private $data = [];

    private $routeMapData;

    private $params = [];

    private $headers;

    public $originalRoute;

    public $get = array();
    public $post = array();
    public $cookie = array();
    public $files = array();

    /**
     * @param string $fullUrl URL base do projeto (APP_URL)
     * @param string $route rota registrada (ex.: /user/{id}/edit)
     * @param array<string, string> $routeParams parâmetros capturados (nome => valor)
     */
    public function __construct(
        String $fullUrl,
        String $route,
        array $routeParams = []
    ) {
        $this->post = $_POST;
        $this->get = $_GET ?? [];
        $this->cookie = $_COOKIE;
        $this->files = $_FILES;

        $this->fullUrl = $fullUrl . ($_SERVER['REQUEST_URI'] ?? '/');
        $this->httpMethod = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        unset($this->get["route"]);

        $this->headers = $this->resolveHeaders();
        $this->originalRoute = $route ?: "/";

        // lista de nomes (formato antigo) não traz valores: só pares nome => valor são usados
        $this->params = array_is_list($routeParams) ? [] : array_map('strval', $routeParams);

        if ($this->httpMethod !== 'GET' && $this->httpMethod !== 'HEAD') {
            $this->setData();
        }
    }

    /**
     * Proxies confiáveis para ip() e isSecure(). Aceita IPs e CIDRs (IPv4/IPv6).
     *
     * @param string[] $proxies
     */
    public static function setTrustedProxies(array $proxies): void
    {
        self::$trustedProxies = array_values(array_filter(array_map('strval', $proxies)));
    }

    /**
     * @param	array	$data
     * @return	array
     */
    public function clean($data)
    {
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                unset($data[$key]);

                $data[$this->clean($key)] = $this->clean($value);
            }
        } elseif (!is_double($data) && !is_int($data) && !is_float($data) && !is_bool($data)) {
            $data = htmlspecialchars((string) $data, ENT_COMPAT, 'UTF-8');
        }

        return $data;
    }

    /**
     * Method responsible for bringing
     * all request headers.
     *
     * @return array
     */
    protected function resolveHeaders()
    {
        $headers = [];

        foreach ($_SERVER as $name => $value) {
            if (strncmp($name, 'HTTP_', 5) === 0) {
                $index = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
                $headers[$index] = $value;
            }
        }

        // sem o prefixo HTTP_ no $_SERVER
        foreach (['CONTENT_TYPE' => 'Content-Type', 'CONTENT_LENGTH' => 'Content-Length'] as $name => $index) {
            if (isset($_SERVER[$name]) && !isset($headers[$index])) {
                $headers[$index] = $_SERVER[$name];
            }
        }

        return $headers;
    }

    /**
     * Method responsible for returning the
     * current route in parts.
     *
     * @return string|null|array
     */
    protected function getParsedRoute(?String $data = null)
    {
        $parsedRoute = parse_url($this->fullUrl ?? "/");

        if (empty($data)) return $parsedRoute;

        return $parsedRoute[$data] ?? null;
    }

    /**
     * Method responsible for returning
     * the current route path.
     *
     * @return string
     */
    public function path()
    {
        return $this->getParsedRoute("path") ?? "/";
    }

    /**
     * Method responsible for returning
     * the current route without query strings.
     *
     * @return null|string
     */
    public function url()
    {
        $path = $this->getParsedRoute();

        if (!$path || !isset($path['scheme'], $path['host'])) return null;

        $port = isset($path['port']) ? ':' . $path['port'] : '';

        return "{$path['scheme']}://{$path['host']}{$port}{$this->path()}";
    }

    /**
     * Method responsible for returning
     * the full current route (with query strings).
     *
     * @return null|string
     */
    public function fullUrl()
    {
        $query = $this->getParsedRoute("query");

        return $query ? "{$this->url()}?{$query}" : $this->url();
    }

    /**
     * Parâmetros dinâmicos da rota (nome => valor).
     *
     * @return array<string, string>
     */
    public function getParams()
    {
        return $this->params;
    }

    /**
     * Um parâmetro dinâmico da rota.
     */
    public function param(String $name, $default = null)
    {
        return $this->params[$name] ?? $default;
    }

    /**
     * Method responsible for returning one or all
     * data coming from the params query ($_GET).
     *
     * @param null|string $name = null
     * @param null|string $asDefault = null
     *
     * @return array|string
     */
    public function query(?String $name = null, $asDefault = null)
    {
        if (!$name) {
            return $this->get;
        }

        return $this->get[$name] ?? $asDefault;
    }

    /**
     * Um campo da requisição (mesma precedência de data()), ou null.
     *
     * Antes devolvia o array "custom" inteiro da rota para qualquer propriedade inexistente,
     * então $request->keep ?? false podia virar um array (truthy).
     *
     * @param string $data
     *
     * @return mixed
     */
    public function __get(String $data)
    {
        return $this->data($data);
    }

    public function __isset(String $data): bool
    {
        return $this->data($data) !== null;
    }

    /**
     * Lê e interpreta o corpo da requisição (uma vez só).
     *
     * - POST de formulário/multipart: $_POST
     * - JSON (Content-Type application/json ou corpo começando com { / [): json_decode
     * - PUT/PATCH/DELETE com form-urlencoded: parse_str
     * - Form spoofing: POST com _method=PUT|PATCH|DELETE
     *
     * Antes PUT/PATCH/DELETE com JSON passavam por parse_str e chegavam corrompidos, o que
     * quebrava o update/delete enviados pelo front.
     *
     * @return void
     */
    protected function setData()
    {
        $post = $_POST;
        $spoofed = is_string($post['_method'] ?? null) ? strtoupper($post['_method']) : null;

        if ($this->httpMethod === 'POST' && in_array($spoofed, self::SPOOFING, true)) {
            $this->httpMethod = $spoofed;
        }

        if (!empty($post)) {
            unset($post['_method']);
            $this->data = $post;
            return;
        }

        $raw = self::rawBody();

        if ($raw === '') {
            $this->data = [];
            return;
        }

        $contentType = strtolower($this->header('Content-Type', ''));
        $looksJson = $raw[0] === '{' || $raw[0] === '[';

        if (str_contains($contentType, 'json') || $looksJson) {
            $json = json_decode($raw, true);

            if (is_array($json)) {
                $this->data = $json;
                return;
            }

            if (str_contains($contentType, 'json')) {
                $this->data = [];
                return;
            }
        }

        if ($contentType === '' || str_contains($contentType, 'application/x-www-form-urlencoded')) {
            parse_str($raw, $parsed);
            unset($parsed['_method']);
            $this->data = $parsed;
            return;
        }

        $this->data = [];
    }

    /**
     * Corpo bruto da requisição.
     */
    public static function rawBody(): string
    {
        return self::$rawBody ??= (string) file_get_contents('php://input');
    }

    /**
     * @internal substitui o corpo bruto (testes). null volta a ler php://input.
     */
    public static function setRawBody(?string $body): void
    {
        self::$rawBody = $body;
    }

    /**
     * Return the httpMethod.
     *
     * @return string
     */
    public function getMethod()
    {
        return $this->httpMethod;
    }

    /**
     * Method responsible for checking if the current http method
     * is the expected.
     *
     * @param string $expectedMethod
     *
     * @return bool
     */
    public function isMethod(String $expectedMethod): bool
    {
        return $this->getMethod() === strtoupper($expectedMethod);
    }

    /**
     * Dados do corpo da requisição, opcionalmente sem alguns campos.
     *
     * @param null|string $except campos separados por vírgula
     *
     * @return array
     */
    public function all(?String $except = null)
    {
        $data = $this->data ?? [];

        if (!$except) {
            return $data;
        }

        // antes devolvia null quando um dos campos não existia no corpo
        foreach (explode(',', $except) as $field) {
            unset($data[trim($field)]);
        }

        return $data;
    }

    /**
     * Method responsible for returning all header data.
     *
     * @return array
     */
    public function getHeaders()
    {
        return $this->headers;
    }

    /**
     * Um header, sem diferenciar maiúsculas (header('authorization') funciona).
     *
     * @param string $header
     * @param null|string $asDefault
     *
     * @return null|string
     */
    public function header(String $header, ?String $asDefault = null)
    {
        if (isset($this->headers[$header])) {
            return $this->headers[$header];
        }

        foreach ($this->headers as $name => $value) {
            if (strcasecmp($name, $header) === 0) {
                return $value;
            }
        }

        return $asDefault;
    }

    /**
     * Method responsible for checking if there is one
     * header in the request.
     *
     * @param string $header
     *
     * @return bool
     */
    public function hasHeader(String $header)
    {
        return (bool) $this->header($header);
    }

    /**
     * IP do cliente.
     *
     * X-Forwarded-For só é considerado quando a conexão vem de um proxy confiável
     * (setTrustedProxies / config "security.trusted_proxies"). Antes qualquer cliente
     * podia forjar o IP enviando o header.
     *
     * @return string
     */
    public function ip()
    {
        $remote = $_SERVER['REMOTE_ADDR'] ?? '';

        if (!self::isTrustedProxy($remote) || empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return $remote;
        }

        // da direita para a esquerda: o primeiro IP que não é proxy confiável é o cliente
        $chain = array_reverse(array_map('trim', explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])));

        foreach ($chain as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP) && !self::isTrustedProxy($ip)) {
                return $ip;
            }
        }

        return $remote;
    }

    /**
     * Requisição via HTTPS (considera X-Forwarded-Proto apenas de proxy confiável).
     */
    public function isSecure(): bool
    {
        return self::isHttps();
    }

    public static function isHttps(): bool
    {
        $https = $_SERVER['HTTPS'] ?? '';

        if (($https !== '' && strtolower($https) !== 'off') || ($_SERVER['SERVER_PORT'] ?? null) == 443) {
            return true;
        }

        return self::isTrustedProxy($_SERVER['REMOTE_ADDR'] ?? '')
            && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }

    /**
     * Token do header "Authorization: Bearer <token>".
     *
     * Antes devolvia o header inteiro ("Bearer <token>").
     *
     * @return null|string
     */
    public function bearerToken()
    {
        $authorizationHeader = $this->header('Authorization');

        if ($authorizationHeader && preg_match('/^Bearer\s+(\S+)\s*$/i', $authorizationHeader, $found)) {
            return $found[1];
        }

        return null;
    }

    /**
     * Método responsável por retornar
     * os dados da rotas extraidas do
     * arquivo de build do map das rotas
     * @param string $key
     * @param string $defaultValue
     *
     */
    public function routeMapData($key = null, $defaultValue = null)
    {
        if (!$this->routeMapData) {
            $route_method = strtolower($this->httpMethod);
            $route_data = RouteMap::get($this->originalRoute);

            $this->routeMapData = method_exists($route_data, $route_method)
                ? $route_data->{$route_method}()
                : $route_data->post();
        }

        return $key ? ($this->routeMapData->{$key} ?? $defaultValue) : $this->routeMapData;
    }

    /**
     * Todos os dados da requisição (query, corpo, parâmetros e custom da rota), ou um campo.
     *
     * @param string $key
     * @param mixed $defaultValue
     */
    public function data($key = null, $defaultValue = null)
    {
        $custom = $this->routeMapData('custom', []);

        $data = array_merge(
            $this->get,
            ($this->data ?? []),
            ($this->params ?? []),
            is_array($custom) ? $custom : (array) $custom
        );

        return $key ? ($data[$key] ?? $defaultValue) : $data;
    }

    private static function isTrustedProxy(string $ip): bool
    {
        if ($ip === '' || !self::$trustedProxies) {
            return false;
        }

        foreach (self::$trustedProxies as $proxy) {
            if ($proxy === $ip || (str_contains($proxy, '/') && self::inCidr($ip, $proxy))) {
                return true;
            }
        }

        return false;
    }

    private static function inCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr, 2) + [1 => ''];

        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);

        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin) || !ctype_digit($bits)) {
            return false;
        }

        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        $remainder = $bits % 8;

        if (strncmp($ipBin, $subnetBin, $bytes) !== 0) {
            return false;
        }

        if ($remainder === 0) {
            return true;
        }

        $mask = chr((0xFF << (8 - $remainder)) & 0xFF);

        return (($ipBin[$bytes] ?? "\0") & $mask) === (($subnetBin[$bytes] ?? "\0") & $mask);
    }
}
