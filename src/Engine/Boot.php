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

        date_default_timezone_set("America/Sao_Paulo");
        
        if ( ENV == "DEV" ){
            error_reporting(E_ALL);
            ini_set('display_errors', '1');
        }
        
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

        // Build
        define("DIR_BUILDS", DIR_STORAGE . "/builds");
        define("ROUTE_MAPS_FILENAME", DIR_BUILDS. "/route-maps.php");
        define("ROUTE_MAPS_BUILD_FILENAME_BACK", DIR_BUILDS. "/route-maps.build.php");
        define("ROUTE_MAPS_BUILD_FILENAME_FRONT", DIR_BUILDS. "/route-maps.build.js");
        define("CONFIGS_BUILD_FILENAME_FRONT", DIR_BUILDS. "/configs.build.json");

        // App
        define("DIR_APP", DIR_ROOT . "/src");
        define("DIR_MODULES", DIR_APP . "/Modules");
        define("DIR_COMMON", DIR_APP . "/Common");
        define("DIR_RESOURCES", DIR_APP . "/Resources");
        define("DIR_TEMPLATES", DIR_RESOURCES . "/Templates");
        define("DIR_MIDDLEWARES", DIR_RESOURCES . "/Middlewares");

        // Namespace
        define("APP_NAMESPACE", "App\\");
        define("APP_MODULES_NAMESPACE", APP_NAMESPACE . "Modules\\");
        define("APP_TEMPLATES_NAMESPACE", APP_NAMESPACE."Resources\\Templates\\");

       
        self::startEnv();

        // Dependentes do Env
        define("ENV", getenv("ENV"));
        define("APP_URL", getenv("APP_URL"));

        // Core
        define("CORE_NAMESPACE", "App\\Core\\");
        define("DEFAULT_VIEW_CONTROLLER", CORE_NAMESPACE . "View\\View@handle");
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