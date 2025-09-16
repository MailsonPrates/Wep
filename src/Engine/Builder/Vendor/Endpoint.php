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

    public static function convertToRoute($module_endpoints)
    {
        $routes = [];

        foreach( $module_endpoints as $resource => $endpoint ){

            $endpoint_path = $endpoint['path'] ?? "";
            $endpoint_methods = $endpoint['methods'] ?? [];
            $endpoint_groups = $endpoint['groups'] ?? [];

            foreach($endpoint_methods as $method){
                $has_props = str_contains($method, ':');

                if ( $has_props ){
                    $method_parts = explode(':', $method);
                    $method = $method_parts[0];
                }

                $routes[] = [
                    'path' => "/$resource/$method",
                    'method' => $method,
                    'resource' => $resource
                ];
            }

            foreach($endpoint_groups as $group){

                $group_path = $group['path'];
                $group_methods = $group['methods'] ?? [];
                $path = $endpoint_path . $group_path;

                foreach($group_methods as $method){
                    $has_props = str_contains($method, ':');
    
                    if ( $has_props ){
                        $method_parts = explode(':', $method);
                        $method = $method_parts[0];
                    }
    
                    $routes[] = [
                        'path' => $path . '/' . $method,
                        'method' => $method,
                        'resource' => $resource
                    ];
                }
            }
        }

        return $routes;
    }

    /**
     * @return object array
     */
    public static function buildResources($module=[])
    {
        $resources = [];
        $module_endpoints = $module['endpoints'] ?? [];
        $module_urls = $module['url'] ?? $module['urls'] ?? "";
        $module_headers = $module['headers'] ?? [];
        $module_hooks = $module['hooks'] ?? [];

        $has_multiples_urls = is_array($module_urls);

        foreach( $module_endpoints as $resource => $endpoint ){

            // $endpoint = self::getData($resource, $endpoint, $module);
            //  $resource = $endpoint->resource;

            $endpoint_methods = $endpoint['method'] ?? $endpoint['methods'] ?? [];
            $endpoint_url = $endpoint['url'] ?? "";
            $endpoint_path = $endpoint['path'] ?? "";
            $endpoint_headers = $endpoint['header'] ?? $endpoint['headers'] ?? [];
            $endpoint_headers = self::mergeHeaders($endpoint_headers, $module_headers);
            $endpoint_groups = $endpoint['group'] ?? $endpoint['groups'] ?? [];
            $endpoint_hooks = $endpoint['hooks'] ?? [];
            $endpoint_hooks = self::mergeHooks($endpoint_hooks, $module_hooks);
            $endpoint_debug = $endpoint['debug'] ?? false;

            $url = $endpoint_url ?: $module_urls;

            if ( $has_multiples_urls ){
                $url_key = Str::between($endpoint_url, "{{", "}}");
                $url_key = trim($url_key);

                $url = $module_urls[$url_key] ?? $endpoint_url;
            }

            $url .= $endpoint_path; 

            foreach( $endpoint_methods as $method ){

                $method_data = self::getMethodData($method);

                if ( !isset($resources[$resource]) ){
                    $resources[$resource] = [];
                }

                $resources[$resource][$method_data->method] = [
                    'url' => $url,
                    'headers' => $endpoint_headers,
                    'type' => $method_data->type,
                    'hooks' => $endpoint_hooks,
                    'debug' => $endpoint_debug
                ];
            }

            // Groups
            foreach($endpoint_groups as $group){
                $group_path = $group['path'];
                $group_methods = $group['method'] ?? $group['methods'] ?? [];
                $group_url = $url . $group_path;

                foreach( $group_methods as $method ){

                    $method_data = self::getMethodData($method);
    
                    if ( !isset($resources[$resource]) ){
                        $resources[$resource] = [];
                    }
    
                    $resources[$resource][$method_data->method] = [
                        'url' => $group_url,
                        'headers' => $endpoint_headers,
                        'type' => $method_data->type,
                        'hooks' => $endpoint_hooks
                    ];
                }

            }
        }

        return $resources;
    }

    /**
     * @param array $current
     * @param array $prev
     * 
     * @return array
     */
    private static function mergeHeaders($current, $prev)
    {
        $removes = $current['remove'] ?? $current['removes'] ?? [];
        
        if ( count($removes) > 0 ){
            unset($current['remove']);
            unset($current['removes']);
        }

        foreach($removes as $item){
            unset($prev[$item]);
        }

        $headers_final = array_merge($prev, $current);

        return $headers_final;
    }

        /**
     * @param array $current
     * @param array $prev
     * 
     * @return array
     */
    private static function mergeHooks($current, $prev)
    {
        $hooks_final = [
            'beforeRequest' => $prev['beforeRequest'] ?? [],
            'afterRequest' => $prev['afterRequest'] ?? [],
            'onError' => $prev['onError'] ?? [],
            'onSuccess' => $prev['onSuccess'] ?? []
        ];

       foreach($current as $event => $hooks){

            $hooks_to_remove = [];

            foreach($hooks as $name){
                $is_remove = substr($name, 0, 1) === "-";

                if ( $is_remove ){
                    $hook_to_remove = substr($name, 1);
                    $hooks_to_remove[] = $hook_to_remove;
                    continue;
                }

                $hooks_final[$event][] = $name;
            }

            foreach($hooks_final[$event] as $i => $item){

                if ( in_array($item, $hooks_to_remove) ){
                    array_splice($hooks_final[$event], $i, 1);
                }
            }
       }

       return $hooks_final;
    }

    /**
     * @return object resource|url|headers|methods
     */
    private static function getData($resource, $endpoint, $module)
    {
        $methods = $endpoint['methods'] ?? [];

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

        foreach( $headers as $key => $value ){
            $headers_parsed[] = $key . ': ' . self::parseEnvVariables($value);
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
                $value = Core::config($key) ?? "";
                $parsed = str_replace('{{'.$key.'}}', $value, $word);
            }
                
            $words_parsed[] = $parsed;
        }

        return join(' ', $words_parsed);
    }

}