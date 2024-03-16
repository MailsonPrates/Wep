<?php

namespace App\Core\Engine\Builder\Vendor;

use App\Core\Core;
use App\Core\Obj;
use App\Core\Str;

class Endpoint
{

    private static $http_methods_aliase = [
        'create' => 'post',
        'update' => 'put',
        'find' => 'get'
    ];

    public static function convertToRoute($endpoints=[])
    {
        $routes = [];

        foreach( $endpoints as $key => $endpoint ){

            // produtos|get:http_method|update|delete
            $parts = explode("|", $key);

            $resource = $parts[0];
            $methods = array_slice($parts, 1);
            
            foreach( $methods as $method ){

                $has_props = str_contains($method, ':');
                $prop = '';

                if ( $has_props ){
                    $method_parts = explode(':', $method);
                    $method = $method_parts[0];
                    $prop = $method_parts[1];
                }

                $routes[] = [
                    'path' => "/$resource/$method",
                    'api' => $method,
                    'resource' => $resource
                ];
            }

        }

        return $routes;
    }

    /**
     * @return object array
     */
    public static function buildResources($endpoints=[])
    {
        $resources = [];

        foreach( $endpoints as $key => $endpointUrl ){

            $endpoint = self::getData($key, $endpointUrl);
            $resource = $endpoint->resource;
            
            foreach( $endpoint->methods as $method ){

                $method_data = self::getMethodData($method);

                if ( !isset($resources[$resource]) ){
                    $resources[$resource] = [];
                }

                $resources[$resource][$method_data->method] = [
                    'url' => $endpoint->url,
                    'headers' => $endpoint->headers,
                    'type' => $method_data->type
                ];
            }
        }

        return $resources;
    }

    /**
     * @return object resource|url|headers|methods
     */
    private static function getData($key, $url)
    {
        // produtos|get:http_method|update|delete
        $parts = explode("|", $key);
        $resource = trim($parts[0]);
        $methods = array_slice($parts, 1);

        $response = Obj::set([
            'resource' => $resource,
            'methods' => $methods
        ]);

        $custom_data = self::getCustomData($url);

        $response->url = $custom_data->url;
        $response->headers = $custom_data->headers ?? [];

        return $response;
    }

    /**
     * @return object url|headers
     */
    private static function getCustomData($url='')
    {
        $is_shortcut = !is_array($url);

        if ( $is_shortcut ) return Obj::set([
            'url' => $url
        ]);

        $data = $url;

        $response = Obj::set([
            'url' => $data['url']
        ]);

        if ( !empty($data['headers']) ){
            $response->headers = $data['headers'];
        }

        return $response;
    }

    /**
     * @return object method|type|
     */
    private static function getMethodData($method='')
    {
        $response = Obj::set([]);

        // update:patch
        $has_props = str_contains($method, ':');
        $prop = '';

        if ( $has_props ){
            $method_parts = explode(':', $method);
            $method = $method_parts[0];
            $prop = trim($method_parts[1] ?? '');
        }

        $response->method = trim($method);
        $response->type = self::$http_methods_aliase[$method] ?? $method;

        if ( $prop ){
            $response->type = $prop;
        }

        return $response;
    }

    public static function getParsedHeaders($headers=[])
    {
        $headers_parsed = [];

        foreach( $headers as $item ){
            $headers_parsed[] = self::parseEnvVariables($item);
        }

        return $headers_parsed;
    }

    /**
     * Troca placeholders do arquivo env 
     * 
     * @example
     * {{ env.NS_URL_V1 }}/products
     * em: www.url.com.br/products
     */
    public static function parseEnvVariables($text='')
    {
        $has_placeholder = str_contains($text, '{{');

        if ( !$has_placeholder ) return $text;

        $text_trim = str_replace(['{{ ', ' }}'], ['{{', '}}'], $text);
        $parts = explode(' ', $text_trim);

        $words_parsed = [];

        foreach( $parts as $word ){
            $is_placeholder = str_contains($word, '{{env.');
            $parsed = $word;

            if ( $is_placeholder ){
                $key = Str::between($word, '{{', '}}');
                $value = Core::config($key);
                $parsed = str_replace('{{'.$key.'}}', $value, $word);
            }
                
            $words_parsed[] = $parsed;
        }

        return join(' ', $words_parsed);
    }

}