<?php

namespace App\Core;

use App\Core\Engine\Boot;
use App\Core\Engine\Module;

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
     * App::config("name", 'fallback');
     * App::config("name|title"); name ou title
     * App::config("name|title", 'fallback'); name ou title ou fallback
     * 
     * # Case 2: retorna configurações do arquivo env   
     * App::config("env", "key");
     * App::config("env", "key", 'fallback');
     * App::config("env.key");
     * App::config("env.key", 'fallback');
     * 
     * # Case 3: retorna config dos módulos
     * App::config("module", "Pedido")->routes;
     * App::config("module", "Vendor.Pedido")->routes;
     * App::config("module", "Pedido", fallback)->routes;
     * 
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
        $is_module_config = is_string($arg_0) && in_array($arg_0_lower, ["module", "modules"]);

        $is_env_short = str_contains($arg_0_lower, 'env.');
        $is_env_kv = ($arg_0_lower == 'env') && $args_count > 1;
        $is_env_config = $is_env_short || $is_env_kv;

        // # Case 1 - App::config("name");
        $is_app_config = !$is_env_config && !$is_module_config;

        if ( $is_app_config ){
            $key = $arg_0;
            $fallback = $arg_1;

            // App::config("name|title", 'fallback');
            $has_or = str_contains($key, '|');

            if ( $has_or ){
                $key_parts = explode('|', $key);
                $key = trim($key_parts[0] ?? '');
                $key_or = trim($key_parts[1] ?? '');

                return Obj::get(self::$config, $key, $key_or) ?: $fallback;
            }

            // App::config("name", 'fallback');
            return Obj::get(self::$config, $key) ?: $fallback;
        }

        // # Case 2;
        if ( $is_env_config ){

            // App::config("env.key", 'fallback');
            if ( $is_env_short ){
                $key = str_replace("env.", '', $arg_0_lower);
                $key = strtoupper($key);
                $fallback = $arg_1;

                return getenv($key) ?: $fallback;
            }

            // App::config("env", "key", 'fallback');
            if ( $is_env_kv ){
                $key = strtoupper($arg_1);
                $fallback = $arg_2;
                return getenv($key) ?: $fallback;
            }
        }

        // # Case 3 - App::config("module", "Pedido")->routes;
        // App::config("module", "Vendor.Pedido")->routes;
        // App::config("module", "all")->routes;
        // App::config("module", "Pedido", fallback)->routes;
        if ( $is_module_config ){
            $module_name = $arg_1 ?? '';
            $fallback = $arg_2;

            if ( !$module_name ) return Obj::set(); /** @todo */
            
            $module_content = Module::getConfigs($module_name);
            $module_content = empty($module_content) && $fallback
                ? $fallback
                : $module_content;

            return Obj::set($module_content);
        }
    }

    /**
     * @param string $path
     * @param string|bool $cacheBooster
     * 
     * App::assets('path/to/file.jpg');
     * App::assets('path/to/file.jpg', true);
     * App::assets('path/to/file.jpg', 123);
     */
    public static function assets($path="", $cacheBooster=null)
    {
        $assets_url = explode("/public", DIR_ASSETS)[1];
        $dir_name = self::config("dir.name", null);

        if ( $dir_name ){
            $assets_url = "/" . $dir_name . $assets_url;
        }

        if ( $path ){
            $assets_url .= ($path[0] == "/" ? $path : "/" . $path);

            if ( $cacheBooster !== null ){
                $assets_url .= '?v=' . ($cacheBooster === true ? time() : $cacheBooster);
            }
        }

        return $assets_url;
    }

    /**
     * Retorna dados customizados do app
     * 
     * @example
     * 
     * App::data();
     * App::data('key');
     * App::data('key|default_key');
     * App::data('key', 'default value');
     */
    public static function data($key=null, $default=null)
    {
        $data = self::$data ?? [];

        if ( empty($data) ) return null;

        if ( !$key ) return Obj::set($data);

        $has_key_default = str_contains($key, '|');

        if ( $has_key_default ){
            $key_parts = explode('|', $key);
            $key = trim($key_parts[0] ?? '');
            $key_default = trim($key_parts[1] ?? '');

            return Obj::get($data, $key, $key_default) ?: $default;
        }

        return Obj::get($data, $key) ?? $default;
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