<?php

namespace App\Core;

use App\Core\Obj;

class Response
{
    /**
     * Retorna responsa de erro
     * 
     * Usage:
     * Response::error(); {error: true}
     * Response::error("message"); {error: true, message: "message"}
     * Response::error(["code" => 404]); {error: true, code: 404}
     * Response::error("message", ["data" => []]); {error: true, message: "", data: []}
     * 
     * @param string|array $message
     * @param array $params
     * 
     * @return object
     */
    public static function error($message="", $params=[])
    {
        $response = ["error" => true];

        if ( $message ){

            if ( is_array($message) ){
                $response = array_merge($response, $message);
            } else {
                $response["message"] = $message;
            }
        }

        $response = array_merge($response, $params);

        return Obj::set($response);
    }

    /**
     * Retorna responsa de sucesso
     * 
     * Usage:
     * Response::success(); {error: false}
     * Response::success([]); {error: false, data: []}
     * Response::success([], ["debug" => []]); {error: false, message: "", data: [], debug: []}
     * 
     * @param mixed $data
     * @param array $params
     * 
     * @return object
     */
    public static function success($data=null, $params=[])
    {
        $response = ["error" => false];

        if ( isset($data)){
            $response["data"] = $data;
            $response = array_merge($response, $params);
        }
        
        return Obj::set($response);
    }

    /**
     * Retorna resposta em formato json
     * Usage:
     * Response::json("error", "message", ["data" => []]) {error: true, message: "", data: []}
     * 
     * @param string $method
     * @param string|array $content
     * @param array $params
     * 
     * @return string
     */
    public static function json($method, $content=null, $params=[])
    {
        $response = isset($method) && is_string($method) 
            ? self::$method($content, $params) 
            : $method;

        return json_encode($response);
    }
}