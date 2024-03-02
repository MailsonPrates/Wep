<?php

namespace App\Core\Router;

use App\Core\Router\RouteMapData;

class RouteMap
{
    /**
     * Retorda dados da rota baseado no path ou método http
     * @param string $path
     * 
     * @return RouteMapData
     */
    public static function get($path="")
    {
        $routes_map_raw = self::getRaw();
        $route_data = $routes_map_raw[$path] ?? $routes_map_raw[$path . '/'] ?? [];

        return new RouteMapData($route_data);
    }

    public static function getRaw(): array
    {
        return include(ROUTE_MAPS_FILENAME) ?? [];
    }

}