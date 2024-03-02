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
     * 
     * @param string $method
     * @param string|array $content
     * @param array $params
     * 
     * @example
     * #1 - Response::json($regular_data);
     * #2 - Response::json("Messagem de error", "error");
     * #3 - Response::json($data, "success");
     * #4 - Response::json("Messagem de error com dados", ["code" => 500], "error");
     * #5 - Response::json($data, ["other_data" => []], "success");
     * #6 - Response::json("error|success");
     * 
     *  - Response::json(true|false);
     *  - Response::json($data, true); // success
     *  - Response::json($data, false); // error
     * 
     * @return string
     */
    public static function json()
    {
        $args = func_get_args();
        $args_count = func_num_args();

        $arg_0 = $args[0] ?? '';
        $arg_1 = $args[1] ?? null;
        $arg_2 = $args[2] ?? null;

        $aliases = [
            true => 'success',
            false => 'error'
        ];

        $arg_0 = is_bool($arg_0) ? $aliases[$arg_0] : $arg_0;
        $arg_1 = is_bool($arg_1) ? $aliases[$arg_1] : $arg_1;
        $arg_2 = is_bool($arg_2) ? $aliases[$arg_2] : $arg_2;

        $response = [];
        
        $is_regular_response = $args_count === 1;

        if ( $is_regular_response ){

            // #5 e #6
            $is_shortcut = is_string($arg_0) && in_array(strtolower($arg_0), ["error", "success"]);

            if ( $is_shortcut ){
                $method = strtolower($arg_0);
                $response = self::$method();
            
            // #1
            } else {
                $response = $arg_0;
            }
        }

        // #2 e #3
        $is_state_response = $args_count === 2 && (is_string($arg_1));

        if ( $is_state_response ){
            $content = $arg_0;
            $method = $arg_1;

            $response = self::$method($content);
        }

        // #4 e #5
        $is_state_params_response = $args_count === 3;

        if ( $is_state_params_response ){
            $content = $arg_0;
            $params = $arg_1;
            $method = $arg_2;
            
            $response = self::$method($content, $params);
        }

        echo json_encode($response);
    }
}