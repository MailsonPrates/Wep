<?php

namespace App\Core;

use App\Core\Engine\Builder\Vendor\Endpoint;
use Exception;
use App\Core\Http\HttpRequest;

class VendorMethod
{
    /**
     * @var array
     */
    private $headers;

    /**
     * @var array
     */
    private $resources;

    /**
     * @var string
     */
    private $vendor;
    
    /**
     * @var object
     */
    private $vendor_instance;

    /**
     * @var array
     */
    private $hooks = [];

    public function __construct($config=[]) 
    {
       // $this->headers = $config['headers'];
        $this->resources = $config['resources'];
        $this->vendor = $config['vendor'];
        $this->vendor_instance = $config['vendor_instance'];
        //$this->hooks = $config['hooks'];
    }

    public function __call($methodName, $arguments)
    {
        $method_data = $this->resources[$methodName] ?? [];
        $vendor = $this->vendor;

        if ( empty($method_data) ) throw new Exception("Método '$methodName' não definido nas configurações do módulo '$vendor'. Certifique-se de que o arquivo de config. do módulo foi corretamente criado e o app foi atualizado pelo comando > php app update");

        $headers = $method_data['headers'] ?? [];
        $hooks = $method_data['hooks'] ?? [];

        $props = [
            'headers' => Endpoint::getParsedHeaders($headers),
            'hooks' => $hooks,
            'url' => Endpoint::parseEnvVariables($method_data['url']),
            'type' => $method_data['type'],
            'arguments' => $arguments[0] ?? []
        ];

        if ( $method_data['debug'] ) return [
            'method_data' => $method_data,
            'request_props' => $props,
            'method_name' => $methodName
        ];

        return $this->executeMethod($props);
    }

    protected function executeMethod($config)
    {
        $arguments = $config['arguments'] ?? [];

        $request_props = [
            'type' => $config['type'],
            'url' => $config['url'],
            'headers' => $config['headers'],
            'query' => $arguments['query'] ?? null,
            'fields' => $arguments['fields'] ?? [],
            'params' => $arguments['params'] ?? []
        ];

        $request = new HttpRequest($request_props);

        $hooks = $config['hooks'];

        /**
         * Call Hook beforeRequest 
         */
        if ( !empty($hooks['beforeRequest']) ){

            $before_request = $hooks['beforeRequest'];
            $before_request = is_array($before_request) ? $before_request : [$before_request];

            foreach( $before_request as $method ){

                if ( !method_exists($this->vendor_instance, $method) ){
                    throw new Exception('Método '.$method . ' não existe em '.get_class($this->vendor_instance));
                }

                try {

                    $this->vendor_instance->{$method}($request, $request_props);

                } catch (\Throwable $th) {
                    /** @todo criar log para isso */
                    throw new Exception($th->getMessage());
                }
            }
        }

        $request->execute();
        $response = $request->response();

        $is_response_error = isset($response->error) && $response->error === true;

        /**
         * Call Hook onError 
         */
        if ( $is_response_error && !empty($hooks['onError']) ){

            $on_error = $hooks['onError'];
            $on_error = is_array($on_error) ? $on_error : [$on_error];

            foreach( $on_error as $method ){
                
                if ( !method_exists($this->vendor_instance, $method) ){
                    throw new Exception('Método '.$method . ' não existe em '.get_class($this->vendor_instance));
                }

                try {

                    $this->vendor_instance->{$method}($request, $response);
                    $response = $request->response();

                } catch (\Throwable $th) {

                    /** @todo criar log para isso */
                    throw new Exception($th->getMessage());
                }
            }
        }

        /**
         * Call Hook onSuccess 
         */
        if ( !$is_response_error && !empty($hooks['onSuccess']) ){

            $on_success = $hooks['onSuccess'];
            $on_success = is_array($on_success) ? $on_success : [$on_success];

            foreach( $on_success as $method ){

                if ( !method_exists($this->vendor_instance, $method) ){
                    throw new Exception('Método '.$method . ' não existe em '.get_class($this->vendor_instance));
                }

                try {

                    $this->vendor_instance->{$method}($request, $response);
                    $response = $request->response();

                } catch (\Throwable $th) {
                    
                    /** @todo criar log para isso */
                    throw new Exception($th->getMessage());
                }
            }
        }

        /**
         * Call Hook afterRequest 
         */
        if ( !empty($hooks['afterRequest']) ){

            $after_request = $hooks['afterRequest'];
            $after_request = is_array($after_request) ? $after_request : [$after_request];

            foreach( $after_request as $method ){

                if ( !method_exists($this->vendor_instance, $method) ){
                    throw new Exception('Método '.$method . ' não existe em '.get_class($this->vendor_instance));
                }

                try {

                    $this->vendor_instance->{$method}($request, $response);
                    $response = $request->response();

                } catch (\Throwable $th) {
                    
                    /** @todo criar log para isso */
                    throw new Exception($th->getMessage());
                }
            }
        }

        return $response;
    }
}