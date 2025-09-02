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
     * Module::getConfigs('vendor');
     * Module::getConfigs('regular');
     * 
     * @todo
     * Module::getConfigs('Pedido:routes|key');
     * Module::getConfigs(['Pedido', 'Outro]);
     */
    public static function getConfigs($props=null)
    {
        $module_name = $props ?? '';
        $has_filters = str_contains($module_name, ':');
        $is_only_filters = $has_filters && $module_name[0] == ':';
        $is_all = !$module_name || ( is_string($props) && in_array($module_name, ['regular', 'vendor']));

        $filters = [];

        if ( $has_filters ){
            $parts = explode(":", $module_name) ?? [];
            $filters = explode("|", $parts[1] ?? '') ?? [];
            $module_name = $parts[0] ?: '';
        }
        
        // all
        if ( $is_all || $is_only_filters ){
            $config_list = self::getConfigList(($is_only_filters ? "" : ($props ?: "")), $filters);
            //echo $props . PHP_EOL;
            //echo json_encode($config_list) . PHP_EOL;
            //echo "--------".PHP_EOL;
            return $config_list;
        }

        $is_vendor = str_contains($module_name, '.');

        //echo $module_name . PHP_EOL; 

        if ( $is_vendor ){
            $name_parts = explode('.', $module_name); 
            //echo $module_name . PHP_EOL;
            $module_name = 'Vendor/'.ucfirst($name_parts[1]);

        } else {
            $module_name = ucfirst($module_name);
        }

        $config = self::getConfigList("/$module_name/config/module.php", $filters);
        return $config[0] ?? [];
    }

    private static function getConfigList($props="", $filters=[])
    {
        $configs = [];

        $filenames = $props;
        $is_vendor_filter = $props === "vendor";
        $is_regular_filter = $props === "regular";
        $is_only_filter = !$props;

        if ( $is_vendor_filter || $is_regular_filter || $is_only_filter ){

            $files = [];
            $dir = DIR_MODULES . ($is_vendor_filter ? '/Vendor' : '');

            //echo "dir: ". $dir . PHP_EOL;

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                $pathname = $file->getPathname();

                //echo "PATH: ". $pathname . PHP_EOL;

                if ( basename($file) === 'module.php' ) {

                    if ( $is_regular_filter && str_contains($pathname, 'Vendor') ) continue;
                    if ( $is_vendor_filter && !str_contains($pathname, 'Vendor') ) continue;

                    $files[] = $pathname;
                }
            }

            $filenames = $files;

           // echo json_encode($filenames) . PHP_EOL;

        } else {
            $filenames = glob(DIR_MODULES . $props);
        }

        // echo json_encode($filenames) . PHP_EOL;

        foreach($filenames as $filename){

            $is_vendor = str_contains($filename, 'Vendor');

            $filename_replaced = str_replace("/", "\\", $filename);
            $parts = explode("Modules\\".($is_vendor ? "Vendor\\" : ""), $filename_replaced);
            $item = $parts[1] ?? "";
            $module_path = explode("\\config", $item)[0] ?? "";
            $module_name_parts = explode("\\", $module_path);
            $module_name = end($module_name_parts);
            $module_namespace = APP_MODULES_NAMESPACE . ($is_vendor ? 'Vendor\\' : '') . $module_path;

            $config = include($filename) ?? [];

            //echo !$is_vendor_filter ? '' : $filename . PHP_EOL;
            //$break = PHP_EOL;
            //echo "is_vendor: " . $is_vendor . $break;
            //echo "filename: " . $filename . $break;
            //echo "fullnamespace: " . APP_MODULES_NAMESPACE . $module_name . $break;
            //echo "parts: " . json_encode($parts) . $break;
            //echo "item: " . json_encode($item) . $break;
            //echo "module_name: " . $module_name . $break;
            //echo "namespace: " . $module_namespace . $break;
            //echo "-----------" . $break;

            $config['namespace'] = $config['namespace'] ?? $module_namespace;
            $config['module_name'] = $module_name;
            $config['routes'] = $config['routes'] ?? [];
            $config['is_vendor'] = $is_vendor;

            if ( !empty($config['routes']) ){
                $config['routes']['namespace'] = $config['routes']['namespace'] ?? $module_namespace;
                $config['routes']['module_name'] = $module_name;
                $config['routes']['groups'] = $config['routes']['groups'] ?? [];
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

        //echo json_encode($configs) . PHP_EOL;
        //echo "-------" . PHP_EOL;

        return $configs;
    }
}