<?php

namespace App\Core\Engine;

class Module
{
    /**
     * @example
     * 
     * Module::getConfigs();
     * Module::getConfigs(':routes|key');
     * Module::getConfigs('Pedido');
     * Module::getConfigs('Vendor.Pedido');
     * 
     * @todo
     * Module::getConfigs('Pedido:routes|key');
     * Module::getConfigs(['Pedido', 'Outro]);
     * Module::getConfigs('vendor');
     * Module::getConfigs('regular');
     */
    public static function getConfigs($props=null)
    {
        $module_name = $props ?? '';
        $has_filters = str_contains($module_name, ':');
        $is_only_filters = $has_filters && $module_name[0] == ':';

        $filters = [];

        if ( $has_filters ){
            $parts = explode(":", $module_name) ?? [];
            $filters = explode("|", $parts[1] ?? '') ?? [];
            $module_name = $parts[0] ?: '';
        }

        // all
        if ( !$module_name || $is_only_filters ){

            $regular = self::getConfigList('/*/config/module.php', $filters);
            $vendor = self::getConfigList('/Vendor/*/config/module.php', $filters);

            return array_merge($regular, $vendor);
        }

        $is_vendor = str_contains($module_name, '.');

        if ( $is_vendor ){
            $name_parts = explode('.', $module_name); 
            $module_name = 'Vendor/'.ucfirst($name_parts[1]);

        } else {
            $module_name = ucfirst($module_name);
        }

        $config = self::getConfigList("/$module_name/config/module.php", $filters);
        return $config[0] ?? [];
    }

    private static function getConfigList($props=[], $filters=[])
    {
        $configs = [];

        $filenames = is_string($props) 
            ? glob(DIR_MODULES . $props)
            : $props;

        foreach($filenames as $filename){

            $is_vendor = str_contains($filename, 'Vendor');
            $parts = explode("Modules/".($is_vendor ? "Vendor/" : ""), $filename);
            $item = $parts[1] ?? "";
            $module_name = explode("/", $item)[0] ?? "";
            $module_namespace = APP_MODULES_NAMESPACE . ($is_vendor ? 'Vendor\\' : '') . $module_name;

            $config = include($filename) ?? [];

            $config['namespace'] = $config['namespace'] ?? $module_namespace;
            $config['module_name'] = $module_name;
            $config['routes'] = $config['routes'] ?? [];
            $config['is_vendor'] = $is_vendor;

            if ( !empty($config['routes']) ){
                $config['routes']['namespace'] =  $config['routes']['namespace'] ?? $module_namespace;
                $config['routes']['module_name'] =  $module_name;
                $config['routes']['groups'] =  $config['routes']['groups'] ?? [];
                $config['routes']['middlewares'] = $config['middlewares'] ?? [];
            }

            if ( !empty($filters) ){

                $filtered_values = [];

                foreach($filters as $key){

                    if ( !isset($config[$key]) ) continue;

                    $filtered_values[$key] = $config[$key];
                }

                if ( empty($filtered_values) ) continue;

                $configs[] = $filtered_values;
                continue;
            }            

            $configs[] = $config;
        }

        return $configs;
    }

}