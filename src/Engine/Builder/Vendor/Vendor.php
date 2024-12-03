<?php

namespace App\Core\Engine\Builder\Vendor;

use App\Core\Str;
use App\Core\Engine\Builder\Vendor\Endpoint;

/**
 * Classe responsável por montar dados dos 
 * módulos vendor através do config
 */
class Vendor 
{
    public static $modules_config = [];

    public static function buildRoutes()
    {
        if ( empty(self::$modules_config) ) self::setConfigs();

        $module_configs = self::$modules_config;
        $routes = [];

        foreach( $module_configs as $module ){

            $module_routes = $module['routes'];
            $module_routes_groups = $module_routes['groups'] ?? [];

            $module_endpoints = $module['endpoints'] ?? [];
            $module_endpoints_routes = Endpoint::convertToRoute($module_endpoints);

            $module_routes['groups'] = array_merge($module_routes_groups, $module_endpoints_routes);

            $routes[] = $module_routes;
        }

        return $routes;
    }

    public static function buildEndpointsMap()
    {
        if ( empty(self::$modules_config) ) self::setConfigs();

        $module_configs = self::$modules_config;
        $endpoints_map = [];

        foreach( $module_configs as $module ){

            $module_name = $module['module_name'];

            $data = [
                'headers' => $module['headers'] ?? [],
                'resources' => Endpoint::buildResources($module),
                'hooks' => $module['hooks'] ?? []
            ];

            $endpoints_map[$module_name] = $data;
        }

        return $endpoints_map;
    }

    public static function getMap($vendor='')
    {
        $endpoints_map = include(VENDOR_ENDPOINTS_MAP_FILENAME) ?? [];

        return $vendor ? $endpoints_map[$vendor] : $endpoints_map;
    }

    private static function setConfigs()
    {
        /**
         * @todo usar Module::getConfigs()
         */


        $modules_config_filenames = glob(DIR_MODULES . "/Vendor/*/config/module.php");

        foreach($modules_config_filenames as $filename){
            
            $parts = explode("Modules/Vendor/", $filename);
            $item = $parts[1] ?? "";
            $module_name = explode("/", $item)[0] ?? "";
            $module_namespace = APP_MODULES_NAMESPACE . 'Vendor\\' . $module_name;

            $module_config = include_once($filename) ?? [];

            $active = $module_config['active'] ?? true;

            if ( $active === false ) continue;

            $module_config['routes'] = $module_config['routes'] ?? [];
            $module_config['module_name'] = $module_name;

            $module_config['routes'] = array_merge([
                'path' => '/' . Str::camelToKebabCase($module_name),
                'namespace' => $module_namespace,
                'module_name' => $module_name,
                'groups' => []
            ], $module_config['routes']);

            self::$modules_config[] = $module_config;
        }
    }

}