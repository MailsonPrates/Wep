<?php

namespace Wep\Router;

use Wep\Router\RouteGroups;
use Wep\Router\Traits\RouterHelpers;
use Wep\Router\Traits\RouteManagement;
use Wep\Router\Exceptions\NotFoundException;
use Wep\Router\Exceptions\BadMethodCallException;
use Wep\Router\Exceptions\MethodNotAllowedException;
use Wep\Router\Middlewares\MiddlewareCollection;
use Wep\Router\Exceptions\BadMiddlewareExecuteException;

class RouteCollection
{
    use RouteManagement, RouterHelpers;

    /** @var string */
    protected $baseUrl;

    /** @var string */
    protected $currentUri;

    /** @var object */
    protected $currentGroup;

    /** @var object */
    protected $currentRoute;

    /** @var string */
    protected $requestMethod;

    /** @var string */
    protected $actionSeparator;

    /** @var array */
    protected $httpCodes = [
        "badRequest" => 400,
        "notAllowed" => 403,
        "notFound" => 404,
        "methodNotAllowed" => 405,
        "notImplemented" => 501,
        "redirect" => 302
    ];

    /** @var array */
    protected $routes = [
        "GET" => [],
        "POST" => [],
        "PUT" => [],
        "PATCH" => [],
        "DELETE" => [],
        "REDIRECT" => [],
        "OPTIONS" => []
    ];

    /** @var array */
    protected $formSpoofingMethods = ["PUT", "PATCH", "DELETE"];

    public function __construct(String $separator, String $baseUrl)
    {
        $this->baseUrl = $baseUrl;
        $this->actionSeparator = $separator;
        // filter_input lê a query string original; $_GET é o fallback (CLI/testes)
        $route = filter_input(INPUT_GET, "route", FILTER_DEFAULT) ?? ($_GET["route"] ?? null);
        $this->currentUri = is_string($route) && $route !== "" ? $route : "/";
    }

    /**
     * Method responsible for defining the 
     * group of current routes.
     * 
     * @param null|\Wep\Router\RouteGroups $group = null
     * 
     * @return void
     */
    public function defineGroup(?RouteGroups $group = null): void
    {
        $this->currentGroup = $group;
    }

    /**
     * Method responsible for returning the
     * current route.
     * 
     * @return null|\Wep\Router\RouteManager
     */
    public function getCurrentRoute()
    {
        return $this->currentRoute;
    }

    /**
     * Method responsible for adding a
     * route to an http method.
     * 
     * @param string $method
     * @param string $uri
     * @param array|string|\Closure $callback
     * 
     * @return \Wep\Router\RouteManager
     */
    public function addRoute(String $method, $uri, $callback)
    {
        $uri = $this->resolveRouterUri($uri);

        if (array_key_exists($method, $this->routes)) {
            return $this->routes[$method][$uri] = $this->addRouter($uri, $callback);
        }
    }

    /**
     * Method responsible for adding the same
     * route in more than one http method.
     * 
     * @param string $uri
     * @param array|string|\Closure $callback
     * @param null|array $methods
     * 
     * @return \Wep\Router\RouteManager
     */
    public function addMultipleHttpRoutes(String $uri, $callback, ?array $methods = null)
    {
        // "REDIRECT" não é método HTTP: antes entrava aqui e a rota virava um item do mapa de
        // redirects (fatal ao casar). O prefixo do grupo também não era aplicado.
        $methods = $methods ?: array_diff(array_keys($this->routes), ["REDIRECT"]);
        $methods = array_intersect(array_map("strtoupper", $methods), array_keys($this->routes));
        $uri = $this->resolveRouterUri($uri);

        foreach ($methods as $method) {
            $this->routes[$method][$uri] = $this->addRouter($uri, $callback);
        }
    }

    /**
     * Method responsible for adding a redirect route.
     * 
     * @param string $uri
     * @param string $redirect
     * @param int $httpCode
     * 
     * @return void
     */
    public function addRedirectRoute(String $uri, String $redirect, Int $httpCode): void
    {
        $uri = $this->resolveRouterUri($uri);

        $this->routes["REDIRECT"][$uri] = $this->redirectRouterData($redirect, $httpCode);
    }

    /**
     * Method responsible for handling method
     * calls that do not exist in the class.
     * 
     * @param string $method
     * @param array $arguments
     * 
     * @return void
     */
    public function __call($method, $arguments)
    {
        $this->throwException(
            "badRequest",
            BadMethodCallException::class,
            "Method [%s::%s] doesn't exist.",
            static::class,
            $method
        );
    }

    /**
     * Method responsible for returning a route
     * by the name attribute.
     * 
     * @param string $routeName
     * @param null|string $httpMethod = null
     * 
     * @return \Wep\Router\RouteManager|null
     */
    public function getByName(String $routeName, $httpMethod = null): ?RouteManager
    {
        $routes = $this->routes;
        $httpMethod = !$httpMethod ?: strtoupper($httpMethod);

        unset($routes["REDIRECT"]);

        if ($httpMethod && isset($this->routes[$httpMethod])) {
            $routes = $this->routes[$httpMethod];
        }

        if (!is_array($routes)) return null;

        $soughtRoute = null;

        foreach ($routes as $verb) {
            if (!$this->instanceOf($verb, RouteManager::class)) {
                foreach ($verb as $route) {
                    if ($route->getName() === $routeName) {
                        $soughtRoute = $route;
                        break;
                    }
                }
            } else {
                if ($verb->getName() === $routeName) {
                    $soughtRoute = $verb;
                    break;
                }
            }
        }

        return $soughtRoute;
    }

    /**
     * Method responsible for verifying if the
     * object is an instance of class.
     * 
     * @param mixed $object
     * 
     * @return bool
     */
    protected function instanceOf($object, $class)
    {
        return is_a($object, $class);
    }

    /**
     * Method responsible for redirecting to an
     * existing route or a uri.
     * 
     * @param object|array $route
     * @param bool $permanent = false
     */
    protected function redirectRoute(array $routes, $permanent = false)
    {
        $redirectRoute = $this->baseUrl;

        [$routeObject, $route] = $routes;

        if ($this->instanceOf($routeObject, RouteManager::class)) {
            // antes: rtrim(regex, '(\/)?'), que removia caracteres soltos do fim da rota
            $redirectRoute .= $routeObject->getOriginalRoute();
        } else {
            $redirectRoute .= $this->resolveRouterUri($route["redirect"]);
        }

        header("Location: {$redirectRoute}", true, $permanent ? 301 : $route["httpCode"]);
        exit();
    }

    /**
     * Method responsible for formSpoofing the
     * HTTP verbs coming from the form.
     * 
     * @return null|void
     */
    protected function resolveRequestMethod()
    {
        $method = strtoupper($_SERVER["REQUEST_METHOD"] ?? "GET");
        $spoofed = is_string($_POST["_method"] ?? null) ? strtoupper($_POST["_method"]) : null;

        if ($method === "POST" && in_array($spoofed, $this->formSpoofingMethods, true)) {
            $this->requestMethod = $spoofed;
            return null;
        }

        $this->requestMethod = $method;
    }

    /**
     * Method responsible for listening to browser calls
     * and returning the corresponding route.
     *
     * Precedência: rota estática exata (lookup direto) e, se não houver, a primeira rota
     * dinâmica que casar, na ordem de registro. (Antes vencia a última que casasse.)
     *
     * @return void
     */
    public function run(): void
    {
        $this->currentRoute = null;

        if (array_key_exists($currentRoute = $this->resolveRouterUri($this->currentUri), $this->routes["REDIRECT"])) {
            $route = $this->routes["REDIRECT"][$currentRoute];
            $redirectRoute = $this->getByName($route["redirect"]);

            $this->redirectRoute(
                [$redirectRoute, $route],
                $route["permanent"]
            );
        }

        $this->resolveRequestMethod();

        [$this->currentRoute, $params] = $this->findRoute($this->currentUri, $this->requestMethod);

        if ($this->currentRoute) {
            $this->currentRoute->setRequest($this->newRequest($this->currentRoute, $params));
        }

        $this->dispatchRoute();
    }

    /**
     * Encontra a rota para a URI e o método.
     *
     * @return array{0: RouteManager|null, 1: array<string, string>} rota e parâmetros
     */
    public function findRoute(String $uri, String $method): array
    {
        $method = strtoupper($method);
        $routes = $this->routes[$method === "HEAD" ? "GET" : $method] ?? [];

        $static = $routes[$this->fixRouterUri($uri)] ?? null;

        if ($static instanceof RouteManager && $static->isStatic()) {
            return [$static, []];
        }

        foreach ($routes as $route) {
            if ($route->isStatic()) continue;

            $params = $route->matches($uri);

            if ($params !== null) {
                return [$route, $params];
            }
        }

        return [null, []];
    }

    /**
     * O único request do ciclo (antes: um por rota registrada).
     */
    protected function newRequest(RouteManager $route, array $params): Http\RouterHttpRequest
    {
        $request = new Http\RouterHttpRequest($this->baseUrl, $route->getOriginalRoute(), $params);
        Http\RouterHttpRequest::setCurrent($request);

        return $request;
    }

    /**
     * Returns the URI based on group prefix.
     * 
     * @param string $uri
     * 
     * @return string
     */
    protected function resolveRouterUri(String $uri): String
    {
        $uri = $this->fixRouterUri($uri);

        if ($this->instanceof($this->currentGroup, RouteGroups::class) && $this->currentGroup->prefix) {
            $prefix = $this->fixRouterUri($this->currentGroup->prefix);

            return $prefix . $uri;
        }

        return $uri;
    }

    /**
     * Method responsible for performing
     * route actions.
     * 
     * @return mixed retorno da action (antes ?Closure: qualquer action que retornasse um valor
     *               — string, array, int — quebrava com TypeError)
     */
    protected function dispatchRoute(): mixed
    {
        if (!$route = $this->currentRoute) {
            if($fallbackRoute = $this->getByName('fallback')) {
                $fallbackRoute->setRequest($this->newRequest($fallbackRoute, []));
                return $this->executeRoute($fallbackRoute);
            }
            
            $this->setHttpCode($this->httpCodes["notFound"]);

            $this->throwException(
                "notFound",
                NotFoundException::class,
                "Route [%s] with method [%s] not found.",
                $_SERVER["REQUEST_URI"],
                $this->requestMethod
            );
        }

        $this->executeMiddlewares($route);

        [$controller, $method] = $route->getCompleteAction();

        if ($this->instanceOf($method, \Closure::class)) {
            $this->setHttpCode();
            return call_user_func($route->getAction(), ...$route->closureReturn());
        }

        $obController = $this->resolveRouteController($controller);

        if (!method_exists($obController, $method)) {
            $this->setHttpCode($this->httpCodes["methodNotAllowed"]);

            $this->throwException(
                "methodNotAllowed",
                MethodNotAllowedException::class,
                "Method [%s::%s] doesn't exist.",
                $controller,
                $method
            );
        }

        $obController->{$method}(...$route->closureReturn());

        return null;
    }

    /**
     * Responsible for execute the route
     * 
     * @param RouteManager $route
     * 
     * @return mixed|false
     */
    protected function executeRoute(RouteManager $route)
    {
        return call_user_func($route->getAction(), ...$route->closureReturn());
    }

    /**
     * Method responsible for checking if the controller
     * class exists and returns an instance of it.
     * 
     * @param string $controller
     */
    protected function resolveRouteController(String $controller)
    {
        if (!class_exists($controller)) {
            $this->setHttpCode($this->httpCodes["badRequest"]);

            $this->throwException(
                "badRequest",
                BadMethodCallException::class,
                "Class [%s] doesn't exist.",
                $controller
            );
        }

        return new $controller;
    }

    /**
     * Method responsible for executing
     * the middlewares of the current route.
     * 
     * @return mixed|false|void
     */
    protected function executeMiddlewares(RouteManager $route)
    {
        if ($this->instanceOf($route->getMiddleware(), MiddlewareCollection::class)) {

            $route->getMiddleware()->setRequest($route->request());

            if (!$route->getMiddleware()->execute()) {
                $this->setHttpCode($this->httpCodes["notFound"]);

                $this->throwException(
                    "notFound",
                    BadMiddlewareExecuteException::class,
                    "Some middleware has not approved your request."
                );
            }
        }
    }

    /**
     * Method responsible for returning an
     * http method by slug.
     * 
     * @param string $slug
     * 
     * @return null|int
     */
    protected function getHttpCode(String $slug)
    {
        if (!isset($this->httpCodes[$slug])) return null;

        return $this->httpCodes[$slug];
    }

    /**
     * Method responsible for rendering
     * the http method on the page.
     * 
     * @param int $code = 200
     * 
     * @return void
     */
    protected function setHttpCode(Int $code = 200)
    {
        http_response_code($code);
    }

    /**
     * Method responsible for returning all routes
     * from the http method passed in the parameter.
     * 
     * @param string $method
     * 
     * @return null|array
     */
    public function getRoutesOf(String $method)
    {
        $method = strtoupper($method);

        if (!isset($this->routes[$method])) return null;

        return $this->routes[$method];
    }
}
