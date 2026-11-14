<?php

/**
 * Substitutos mínimos das classes que ficam FORA do módulo (Response, Obj, Core, Singleton).
 * Só são carregados se o projeto não fornecer as classes reais (veja tests/Pest.php).
 * Se o seu Response tiver outro formato, ajuste apenas os helpers succeeded()/rows()/meta()/errorOf()
 * em tests/Pest.php.
 */

namespace App\Core;

if (!class_exists(Response::class)) {
    class Response
    {
        public static function success($data = [], $meta = [])
        {
            return (object) ['success' => true, 'data' => $data, 'meta' => $meta];
        }

        public static function error($message = 'Error')
        {
            return (object) ['success' => false, 'error' => $message];
        }
    }
}

if (!class_exists(Obj::class)) {
    class Obj
    {
        public static function set($data = [])
        {
            return (object) $data;
        }
    }
}

if (!class_exists(Core::class)) {
    class Core
    {
        public static function config($group, $key, $default = null)
        {
            return $_ENV[$key] ?? $default;
        }
    }
}

if (!class_exists(Singleton::class)) {
    abstract class Singleton
    {
        private static $instances = [];

        public static function instance()
        {
            return self::$instances[static::class] ??= new static();
        }
    }
}
