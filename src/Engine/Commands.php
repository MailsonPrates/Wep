<?php

namespace App\Core\Engine;

use App\Core\Command\Console;
use App\Core\Engine\Update;
use App\Core\Core;

class Commands
{
    private static $actions = [
        "update" => "Update"
    ];

    public static function run($argv=[], $config=[])
    {
        $action = $argv[1] ?? null;

        if ( !$action ) {
            Console::log("error", "Ação não informada");
            return;
        }

        $action = strtolower($action);

        Core::start($config, true);

        $current_action = self::$actions[$action] ?? null;

        if ( is_null($current_action) ) return Console::log("error", "Comando '$action' não reconhecido");

        if ( $action == "update" ){
            $result = Update::handle();
        }

        if ( !$result ) return Console::log("error", "Houve um erro desconhecido");

        $has_erros = $result->error;

        Console::log($has_erros ? 'error' : 'success', $result->message);

        if ( $has_erros && isset($result->data) ){
            foreach( $result->data as $item ){
                Console::log($item->error ? 'error' : 'success', $item->message);
            }
        }
    }
}