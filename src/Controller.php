<?php

namespace App\Core;

use App\Core\Response;
use App\Core\Router\Http\RouterHttpRequest;
use Exception;

trait Controller
{
    public function moduleApiControllerHandle(RouterHttpRequest $request)
    {
        $route_data = $request->routeMapData();
        $is_vendor_module = $route_data->vendor;

        if ( $is_vendor_module ) return $this->handleVendorModule($route_data, $request);

        $route_method_to_call = $route_data->method;

        $is_default_method = in_array($route_method_to_call, ['get', 'create', 'delete', 'update']);
        $is_invalid_custom_method_call = !$is_default_method && !method_exists($this, $route_method_to_call);

        $response = new Response;

        if ( !$route_method_to_call || $is_invalid_custom_method_call ){
            return $response->json('Método não existe ou não permitido', false);
        }

        $module_class =  $route_data->namespace . '\\' . $route_data->module_last;

        if ( $is_default_method ) return $this->handleDefaultMethods(
            $route_method_to_call, 
            $request,
            $response,
            $module_class
        );

        return $this->{$route_method_to_call}($request, $response, $module_class);
    }

    protected function handleDefaultMethods($method, $request, $response, $module_class)
    {
        if ( !class_exists($module_class) ) return $response->json(Response::error("Class não existe: ". $module_class));

        try {

            // Verifica se método existe no ModuleApi
            // para casos de substituição do método default
            $method_exist_in_module_api = method_exists($this, $method);

            if ( $method_exist_in_module_api ){
                // Executa método substituto
                return call_user_func_array([$this, $method], [$request, $response]);
            }

            $data = $request->data();
            $method_to_call = $module_class . "::$method";

            $result = $method_to_call($data);
            return $response->json($result);

        } catch (\Throwable $e) {
            /** @todo add trace em env dev */
            return $response->json($e->getMessage(), false);
        }
    }

    protected function handleVendorModule($routeData, RouterHttpRequest $request)
    {
        $response = new Response();
        
        try {

            $method = $routeData->method;
            $resource = $routeData->resource;
            $module_class = $routeData->namespace . '\\' . $routeData->module;
        
            $vendor_class = $module_class . '::resource';
            $vendor_resource = $vendor_class($resource);

            $data = $request->data();

            $result = $vendor_resource->{$method}($data);

            return $response->json($result);

        } catch (\Throwable $e) {
            /** @todo add trace em env dev */
            return $response->json($e->getMessage(), false);
        }
    }
}