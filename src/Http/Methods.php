<?php

namespace App\Core\Http;

use App\Core\Http\Request;

trait Methods
{
    /**
     * @example
     * Request::post('https://www.site.com.br');
     * Request::post("https://www.site.com.br, ["field1" => "value"]);
     * Request::post([
     *  "url": "", 
     *  "fields" => [], 
     *  "other-options" => true
     * ]);
     * 
     * @param string|array $url
     * @param array|null $fields
     */
    public static function post($url="", $fields=null)
    {
        $is_verbose = is_array($url) && is_null($fields);
        $request = new Request();

        $options = $is_verbose
            ? $url
            : ['url' => $url, 'fields' => $fields ?? []];

        $options['type'] = 'POST';
        
        $request->setOptions($options);
        $request->execute();

        return $request->response();
    }

    public static function put($url="", $fields=null)
    {
        $is_verbose = is_array($url) && is_null($fields);
        $request = new Request();

        $options = $is_verbose
            ? $url
            : ['url' => $url, 'fields' => $fields ?? []];

        $options['type'] = 'PUT';
        
        $request->setOptions($options);
        $request->execute();

        return $request->response();
    }

    public static function patch($url="", $fields=null)
    {
        $is_verbose = is_array($url) && is_null($fields);
        $request = new Request();

        $options = $is_verbose
            ? $url
            : ['url' => $url, 'fields' => $fields ?? []];

        $options['type'] = 'PATCH';
        
        $request->setOptions($options);
        $request->execute();

        return $request->response();
    }    

    public static function delete($url="", $fields=null)
    {
        $is_verbose = is_array($url) && is_null($fields);
        $request = new Request();

        $options = $is_verbose
            ? $url
            : ['url' => $url, 'fields' => $fields ?? []];

        $options['type'] = 'DELETE';
        
        $request->setOptions($options);
        $request->execute();

        return $request->response();
    }    

    /**
     * @example
     * Request::get("https://www.site.com.br);
     * Request::get("https://www.site.com.br, ["param1" => "value"]);
     * Request::get([
     *  "url": "", 
     *  "fields" => [], 
     *  "other-options" => true
     * ]);
     * 
     * @param string|array $url
     * @param array|null $query
     */
    public static function get($url="", $query=null)
    {
        $is_verbose = is_array($url) && is_null($query);
        $request = new Request();

        $options = $is_verbose
            ? $url
            : ['url' => $url, 'query' => $query ?? []];

        $options['type'] = 'GET';
        
        $request->setOptions($options);
        $request->execute();

        return $request->response();
    }
}