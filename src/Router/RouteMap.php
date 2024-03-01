<?php

namespace App\Core\Router;

use App\Core\Router\RouteData;

class RouteMap
{
    /**
     * Retorda dados da rota baseado no path ou método http
     * @param string $path
     * 
     * @return RouteData
     */
    public static function get($path="")
    {
        $routes_map_raw = self::getRaw();
        $route_data = $routes_map_raw[$path] ?? [];

        return new RouteData($route_data);
    }

    public static function getRaw(): array
    {
        return include_once(ROUTE_MAPS_FILENAME) ?? [];
    }

}