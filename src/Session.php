<?php

namespace App\Core;

class Session
{

    /**
     * Inicializa sessão caso já não tenha
     * sido incializada
     */
    public static function start()
    {
        if ( session_status() !== PHP_SESSION_ACTIVE ) {
            session_start();
        }
    }

    /**
     * Add dados ao $_SESSION
     * @param string $key
     * @param mixed $value
     */
    public static function set($key, $value)
    {
        $_SESSION[$key] = $value;
    }


    /**
     * Pega valor salvo em uma sessão
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public static function get($key="", $default="")
    {
        return $_SESSION[$key] ?? $default;
    }

    /**
     * Encerra sessão
     */
    public static function end()
    {
        session_unset();
        session_destroy();
    }

}