<?php

namespace Wep;

use Wep\Engine\Module;
use Wep\Env\Env;
use Wep\Env\EnvCache;
use Wep\Foundation\Config;
use Wep\Foundation\ErrorHandler;
use Wep\Foundation\Paths;
use Wep\Http\HttpException;
use Wep\Http\OriginGuard;
use Wep\Http\SecurityHeaders;
use Wep\Router\Http\RouterHttpRequest;
use Wep\Router\Route;
use Wep\Router\RouteRegistrar;

/**
 * Fachada da aplicação. A classe App do projeto estende esta classe.
 *
 * @example
 * $app = App::start(include '../config/app.php');
 * $app->run();
 */
class Core
{
    /** @var array<string, mixed> dados de config/data.php */
    public static $data = [];

    /** @var Env|null */
    public static $env = null;

    /** @var array<string, mixed> config/app.php */
    private static $config = [];

    private static bool $started = false;

    private static ?Config $repository = null;

    private static ?Paths $paths = null;

    private static ?EnvCache $envCache = null;

    /**
     * Inicializa a aplicação.
     *
     * @param array $config conteúdo de config/app.php
     * @param string $config['name']
     * @param string $config['title'] título do documento
     * @param array  $config['dir'] root (obrigatório), name (subpasta na URL)
     * @param string $config['env'] caminho do .env (padrão: config/.env)
     * @param string $config['timezone'] ex.: "America/Sao_Paulo"
     * @param bool   $config['error_handler'] tratamento central de erros (padrão: true)
     *
     * @throws \InvalidArgumentException quando dir.root não for informado
     */
    public static function start($config = [])
    {
        $config = (array) $config;
        $root = $config['dir']['root'] ?? null;

        if (!is_string($root) || $root === '') {
            throw new \InvalidArgumentException("Diretório root não informado na configuração do App (dir.root)");
        }

        if (self::$started) {
            return new static();
        }

        self::$config = $config;
        self::$repository = new Config($config);
        self::$paths = new Paths($root);

        Session::configure(is_array($config['session'] ?? null) ? $config['session'] : []);
        self::$paths->defineLegacyConstants();

        $useErrorHandler = $config['error_handler'] ?? true;

        // registrado antes do .env para cobrir falhas de boot; o modo debug é ajustado logo depois
        if ($useErrorHandler) {
            ErrorHandler::register(false, self::$paths->log());
        }

        self::startEnv();

        self::defineConstant('ENV', (string) self::env('ENV', ''));
        self::defineConstant('ENV_DEV', ENV == 'DEV');
        self::defineConstant('APP_URL', (string) self::env('APP_URL', ''));
        self::defineConstant('CORE_NAMESPACE', 'Wep\\');
        self::defineConstant('DEFAULT_VIEW_CONTROLLER', CORE_NAMESPACE . 'View\\View@handle');

        if ($useErrorHandler) {
            ErrorHandler::configure(ENV_DEV);
        }

        $timezone = self::$repository->get('timezone');

        if ($timezone) {
            date_default_timezone_set($timezone);
        }

        self::defineAppConstants();

        self::$started = true;

        Route::start(APP_URL, '@');

        if (file_exists(ROUTE_MAPS_BUILD_FILENAME_BACK)) {
            $routes = include ROUTE_MAPS_BUILD_FILENAME_BACK;

            // build novo: só dados. Build antigo (código PHP que registra as rotas) devolve 1.
            if (is_array($routes)) {
                RouteRegistrar::register($routes);
            }
        }

        self::loadData();

        return new static();
    }

    /**
     * Atende a requisição: headers de segurança, proteção CSRF e roteamento.
     *
     * config/app.php, chave "security" (todas opcionais):
     *   'headers' => true|false|[...], 'hsts' => false|true|[...],
     *   'csrf' => true, 'trusted_origins' => [...], 'csrf_except' => [...],
     *   'trusted_proxies' => ['10.0.0.1', '10.0.0.0/8']
     */
    public static function run()
    {
        $security = self::security();

        RouterHttpRequest::setTrustedProxies((array) ($security['trusted_proxies'] ?? []));
        SecurityHeaders::send($security);

        try {
            OriginGuard::enforce($_SERVER, $security, defined('APP_URL') ? APP_URL : '', self::requestPath());
        } catch (HttpException $e) {
            ErrorHandler::log('WARNING', $e->getMessage() . ' [' . ($_SERVER['REQUEST_METHOD'] ?? '') . ' ' . ($_SERVER['REQUEST_URI'] ?? '') . ']');
            ErrorHandler::render($e);
            return;
        }

        Route::execute();
    }

    /**
     * Config "security" do app.
     *
     * @return array<string, mixed>
     */
    public static function security(): array
    {
        $security = self::repository()->get('security', []);

        return is_array($security) ? $security : [];
    }

    /** O mesmo caminho que o roteador usa (Wep\Http\RequestPath). */
    private static function requestPath(): string
    {
        return \Wep\Http\RequestPath::current();
    }

    public static function isStarted(): bool
    {
        return self::$started;
    }

    /**
     * Retorna dados de configuração da aplicação.
     *
     * "Sem valor" é null ou "" (string vazia): nesses casos vale o fallback.
     * false, 0 e "0" são valores válidos e são devolvidos como estão.
     *
     * @example
     * # Case 0: todas as configs
     * App::config();
     *
     * # Case 1: configs do app (config/app.php)
     * App::config("name");
     * App::config("name", 'fallback');
     * App::config("name|title");             // name ou title
     * App::config("name|title", 'fallback'); // name, title ou fallback
     * App::config("dir.root");               // chaves aninhadas
     *
     * # Case 2: variáveis do .env
     * App::config("env", "key");
     * App::config("env", "key", 'fallback');
     * App::config("env.key");
     * App::config("env.key", 'fallback');
     *
     * # Case 3: config dos módulos
     * App::config("module", "Pedido")->routes;
     * App::config("module", "Vendor.Pedido")->routes;
     * App::config("module", "Pedido", fallback)->routes;
     */
    public static function config()
    {
        $args = func_get_args();
        $count = count($args);

        if (!$count) {
            return self::$config;
        }

        $first = $args[0];
        $second = $args[1] ?? null;
        $third = $args[2] ?? null;
        $lower = is_string($first) ? strtolower($first) : '';

        // Case 3
        if ($lower === 'module' || $lower === 'modules') {
            if (!$second) {
                return Obj::set();
            }

            $module = Module::getConfigs($second);

            if (empty($module) && $third) {
                $module = $third;
            }

            return Obj::set($module);
        }

        // Case 2
        if (str_starts_with($lower, 'env.')) {
            return self::env(substr($first, 4), $second);
        }

        if ($lower === 'env' && $count > 1) {
            return self::env((string) $second, $third);
        }

        // Case 1
        return self::repository()->get((string) $first, $second);
    }

    /**
     * Valor de uma variável do .env (o nome não diferencia maiúsculas).
     * Variáveis do ambiente real do servidor têm prioridade sobre o arquivo.
     */
    public static function env(string $key, mixed $default = null): mixed
    {
        $key = strtoupper($key);
        $value = self::$env?->get($key);

        if (Config::isMissing($value)) {
            $value = getenv($key);
            $value = $value === false ? null : $value;
        }

        return Config::isMissing($value) ? $default : $value;
    }

    /**
     * URL pública de um arquivo em public/assets.
     *
     * @param string $path
     * @param string|int|bool|null $cacheBooster true usa a data de modificação do arquivo
     *
     * @example
     * App::assets('path/to/file.jpg');       // /assets/path/to/file.jpg
     * App::assets('path/to/file.jpg', true); // /assets/path/to/file.jpg?v=1731600000
     * App::assets('path/to/file.jpg', 123);  // /assets/path/to/file.jpg?v=123
     */
    public static function assets($path = "", $cacheBooster = null)
    {
        $url = '/assets';
        $dirName = self::config('dir.name');

        if ($dirName) {
            $url = '/' . $dirName . $url;
        }

        if (!$path) {
            return $url;
        }

        $url .= $path[0] === '/' ? $path : '/' . $path;

        if ($cacheBooster !== null && $cacheBooster !== false) {
            $version = $cacheBooster === true ? self::assetVersion($path) : $cacheBooster;
            $url .= (str_contains($url, '?') ? '&' : '?') . 'v=' . $version;
        }

        return $url;
    }

    /**
     * Retorna dados customizados do app (config/data.php)
     *
     * @example
     * App::data();
     * App::data('key');
     * App::data('key|default_key');
     * App::data('key', 'default value');
     */
    public static function data($key = null, $default = null)
    {
        $data = self::$data ?? [];

        if (!$key) {
            return empty($data) ? null : Obj::set($data);
        }

        return (new Config((array) $data))->get($key, $default);
    }

    /**
     * Caminho absoluto dentro da aplicação.
     *
     * @example
     * App::path();                // raiz
     * App::path("/config/.env");
     * App::path("config", ".env");
     */
    public static function path(string ...$segments): string
    {
        return self::paths()->root(...$segments);
    }

    public static function paths(): Paths
    {
        if (!self::$paths) {
            throw new \LogicException('A aplicação ainda não foi iniciada (App::start)');
        }

        return self::$paths;
    }

    /**
     * Sincroniza o cache compilado do .env (chamado pelo `php app update`):
     * fora de DEV grava storage/builds/env.build.php; em DEV remove o arquivo.
     *
     * @return bool true quando o cache foi gravado
     */
    public static function syncEnvCache(): bool
    {
        if (!self::$env || !self::$envCache) {
            return false;
        }

        if (defined('ENV_DEV') && ENV_DEV) {
            self::$envCache->clear();
            return false;
        }

        return self::$envCache->write(self::$env->parse());
    }

    private static function repository(): Config
    {
        return self::$repository ??= new Config(self::$config);
    }

    private static function startEnv(): void
    {
        $path = self::repository()->get('env') ?? self::$paths->config('.env');

        $env = new Env($path);
        $cache = new EnvCache(self::$paths->builds('env.build.php'));
        $cached = $cache->read($path);

        $cached === null ? $env->load() : $env->apply($cached);

        self::$env = $env;
        self::$envCache = $cache;
    }

    /**
     * Constantes do app e dos módulos (storage/builds/constants.build.php, gerado pelo update).
     * Formato novo: dados ['NOME' => ['value' => x] | ['parts' => [['const', 'DIR_ROOT'], ['text', '/x']]]].
     * Formato antigo (define() gerado por concatenação) é só incluído.
     *
     * Também chamado pelo `php app update` depois de gerar o arquivo: as constantes novas valem na
     * mesma execução (ex.: o instalador usa os nomes de tabela logo após o build).
     */
    public static function defineAppConstants(): void
    {
        if (!file_exists(APP_CONSTANTS_FILENAME)) {
            return;
        }

        $constants = include APP_CONSTANTS_FILENAME;

        if (!is_array($constants)) {
            return;
        }

        foreach ($constants as $name => $spec) {
            if (defined($name) || !is_array($spec)) {
                continue;
            }

            if (array_key_exists('value', $spec)) {
                define($name, $spec['value']);
                continue;
            }

            $value = '';

            foreach ($spec['parts'] ?? [] as [$type, $content]) {
                $value .= $type === 'const' ? (defined($content) ? (string) constant($content) : '') : $content;
            }

            define($name, $value);
        }
    }

    private static function loadData(): void
    {
        $filename = self::$paths->config('data.php');

        if (!file_exists($filename)) {
            return;
        }

        $data = include_once $filename;

        self::$data = is_array($data) ? $data : [];
    }

    private static function assetVersion(string $path): int
    {
        $file = self::paths()->assets(strtok($path, '?') ?: $path);

        return is_file($file) ? (int) filemtime($file) : time();
    }

    private static function defineConstant(string $name, mixed $value): void
    {
        if (!defined($name)) {
            define($name, $value);
        }
    }
}
