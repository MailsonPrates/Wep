<?php

namespace Wep\Engine\Builder\Vendor;

use Wep\Core;
use Wep\Obj;
use Wep\Str;

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
     * Hooks do endpoint somados aos do módulo; "-nome" remove um hook herdado.
     * (Antes a remoção usava array_splice dentro do próprio foreach e pulava itens.)
     *
     * @param array $current
     * @param array $prev
     *
     * @return array
     */
    private static function mergeHooks($current, $prev)
    {
        $hooks_final = [
            'beforeRequest' => (array) ($prev['beforeRequest'] ?? []),
            'afterRequest' => (array) ($prev['afterRequest'] ?? []),
            'onError' => (array) ($prev['onError'] ?? []),
            'onSuccess' => (array) ($prev['onSuccess'] ?? [])
        ];

        foreach( (array) $current as $event => $hooks ){

            $to_remove = [];

            foreach( (array) $hooks as $name ){
                if ( str_starts_with($name, '-') ){
                    $to_remove[] = substr($name, 1);
                    continue;
                }

                $hooks_final[$event][] = $name;
            }

            $hooks_final[$event] = array_values(array_diff($hooks_final[$event] ?? [], $to_remove));
        }

        return $hooks_final;
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

    /**
     * Headers no formato do cURL ("Nome: valor"), com as variáveis do .env resolvidas.
     * Quebras de linha são removidas: um valor com "\r\n" criaria headers extras.
     */
    public static function getParsedHeaders($headers=[])
    {
        $headers_parsed = [];

        foreach( $headers as $key => $value ){
            $name = preg_replace('/[^A-Za-z0-9-]/', '', (string) $key);
            $value = preg_replace('/[\r\n\0]+/', ' ', self::parseEnvVariables((string) $value));

            if ( $name !== '' ) $headers_parsed[] = $name . ': ' . trim($value);
        }

        return $headers_parsed;
    }

    /**
     * Troca placeholders do .env: "{{ env.NS_URL_V1 }}/products" → "https://api.exemplo.com/products".
     *
     * Placeholders que não são "env." ficam como estão. (Antes a função se chamava de novo enquanto
     * houvesse "{{" no texto: um "{{ versao }}" entrava em recursão infinita e derrubava o PHP.)
     */
    public static function parseEnvVariables($text='')
    {
        $text = (string) $text;

        if ( !str_contains($text, '{{') ) return $text;

        return preg_replace_callback(
            '/\{\{\s*env\.([A-Za-z_][A-Za-z0-9_]*)\s*\}\}/',
            fn ($match) => (string) (Core::config('env.' . $match[1]) ?? ''),
            $text
        );
    }
}
