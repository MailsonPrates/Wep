<?php

namespace App\Core;


class Core
{

    public static function start($configure=[])
    {
        return new self();
    }

    public function run()
    {
        echo "App runing";
    }

    /**
     * Retorna dados de configuração da aplicação.
     * - Em produção, retorna dados do cache.
     * - Quando é arquivo .env retorna pelo getenv
     * 
     * @example
     * 
     * App::config("env", "key");
     * App::config("routes.web", "key");
     * App::config("routes.api", "key");
     */
    public static function config()
    {

    }

     /**
     * Retorna dados de configuração da aplicação.
     * Em produção, retorna dados do cache
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