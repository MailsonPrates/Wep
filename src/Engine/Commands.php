<?php

namespace App\Core\Engine;

use App\Core\Command\Console;
use App\Core\Core;

class Commands
{
    private static $actions = [
        'update' => 'App\Core\Engine\Update@handle',
        'create module' => 'App\Core\Engine\Builder\Snippets\Module@create'
    ];

    public static function run($argv=[], $config=[])
    {
        $action = $argv[1] ?? null;
        $sub_action = $argv[2] ?? null;
        $action_params = $argv[3] ?? null;
        $action_options = $argv[4] ?? '';

        if ( !$action ) {
            Console::log("error", "Ação não informada");
            return;
        }

        $action = strtolower($action);
        
        if ( $sub_action ){
            $action = "$action $sub_action";
        }

        Core::start($config, true);

        $current_action = self::$actions[$action] ?? null;

        if ( is_null($current_action) ) return Console::log("error", "Comando '$action' não reconhecido");

        [$action_namespace, $action_method] = explode('@', $current_action);    

       try {

        /**
         * @todo criar classes especificas para rodar cada comando. 
         * Os comandos executam services do core
         */
            $options = explode(' ', $action_options) ?? [];
            $options = array_filter($options);
            $result = call_user_func($action_namespace . '::'.$action_method, [
                'params' => $action_params,
                'options' => $options
            ]);

            if ( !$result ) return Console::log("error", "Houve um erro desconhecido");

            $has_erros = $result->error;

            Console::log($has_erros ? 'error' : 'success', $result->message);

            if ( $has_erros && isset($result->data) ){
                foreach( $result->data as $item ){
                    Console::log($item->error ? 'error' : 'success', $item->message);
                }
            }

       } catch (\Throwable $th) {
            Console::log('error', $th->getMessage());
            Console::log('error', json_encode($th->getTraceAsString()));
       }

        
    }
}