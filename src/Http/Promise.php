<?php

namespace App\Core\Http;

class Promise
{
    public static function __callStatic($name, $arguments)
    {
        $function = "\\GuzzleHttp\\Promise\\{$name}";

        if (is_callable($function)) {
            return call_user_func_array($function, $arguments);
        }

        throw new \BadMethodCallException("Method {$name} does not exist.");
    }
}
