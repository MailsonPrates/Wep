<?php

namespace Wep\Router\Traits;

use Wep\Router\RouteGroups;
use Wep\Router\Middlewares\MiddlewareRoute;

trait RouteUtils
{
    /** @var object */
    public static $collection;

    /**
     * Method responsible for creating a 
     * new instance of RouteGroups.
     * 
     * @return \Wep\Router\RouteGroups
     */
    private static function newRouteGroup()
    {
        $group = (new RouteGroups(self::$collection));

        self::$collection->defineGroup($group);

        return $group;
    }

    /**
     * Method responsible for setting all global
     * middlewares and identifying them with alias.
     * 
     * @param Array $middlewares
     * 
     * @return void
     */
    public static function globalMiddlewares(Array $middlewares): void
    {
        MiddlewareRoute::setMiddlewares($middlewares);
    }

    /**
     * Method responsible for setting the default
     * namespace in a route group.
     * 
     * @param string $namespace
     * 
     * @return \Wep\Router\RouteGroups
     */
    public static function namespace(String $namespace)
    {
        return self::newRouteGroup()->namespace($namespace);
    }

    /**
     * Method responsible for setting the default
     * prefix in a route group.
     * 
     * @param string $prefix
     * 
     * @return \Wep\Router\RouteGroups
     */
    public static function prefix(String $prefix)
    {
        return self::newRouteGroup()->prefix($prefix);
    }

    /**
     * Method responsible for setting the default
     * middleware in a route group.
     * 
     * @param string $middleware
     * 
     * @return \Wep\Router\RouteGroups
     */
    public static function middleware($middleware)
    {
        return self::newRouteGroup()->middlewares($middleware);
    }

    /**
     * Method responsible for setting the default
     * name in a route group.
     * 
     * @param string $name
     * 
     * @return \Wep\Router\RouteGroups
     */
    public static function name(String $name)
    {
        return self::newRouteGroup()->name($name);
    }
}