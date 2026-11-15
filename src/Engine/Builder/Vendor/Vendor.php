<?php

namespace Wep\Engine\Builder\Vendor;

use Wep\Str;
use Wep\Engine\Builder\RoutesMap;
use Wep\Engine\Module;

/**
 * Monta os dados dos módulos vendor (integrações com APIs externas) a partir da config.
 *
 * Uso no servidor (sempre disponível):  SendPulse::resource('smtp')->send([...])
 *
 * Rotas HTTP para o front (VendorApi no React) só existem com 'expose' => true no module.php.
 * Antes toda integração virava uma rota pública: qualquer visitante, sem login, enviava o corpo
 * que quisesse para a API externa com as credenciais da aplicação.
 *
 *   'expose' => true,
 *   'routes' => ['use_middlewares' => ['isLogged', 'checkPermission']],
 */
class Vendor
{
    public static $modules_config = [];

    /** @var array<string, array>|null mapa de endpoints carregado (por processo) */
    private static ?array $map = null;

    public static function buildRoutes()
    {
        self::setConfigs();

        $routes = [];

        foreach( self::$modules_config as $module ){

            if ( ($module['expose'] ?? false) !== true ) continue;

            $module_routes = $module['routes'];

            if ( empty($module_routes['use_middlewares']) ){
                RoutesMap::warn("Módulo vendor '{$module['module_name']}' exposto ao front sem middlewares: qualquer visitante pode usar a integração (adicione 'routes' => ['use_middlewares' => [...]])");
            }

            $module_routes['groups'] = array_merge(
                $module_routes['groups'] ?? [],
                Endpoint::convertToRoute($module['endpoints'] ?? [])
            );

            $routes[] = $module_routes;
        }

        return $routes;
    }

    public static function buildEndpointsMap()
    {
        self::setConfigs();

        $endpoints_map = [];

        foreach( self::$modules_config as $module ){
            $endpoints_map[$module['module_name']] = [
                'headers' => $module['headers'] ?? [],
                'resources' => Endpoint::buildResources($module),
                'hooks' => $module['hooks'] ?? []
            ];
        }

        return $endpoints_map;
    }

    /**
     * Mapa de endpoints (storage/builds/vendor-endpoints.build.php), carregado uma vez por processo.
     *
     * @return array dados do vendor, [] se ele não existir, ou o mapa inteiro sem $vendor
     */
    public static function getMap($vendor='')
    {
        if ( self::$map === null ){
            $map = defined('VENDOR_ENDPOINTS_MAP_FILENAME') && is_file(VENDOR_ENDPOINTS_MAP_FILENAME)
                ? include VENDOR_ENDPOINTS_MAP_FILENAME
                : [];

            self::$map = is_array($map) ? $map : [];
        }

        return $vendor ? (self::$map[$vendor] ?? []) : self::$map;
    }

    /**
     * Descarta o mapa em memória (depois de um novo build no mesmo processo).
     */
    public static function flush(): void
    {
        self::$map = null;
    }

    private static function setConfigs()
    {
        // recarrega a cada build (antes o cache estático nunca era limpo no mesmo processo)
        self::$modules_config = [];

        foreach( Module::getConfigs('vendor') as $module_config ){

            if ( ($module_config['active'] ?? true) === false ) continue;

            $module_config['routes'] = $module_config['routes'] ?? [];
            $module_config['module_name'] = $module_config['module_name'] ?? "";

            $module_config['routes'] = array_merge([
                'path' => '/' . Str::camelToKebabCase($module_config['module_name']),
                'namespace' => $module_config['namespace'],
                'module_name' => $module_config['module_name'],
                'groups' => []
            ], $module_config['routes']);

            self::$modules_config[] = $module_config;
        }
    }
}
