<?php

namespace App\Core;

use App\Core\Engine\Boot;

class Core
{
    use Boot;

    /**
     * Retorna dados de configuração da aplicação.
     * - Em produção, retorna dados do cache.
     * - Quando é arquivo .env retorna pelo getenv
     * 
     * @example
     * 
     * # Case 0: retorna todas as configs
     * App::config();
     * 
     * # Case 1: retorna configs do app
     * App::config("name");
     * App::config("name|title"); name ou title
     * 
     * # Case 2: retorna configurações do arquivo env
     * App::config("env", "key");
     * App::config("env.key");
     * 
     * # Case 3: retorna config dos módulos
     * App::config("module", "Pedido")->routes;
     * 
     * 
     * @todo
     * App::config("routes.web", "key");
     * App::config("routes.api", "key");
     * 
     * @todo 
     * - Implementar cache
     */
    public static function config()
    {
        $args = func_get_args();
        $args_count = func_num_args();

        if ( !$args_count ) return self::$config;

        $arg_0 = $args[0];
        $arg_1 = $args[1] ?? null;
        $arg_2 = $args[2] ?? null;

        $arg_0_lower = strtolower($arg_0 ?? '');

        // checks
        $is_single_arg = $args_count === 1;
        $is_env_config = str_contains($arg_0_lower, 'env.') 
            || str_contains($arg_0_lower, 'env.') && $args_count > 1;

        // # Case 1 - App::config("name");
        $is_app_config = $is_single_arg && !$is_env_config;

        if ( $is_app_config ){
            $key = $arg_0;

            // App::config("name|title");
            $has_default = str_contains($key, '|');

            if ( $has_default ){
                $key_parts = explode('|', $key);
                $key = trim($key_parts[0] ?? '');
                $key_default = trim($key_parts[1] ?? '');

                return Obj::get(self::$config, $key, $key_default);
            }

            return Obj::get(self::$config, $key);
        }

        // # Case 2;
        if ( $is_env_config ){

            // App::config("env.key");
            if ( $is_single_arg ){
                $key = str_replace("env.", '', $arg_0_lower);
                $key = strtoupper($key);
                return getenv($key) ?? null;
            }

            // App::config("env", "key");
            if ( $arg_1 ){
                $key = strtoupper($arg_1);
                return getenv($key) ?? null;
            }
        }

        // # Case 3 - App::config("module", "Pedido")->routes;
        $is_module_config = is_string($arg_0) && in_array($arg_0_lower, ["module", "modules"]);

        if ( $is_module_config ){
            $module_name = ucfirst($arg_1 ?? '');

            if ( !$module_name ) return Obj::set(); /** @todo */

            $module_filename = DIR_MODULES . "/$module_name/config/module.php";

            if ( !file_exists($module_filename) ) return Obj::set(); /** @todo */ 

            $module_content = include_once($module_filename) ?? [];

            return Obj::set($module_content);
        }

       
    }

    public static function assets($path="", $cacheBooster=null)
    {
        $assets_url = explode("/public", DIR_ASSETS)[1];

        if ( $path ){
            $assets_url .= ($path[0] == "/" ? $path : "/" . $path);

            if ( $cacheBooster === true ){
                $assets_url .= '?v='.time();
            }
        }

        return $assets_url;
    }

    public static function data($key, $)
    {

    }

     /**
     * @todo
     * 
     * @example
     * 
     * App::path("/config/.env");
     * App::path("config", ".env");
     */
    public static function path()
    {

    }
}