<?php

namespace App\Core\Engine;

use App\Core\Response;
use App\Core\Env\Env;
use App\Core\Router\Route;

trait Boot
{
    private static $status = "off";
    private static $config = [];
    public static $data = [];

    /**
     * @param array $config
     * @param string $config->name
     * @param string $config->title // document title
     * @param array $config->dir
     * @param string $config->root
     * @param string $config->env
     * @param string $config->favico
     */
    public static function start($config=[])
    {
        if ( self::$status == "on" ) return new self();

        self::$config = $config;

        if ( !self::config('dir.root') ){
            Response::json("Diretório root não informado na configuração do App", "error");
            return;
        }
        
        self::setConstants();

        self::$status = "on";

        Route::start(APP_URL, "@");

        if ( file_exists(ROUTE_MAPS_BUILD_FILENAME_BACK) ){
            include_once(ROUTE_MAPS_BUILD_FILENAME_BACK);
        }

        self::setData();

        return new self();
    }

    /**
     * @todo talvez usar um try catch
     */
    public static function run()
    {
        $validate = self::validate();

        if ( $validate->error ){
            Response::json($validate->message, "error");
            exit();
        }

        /**
         * Debug only
         * @todo criar recurso para debug visual
         */
        //return Response::json([]);

        Route::execute();
    }

    private static function validate()
    {
        return Response::success();
    }

    private static function setData()
    {
        $filename = DIR_CONFIG . "/data.php";

        if ( !file_exists($filename) ) return;

        $data = include_once($filename) ?? [];

        self::$data = $data;
    }

    private static function setConstants()
    {
        // Root
        define("DIR_ROOT", self::config('dir.root'));
        define("DIR_CONFIG", DIR_ROOT . "/config");
        define("DIR_PUBLIC", DIR_ROOT . "/public");
        define("DIR_ASSETS", DIR_PUBLIC . "/assets");
        define("DIR_STORAGE", DIR_ROOT . "/storage");
        define("DIR_BUILDS", DIR_STORAGE . "/builds");
        define("DIR_LOG", DIR_STORAGE . "/log");
        define("DIR_CACHE", DIR_STORAGE . "/cache");

        // Build
        define("DIR_BUILD_MODULE_APIS", DIR_BUILDS. "/apis");
        define("ROUTE_MAPS_FILENAME", DIR_BUILDS. "/route-maps.php");
        define("ROUTE_MAPS_BUILD_FILENAME_BACK", DIR_BUILDS. "/route-maps.build.php");
        define("ROUTE_MAPS_BUILD_FILENAME_FRONT", DIR_BUILDS. "/route-maps.build.js");
        define("CONFIGS_BUILD_FILENAME_FRONT", DIR_BUILDS. "/configs.build.json");
        define("VENDOR_ENDPOINTS_MAP_FILENAME", DIR_BUILDS. "/vendor-endpoints.build.php");
        define("APP_CONSTANTS_FILENAME", DIR_BUILDS. "/constants.build.php");
        define("APP_HOT_RELOAD_FILENAME", DIR_LOG. "/hot-reload.txt");

        // App
        define("DIR_APP", DIR_ROOT . "/src");
        define("DIR_MODULES", DIR_APP . "/Modules");
        define("DIR_COMMON", DIR_APP . "/Common");
        define("DIR_TOOLS", DIR_COMMON . "/Tools");
        define("DIR_TEMPLATES", DIR_COMMON . "/Templates");
        define("DIR_MIDDLEWARES", DIR_COMMON . "/Middlewares");

        // Namespace
        define("APP_NAMESPACE", "App\\");
        define("APP_MODULES_NAMESPACE", APP_NAMESPACE . "Modules\\");
        define("APP_TEMPLATES_NAMESPACE", APP_NAMESPACE."Common\\Templates\\");

        self::startEnv();

        // Dependentes do Env
        define("ENV", getenv("ENV"));
        define('ENV_DEV', (ENV == 'DEV'));
        define("APP_URL", getenv("APP_URL"));

        // Core
        define("CORE_NAMESPACE", "App\\Core\\");
        define("DEFAULT_VIEW_CONTROLLER", CORE_NAMESPACE . "View\\View@handle");

        // Custom

        if ( file_exists(APP_CONSTANTS_FILENAME) ){
            include_once(APP_CONSTANTS_FILENAME);
        }
    }

    private static function startEnv()
    {
        $path = self::config("env") ?? DIR_CONFIG."/.env";

        if ( !$path ){
            echo "Arquivo .env inválido";
            exit();
        }

        $env = new Env($path);
        $env->load();
    }
}