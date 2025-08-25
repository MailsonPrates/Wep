<?php

namespace App\Core\Engine;

use App\Core\Core;
use App\Core\Engine\Builder\Warning;
use App\Core\Engine\Builder\RoutesMap;
use App\Core\Engine\Builder\Vendor\Vendor;
use App\Core\Obj;
use App\Core\Str;

/**
 * Classe responsável por atualizar recursos do App
 */
class Update
{
    public static function handle()
    {
        $response = Obj::set([
           'error' => false,
           'message' => "App atualizado \\0/"
        ]);

        $routes_update = self::routes();

        if ( $routes_update->error ) return $routes_update;

        $configs_update = self::configs();

        if ( $configs_update->error ) return $configs_update;

        $endpoints_update = self::endpoints();

        if ( $endpoints_update->error ) return $endpoints_update;

        $custom_sets_update = self::customSets();

        if ( $custom_sets_update->error ) return $custom_sets_update;
        
        return $response;
    }

    private static function routes()
    {
        $response = Obj::set([
            'error' => false,
            'message' => 'Rotas atualizadas',
            'data' => []
        ]);

        $routes_build = RoutesMap::build();

        $build_resources = [
            'raw' => ROUTE_MAPS_FILENAME,
            'frontend' => ROUTE_MAPS_BUILD_FILENAME_FRONT,
            'backend' => ROUTE_MAPS_BUILD_FILENAME_BACK
        ];

        foreach( $build_resources as $type => $filename ){

            $build_content = $routes_build->{$type} ?? null;

            if ( !$build_content || !file_put_contents($filename, $build_content) ){
                $response->data[] = "Erro ao atualizar o mapa de rotas: $type";
            }
        }

        if ( !empty($routes_build->module_apis) ){
            $update_module_apis = self::moduleApis($routes_build->module_apis);

            if ( $update_module_apis->error ){
                $response->data[] = $update_module_apis->data ?? [];
            }

        }

        $has_error = !empty($response->data);

        if ( $has_error ){
            $response->error = true;
            $response->message = "Houve erro ao atualizar as rotas";
        }

        return $response;
    }

    /**
     * Atualiza os arquivos de api dos módulos
     * localizados em storage/builds/apis/
     */
    private static function moduleApis($moduleApis="")
    {
        $response = Obj::set([
            "error" => false
        ]);

        $modules = $moduleApis ?? "";
        file_put_contents(DIR_BUILD_MODULE_APIS . "/index.js", $modules);

        return $response;
      
        $module_api_file_to_delete = glob(DIR_BUILD_MODULE_APIS."/*");  
   
        foreach($module_api_file_to_delete as $file) { 
            if ( is_file($file ) ) unlink($file);  
        }

        $module_apis_to_create = $moduleApis ?? [];

        foreach( $module_apis_to_create as $module ){

            $filename = DIR_BUILD_MODULE_APIS . '/' . $module['filename'];
            $content = $module['content'] ?? '';

            $create_module_api_file = file_put_contents($filename, $content);
            
            if ( !$create_module_api_file ){
                $response->error = true;
                $response->data = 'Erro ao criar o arquivo '.$filename;
                break;
            }
        }

        return $response;
    }

    /**
     * Atualiza o arquivo de configuração do app
     * localizados em storage/builds/configs.build.json
     */
    private static function configs()
    {
        $response = Obj::set([
            'error' => false,
            'message' => 'Configs atualizadas',
            'data' => []
        ]);
        
        $app_configs = [
            'name' => Core::config('name'),
            'title' => Core::config('title|name')
        ];

        $app_data = Core::$data ?? [];

        $env_configs = [
            'app_url' => Core::config('env.app_url'),
            'env' => Core::config('env.env')
        ];

        $configs = array_merge($app_configs, $env_configs, $app_data);
        $configs_json = json_encode($configs);

        $save = file_put_contents(CONFIGS_BUILD_FILENAME_FRONT, $configs_json);

        if ( !$configs_json || !$save ){
            $response->error = true;
            $response->data[] = "Erro ao atualizar o arquivo de configs";
        }

        return $response;
    }

    /**
     * Atualiza o arquivo de configuração do app
     * localizados em storage/builds/vendor-endpoints.build.php
     */    
    private static function endpoints()
    {
        $response = Obj::set([
            'error' => false,
            'message' => 'Endpoints dos módulos vendor atualizados',
            'data' => []
        ]);

        // VENDOR_ENDPOINTS_MAP
        $endpoint_maps = Vendor::buildEndpointsMap();

        $content = ['<?php'];
        $content[] = Warning::get();
        $content[] = "return";
        $content[] = var_export($endpoint_maps, true) . ";";
        $content = join("\r", $content);

        $save = file_put_contents(VENDOR_ENDPOINTS_MAP_FILENAME, $content);

        if ( !$save ){
            $response->error = true;
            $response->data[] = "Erro ao atualizar o arquivo de endpoint dos módulos vendor";
        }

        return $response;
    }

    /**
     * Atualiza o arquivo de configuração customizadas do app e módulo
     * localizados em storage/builds/constants.build.php
     */   
    private static function customSets()
    {
        $response = Obj::set([
            'error' => false,
            'message' => 'Arquivo de constantes criado',
            'data' => []
        ]);

        /**
         * Constants
         */
        $app_constants = Core::config('constants') ?? [];
        $modules_config = Module::getConfigs();
        $module_constants = [];

        foreach( $modules_config as $config ){
            $consts = $config['constants'] ?? [];
            $module_constants = array_merge($module_constants, $consts);
        }

        $constants_to_define = array_merge($app_constants, $module_constants);

        if ( file_exists(APP_CONSTANTS_FILENAME) ) unlink(APP_CONSTANTS_FILENAME);

        if ( empty($constants_to_define) ) return $response;

        $constants = [];

        foreach( $constants_to_define as $key => $value ){
            $key = strtoupper($key);

            if ( is_string($value) ){
                $value = self::parseConstant($value);
            }

            if (  is_array($value) || is_object($value)){
                $value = var_export($value, true);
            }

            $constants[] = "define('$key', $value);";
        }

        $content = [
            '<?php',
            Warning::get(),
            join("\r", $constants)
        ];

        $save = file_put_contents(APP_CONSTANTS_FILENAME, $content);

        return $response;
    }

    private static function parseConstant($text)
    {
        $has_placeholder = str_contains($text, '{');

        if ( !$has_placeholder ) return "'$text'";

        $text_trim = str_replace(['{ ', ' }'], ['{', '}'], $text);
        $parts = explode(' ', $text_trim);

        $words_parsed = [];

        foreach( $parts as $word ){
            $is_placeholder = str_contains($word, '{');
            $parsed = $word;

            if ( $is_placeholder ){
                $key = Str::between($word, '{', '}');
                $placeholder = '{'.$key.'}';
                $key = strtoupper($key);

                // '{MY_CUSTOM_VAR_A}/bbbbb'
                //  MY_CUSTOM_VAR_A . '/bbbbb'
                $value = "$key .'";

                $parsed = str_replace($placeholder, $value, $word) . "'";
            }
                
            $words_parsed[] = $parsed;
        }

        return join(' ', $words_parsed);
    }
}