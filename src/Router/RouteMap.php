<?php

namespace Wep\Router;

use Wep\Router\RouteMapData;

class RouteMap
{
    /** @var array<string, mixed>|null mapa carregado uma vez por processo */
    private static ?array $raw = null;

    /**
     * Retorna dados da rota baseado no path
     * @param string $path
     *
     * @return RouteMapData
     */
    public static function get($path = "")
    {
        $routes_map_raw = self::getRaw();
        $route_data = $routes_map_raw[$path] ?? $routes_map_raw[$path . '/'] ?? [];

        return new RouteMapData($route_data);
    }

    /**
     * Mapa de rotas gerado pelo build (storage/builds/route-maps.php).
     * Vazio quando a aplicação não foi iniciada ou o build ainda não existe.
     */
    public static function getRaw(): array
    {
        if (self::$raw !== null) {
            return self::$raw;
        }

        if (!defined('ROUTE_MAPS_FILENAME') || !is_file(ROUTE_MAPS_FILENAME)) {
            return [];
        }

        $raw = include ROUTE_MAPS_FILENAME;

        return self::$raw = is_array($raw) ? $raw : [];
    }

    /**
     * Descarta o mapa em memória (ex.: depois de um novo build no mesmo processo).
     */
    public static function flush(): void
    {
        self::$raw = null;
    }
}
