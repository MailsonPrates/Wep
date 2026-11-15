<?php

namespace Wep\Engine;

use Wep\Command\Console;
use Wep\Core;

/**
 * Comandos do `php app`:
 *
 *   php app update [--strict]
 *   php app install [--force]
 *   php app create module <Nome> [-view]
 *
 * Opções (--x) podem vir em qualquer posição. --debug mostra o stack trace em caso de erro.
 */
class Commands
{
    private static $actions = [
        'update' => 'Wep\Engine\Update@handle',
        'install' => 'Wep\Engine\Install@handle',
        'create module' => 'Wep\Engine\Builder\Snippets\Module@create'
    ];

    public static function run($argv=[], $config=[])
    {
        [$positional, $options] = self::parse(array_slice((array) $argv, 1));

        if ( !$positional ){
            Console::log("error", "Ação não informada. Comandos: " . implode(', ', array_keys(self::$actions)));
            return;
        }

        // comando de duas palavras ("create module") só quando existe; senão a 2ª palavra é parâmetro
        $two_words = strtolower($positional[0] . ' ' . ($positional[1] ?? ''));
        $is_two_words = isset($positional[1]) && isset(self::$actions[$two_words]);

        $action = $is_two_words ? $two_words : strtolower($positional[0]);
        $params = $positional[$is_two_words ? 2 : 1] ?? null;

        $current_action = self::$actions[$action] ?? null;

        if ( is_null($current_action) ) return Console::log("error", "Comando '$action' não reconhecido. Comandos: " . implode(', ', array_keys(self::$actions)));

        Core::start($config, true);

        [$action_namespace, $action_method] = explode('@', $current_action);

        try {

            $result = call_user_func($action_namespace . '::' . $action_method, [
                'params' => $params,
                'options' => $options
            ]);

            if ( !$result ) return Console::log("error", "Houve um erro desconhecido");

            $has_errors = !empty($result->error);

            Console::log($has_errors ? 'error' : 'success', $result->message ?? '');

            foreach( $result->warnings ?? [] as $warning ){
                Console::log('alert', $warning);
            }

            if ( $has_errors ){
                foreach( (array) ($result->data ?? []) as $item ){
                    is_object($item)
                        ? Console::log(!empty($item->error) ? 'error' : 'success', $item->message ?? '')
                        : Console::log('error', (string) $item);
                }
            }

        } catch (\Throwable $th) {
            Console::log('error', $th->getMessage());

            if ( in_array('--debug', $options, true) ){
                echo $th->getTraceAsString() . PHP_EOL;
            }
        }
    }

    /**
     * Separa argumentos posicionais e opções (que começam com "-").
     *
     * @return array{0: string[], 1: string[]}
     */
    private static function parse(array $arguments): array
    {
        $positional = [];
        $options = [];

        foreach( $arguments as $argument ){
            $argument = (string) $argument;

            if ( $argument === '' ) continue;

            if ( $argument[0] === '-' ){
                $options[] = $argument;
            } else {
                $positional[] = $argument;
            }
        }

        return [$positional, $options];
    }
}
