<?php

namespace App\Core\Engine;

use App\Core\Core;
use App\Core\Engine\Builder\RoutesMap;
use App\Core\Obj;

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

        $has_error = !empty($response->data);

        if ( $has_error ){
            $response->error = true;
            $response->message = "Houve erro ao atualizar as rotas";
        }

        return $response;
    }

    private static function configs()
    {
        $response = Obj::set([
            'error' => false,
            'message' => 'Rotas atualizadas',
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

        if ( !$configs_json || !$save  ){
            $response->data[] = "Erro ao atualizar o arquivo de configs";
        }

        return $response;
    }
}