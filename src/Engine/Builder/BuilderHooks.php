<?php

namespace App\Core\Engine\Builder;

use App\App;
use App\Core\Cache;
use App\Core\Engine\Module;

class BuilderHooks
{
 
    public static function beforeBuild()
    {
        $hooks = self::getHooks();
        $event_hooks = $hooks['before_build'] ?? [];

        if ( empty($event_hooks) ) return;

        self::execute($event_hooks);
    }

    public static function eachRoute($route, $data)
    {
        $hooks = self::getHooks();
        $event_hooks = $hooks['each_route'] ?? [];

        if ( empty($event_hooks) ) return $route;

        //echo json_encode($event_hooks) . PHP_EOL;
        //echo '-----------------' . PHP_EOL;

        self::execute($event_hooks, function($executor) use (&$route, $data){
            $result = $executor($route, $data);
            $route = $result ?: $route;
        });

        return $route;
    }

    public static function afterBuild()
    {
        $hooks = self::getHooks();
        $event_hooks = $hooks['after_build'] ?? [];

        if ( empty($event_hooks) ) return;

        self::execute($event_hooks);
    }

    public static function reset()
    {
        Cache::delete('regular_module_hooks');
    }

    private static function getHooks()
    {
        $hooks = Cache::get('regular_module_hooks') ?: [];

        if ( empty($hooks) ){

            $app_hooks = self::getAppHooks();
            $hooks = self::getModuleHooks();

            // Faz merge do App hooks com modulo hooks
            foreach($hooks as $event => $executors){
                $app_executors = $app_hooks[$event] ?? [];
                $hooks[$event] = array_merge($app_executors, $executors);
            }

            Cache::set('regular_module_hooks', $hooks);
        }

        return $hooks;
    }

    private static function getAppHooks()
    {
        $app_hooks = App::config('hooks') ?? [];
        $hooks = [];
        
        foreach($app_hooks as $event => $executor){
                
            if ( empty($executor) ) continue;

            $executor_list = is_string($executor) ? [$executor] : $executor;

            if ( !isset($hooks[$event]) ){
                $hooks[$event] = [];
            }

            foreach($executor_list as $execItem){
                $hooks[$event][] = [
                    'namespace' => 'App\\Common',
                    'module_name' => null,
                    'executor' => $execItem
                ];
            }
        }

        return $hooks;
    }

    private static function getModuleHooks()
    {
        $module_configs = Module::getConfigs('regular');
        $hooks = [];

        foreach($module_configs as $module){
            $module_hooks = $module['hooks'] ?? [];

            if ( empty($module_hooks) ) continue;

            foreach($module_hooks as $event => $executor){
                
                if ( empty($executor) ) continue;

                $executor_list = is_string($executor) ? [$executor] : $executor;

                if ( !isset($hooks[$event]) ){
                    $hooks[$event] = [];
                }

                foreach($executor_list as $execItem){
                    $hooks[$event][] = [
                        'namespace' => $module['namespace'],
                        'module_name' => $module['module_name'],
                        'executor' => $execItem
                    ];
                }
            }
        }

        return $hooks;
    }

    private static function execute($eventHooks, $callback=null)
    {
        foreach($eventHooks as $hook){
            
            $executor = $hook['executor'];
            $is_full_namespace = str_contains($executor, "\\");
            $namespace = $hook['namespace'] . "\\Hooks\\" . $executor;
            $method = "handle";

            if ( $is_full_namespace ){
                $executor_parts = explode("@", $executor);
                $namespace = $executor_parts[0];
                $method = $executor_parts[1] ?? $method;
            }

            if ( !method_exists($namespace, $method) ) continue;

            $executorClass = new $namespace;
            $class_method = [$executorClass, $method];

            if ( $callback && is_callable($callback)) return $callback($class_method);

            $executorClass->$method();
        }
    }

}