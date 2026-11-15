<?php

namespace Wep\Engine\Builder;

use Wep\Cache;
use Wep\Core;
use Wep\Engine\Module;
use Wep\Foundation\ErrorHandler;

/**
 * Hooks do build (`php app update`): before_build, each_route e after_build.
 *
 * - Hooks do app (config/app.php) rodam antes dos hooks dos módulos, em todos os eventos.
 * - each_route: todos os hooks rodam em cadeia; cada um recebe a rota devolvida pelo anterior.
 *   (Antes só o primeiro rodava: um hook do app impedia o AddRoleToRoutes do módulo UserGroup e
 *   as rotas ficavam sem permissão.)
 * - Um hook que falha vira aviso e o build continua. Com --strict (setStrict) a falha aborta.
 */
class BuilderHooks
{
    private static bool $strict = false;

    /** @var string[] */
    private static array $warnings = [];

    /** @var array|null hooks resolvidos (em memória durante o build) */
    private static ?array $hooks = null;

    public static function beforeBuild()
    {
        self::run('before_build');
    }

    public static function eachRoute($route, $data)
    {
        foreach( self::getHooks()['each_route'] ?? [] as $hook ){

            $result = self::call($hook, 'each_route', [$route, $data]);

            // hook que não devolve rota (null/false) mantém a rota como estava
            if ( is_array($result) ) $route = $result;
        }

        return $route;
    }

    public static function afterBuild()
    {
        self::run('after_build');
    }

    public static function reset()
    {
        Cache::delete('regular_module_hooks');
        self::$warnings = [];
        self::$hooks = null;
    }

    public static function setStrict(bool $strict): void
    {
        self::$strict = $strict;
    }

    /**
     * Avisos acumulados no build (hooks inexistentes ou que falharam).
     *
     * @return string[]
     */
    public static function warnings(): array
    {
        return self::$warnings;
    }

    private static function run(string $event): void
    {
        foreach( self::getHooks()[$event] ?? [] as $hook ){
            self::call($hook, $event, []);
        }
    }

    /**
     * Executa um hook. Falhas viram aviso (ou exceção em modo estrito).
     */
    private static function call(array $hook, string $event, array $arguments)
    {
        [$class, $method] = self::resolve($hook);

        if ( !method_exists($class, $method) ){
            // antes era ignorado em silêncio (ex.: erro de digitação no nome do hook)
            return self::fail("Hook $event '{$hook['executor']}' não encontrado ($class::$method)");
        }

        try {
            return (new $class)->{$method}(...$arguments);
        } catch (\Throwable $e) {
            ErrorHandler::report($e);
            return self::fail("Hook $event '{$hook['executor']}' falhou: " . $e->getMessage(), $e);
        }
    }

    private static function fail(string $message, ?\Throwable $previous = null)
    {
        if ( self::$strict ){
            throw new \RuntimeException($message, 0, $previous);
        }

        self::$warnings[] = $message;

        return null;
    }

    /**
     * @return array{0: string, 1: string} [classe, método]
     */
    private static function resolve(array $hook): array
    {
        $executor = (string) $hook['executor'];

        if ( str_contains($executor, "\\") ){
            $parts = explode("@", $executor);
            return [ltrim($parts[0], "\\"), $parts[1] ?? 'handle'];
        }

        return [$hook['namespace'] . "\\Hooks\\" . $executor, 'handle'];
    }

    private static function getHooks()
    {
        if ( self::$hooks !== null ) return self::$hooks;

        $hooks = Cache::get('regular_module_hooks') ?: [];

        if ( empty($hooks) ){

            $app_hooks = self::getAppHooks();
            $module_hooks = self::getModuleHooks();

            // todos os eventos (antes eventos só do app, como before_build, eram descartados)
            foreach( array_unique(array_merge(array_keys($app_hooks), array_keys($module_hooks))) as $event ){
                $hooks[$event] = array_merge($app_hooks[$event] ?? [], $module_hooks[$event] ?? []);
            }

            Cache::set('regular_module_hooks', $hooks);
        }

        return self::$hooks = $hooks;
    }

    private static function getAppHooks()
    {
        return self::normalize(Core::config('hooks') ?? [], 'App\\Common', null);
    }

    private static function getModuleHooks()
    {
        $hooks = [];

        foreach( Module::getConfigs('regular') as $module ){

            if ( ($module['active'] ?? true) === false ) continue;

            foreach( self::normalize($module['hooks'] ?? [], $module['namespace'], $module['module_name']) as $event => $items ){
                $hooks[$event] = array_merge($hooks[$event] ?? [], $items);
            }
        }

        return $hooks;
    }

    private static function normalize($config, string $namespace, ?string $moduleName): array
    {
        $hooks = [];

        foreach( (array) $config as $event => $executors ){

            if ( empty($executors) ) continue;

            foreach( (array) $executors as $executor ){
                $hooks[$event][] = [
                    'namespace' => $namespace,
                    'module_name' => $moduleName,
                    'executor' => $executor
                ];
            }
        }

        return $hooks;
    }
}
