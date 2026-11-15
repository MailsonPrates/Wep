<?php

namespace Wep\Router;

use Wep\Router\Http\RouterHttpRequest;
use Wep\Router\Traits\RouteManagerUtils;
use Wep\Router\Middlewares\MiddlewareCollection;

class RouteManager
{
    use RouteManagerUtils;

    /** @var string */
    protected $name;

    /** @var object */
    protected $group;

    /** @var string */
    protected $route;

    /** @var string|\Closure */
    protected $action;

    /** @var string */
    protected $fullUrl;

    /** @var string|null */
    protected $handler;

    /** @var object */
    protected $request;

    /** @var string */
    private $defaultName;

    /** @var string */
    protected $separator;
    
    /** @var object */
    protected $middleware;

    /** @var array */
    protected $where = [];

    /** @var string */
    protected $originalRoute;

    /** @var string */
    private $defaultNamespace;

    /** @var string */
    protected $defaultRegex = "[^/]+";

    /** @var string|null regex compilada (sem delimitadores); invalidada por where() */
    private $compiled = null;

    /** @var array<int, string> nome de cada parâmetro, na ordem do grupo de captura p0, p1... */
    private $paramNames = [];

    public function __construct($fullUrl, $uri, $callback, String $separator, ?RouteGroups $group = null)
    {
        $this->route = $uri;
        $this->group = $group;
        $this->fullUrl = $fullUrl;
        $this->originalRoute = $uri;
        $this->separator = $separator;

        // O request não é mais criado aqui (antes: um por rota registrada, relendo headers e
        // corpo da requisição N vezes). A RouteCollection cria um único request no dispatch.

        $this->compileGroupData();
        $this->compileAction($callback);
    }

    /**
     * Rota sem parâmetros dinâmicos ({id}): é resolvida por lookup direto, sem regex.
     */
    public function isStatic(): bool
    {
        return !str_contains($this->originalRoute, '{');
    }

    /**
     * Verifica se a URI casa com a rota.
     *
     * @return array<string, string>|null parâmetros capturados (nome => valor) ou null se não casar
     */
    public function matches(string $uri): ?array
    {
        if (!preg_match('~^' . $this->getRoute() . '$~u', $uri, $found)) {
            return null;
        }

        $params = [];

        foreach ($this->paramNames as $index => $name) {
            $value = $found["p$index"] ?? '';

            if ($value !== '') {
                $params[$name] = $value;
            }
        }

        return $params;
    }

    /**
     * Define o request do ciclo atual (chamado pela RouteCollection antes do dispatch).
     */
    public function setRequest(RouterHttpRequest $request): void
    {
        $this->request = $request;
    }

    /**
     * Method responsible for returning
     * the current group.
     * 
     * @return null|\Wep\Router\RouteGroups
     */
    public function getGroup()
    {
        return $this->group;
    }

    /**
     * Method responsible for defining the
     * settings of the group the is part of.
     * 
     * @return null
     */
    private function compileGroupData()
    {
        if(!is_a($this->group, RouteGroups::class)) return null;

        $this->defaultName = $this->name = $this->group->name;
        $this->defaultNamespace = $this->group->namespace;

        if(is_a($this->group->middlewares, MiddlewareCollection::class)) {
            $this->middleware = $this->group->middlewares;
        }

        return null;
    }

    /**
     * Method responsible for returning the filtered route.
     * 
     * @return string
     */
    public function getRoute(): String
    {
        return $this->filteredRoute();
    }

    /**
     * Compila a rota em regex (sem delimitadores), com um grupo nomeado (p0, p1...) por parâmetro.
     * Trechos literais são escapados (antes um "." na rota casava com qualquer caractere).
     *
     * /user/{id}/edit  →  /user/(?P<p0>[^/]+)/edit/?
     * /post/{slug?}    →  /post(?:/(?P<p0>[^/]+))?/?
     *
     * @return string
     */
    protected function filteredRoute(): String
    {
        if ($this->compiled !== null) {
            return $this->compiled;
        }

        preg_match_all("~\{\s*([a-zA-Z_][a-zA-Z0-9_-]*)(\?)?\s*\}~", $this->route, $found, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        $pattern = '';
        $offset = 0;
        $this->paramNames = [];

        foreach ($found as $index => $match) {
            [$placeholder, $position] = $match[0];
            $name = $match[1][0];
            $isOptional = ($match[2][0] ?? '') === '?';

            $literal = substr($this->route, $offset, $position - $offset);
            $group = "(?P<p$index>" . ($this->getWhere($name) ?? $this->defaultRegex) . ")";

            if ($isOptional && str_ends_with($literal, '/')) {
                $pattern .= preg_quote(substr($literal, 0, -1), '~') . "(?:/$group)?";
            } else {
                $pattern .= preg_quote($literal, '~') . $group . ($isOptional ? '?' : '');
            }

            $this->paramNames[$index] = $name;
            $offset = $position + strlen($placeholder);
        }

        $pattern .= preg_quote(substr($this->route, $offset), '~');

        return $this->compiled = $pattern . '/?';
    }

    /**
     * Method responsible for returning the regular
     * expression of a dynamic parameter.
     * 
     * @param string $param
     * 
     * @return null|string
     */
    protected function getWhere(String $param): ?String
    {
        if (!isset($this->where[$param])) return null;

        return $this->where[$param];
    }

    /**
     * Return the where of router.
     * 
     * @return void
     */
    protected function setWhereData(String $key, String $value)
    {
        $this->where[$key] = $value;
        $this->compiled = null;
    }

    /**
     * Method responsible for returning all dynamic parameters.
     * 
     * @return array
     */
    protected function foundParameters($wordOnly = false, $originalRoute = false): array
    {
        $route = $originalRoute ? $this->originalRoute : $this->route;

        preg_match_all("~\{\s*([a-zA-Z_][a-zA-Z0-9_-]*)\??\}~x", $route, $params, PREG_SET_ORDER);

        if ($wordOnly) {
            $params = array_map(function ($param) {
                return $param[1];
            }, $params);
        }

        return $params;
    }

    /**
     * Method responsible for returning the parameters
     * for the route action.
     * 
     * @return array
     */
    public function closureReturn(): array
    {
        // a action recebe somente o request; os parâmetros ficam em $request->getParams()
        return [$this->request()];
    }

    /**
     * Method responsible for separating and defining the class and method,
     * or the anonymous function of the route.
     * 
     * @param string
     * 
     * @return null
     */
    private function compileAction($callback)
    {
        $handler = '';
        $action = '';

        if ($callback instanceof \Closure) {
            $this->action = $callback;
            return null;
        }

        if (is_string($callback)) {
            [$handler, $action] = explode($this->separator, $callback);
        }

        if (is_array($callback)) {
            $handler = $callback[0];
            $action = $callback[1];
        }

        $this->handler = $this->resolveHandler($handler);
        $this->action = $action;

        return null;
    }
    
    /**
     * Method responsible for returning the correct controller,
     * including the namespace of a group.
     * 
     * @param string $handler
     * 
     * @return string
     */
    private function resolveHandler(String $handler)
    {
        if(!$this->defaultNamespace) {
            return $handler;
        }

        $namespace = $this->parseDefaultString($this->defaultNamespace);
        
        return $namespace . $this->parseDefaultString($handler);
    }

    /**
     * Method responsible for applying the default
     * rule in the controller string.
     * 
     * @param string $handler
     * 
     * @return string
     */
    private function parseDefaultString(String $handler)
    {
        if (!preg_match("/^\\\/", $handler, $match)) {
            $handler = "\\{$handler}";
        }

        if (preg_match("/\/$/", $handler, $match)) {
            $handler = rtrim($handler, "/");
        }

        return $handler;
    }

    /**
     * Get the route name.
     * 
     * @return null|string
     */
    public function getName(): ?String
    {
        return $this->name;
    }

    /**
     * Set the route name.
     * 
     * @return void
     */
    protected function setName(String $value)
    {
        $this->name = $value;
    }

    /**
     * Get the route default name.
     * 
     * @return string|null
     */
    protected function getDefaultName()
    {
        return $this->defaultName;
    }

    /**
     * Get the route action.
     * 
     * @return mixed|\Closure|string
     */
    public function getAction()
    {
        return $this->action;
    }

    /**
     * Get the route action and handler.
     * 
     * @return array
     */
    public function getCompleteAction()
    {
        return [
            $this->getHandler(),
            $this->getAction()
        ];
    }

    /**
     * Get the route handler.
     * 
     * @return mixed|\Closure|string
     */
    public function getHandler()
    {
        return $this->handler;
    }

    /**
     * Get the request route.
     * 
     * @return \Wep\Router\Http\RouterHttpRequest
     */
    public function request(): RouterHttpRequest
    {
        // fora do dispatch (ex.: router()->get('nome')->request()) cria um request sem parâmetros
        return $this->request ??= new RouterHttpRequest($this->fullUrl, $this->originalRoute, []);
    }

    /**
     * Get the middleware route.
     * 
     * @return null|\Wep\Router\Middlewares\MiddlewareCollection
     */
    public function getMiddleware()
    {
        return $this->middleware;
    }

    /**
     * Get the original uri route.
     * 
     * @return string
     */
    public function getOriginalRoute(): String
    {
        return $this->originalRoute;
    }

    /**
     * Method responsible for defining the middlewares of the route.
     * 
     * @param string|array $middleware
     * 
     * @return \Wep\Router\RouteManager
     */
    public function middleware($middleware): RouteManager
    {
        if(is_a($this->middleware, MiddlewareCollection::class)) {
            $middleware = $this->middleware->storeMiddleware($middleware);
        }

        $this->middleware = new MiddlewareCollection($middleware);

        return $this;
    }
}
