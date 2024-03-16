<?php

namespace App\Core;

use App\Core\Router\Http\Request;
use App\Core\Router\Http\Response;

trait Controller
{
    public function moduleApiControllerHandle(Request $request)
    {
        $route_data = $request->routeMapData();
        $is_vendor_module = $route_data->vendor;

        if ( $is_vendor_module ) return $this->handleVendorModule($route_data, $request);

        $route_method_to_call = $route_data->api;

        $is_default_method = in_array($route_method_to_call, ['get', 'create', 'delete', 'update']);
        $is_invalid_custom_method_call = !$is_default_method && !method_exists($this, $route_method_to_call);

        $response = new Response;

        if ( !$route_method_to_call || $is_invalid_custom_method_call ){
            return $response->json('Método não existe ou não permitido', false);
        }

        $module_class =  $route_data->namespace . '\\' . $route_data->module;

        if ( !class_exists($module_class) ){
            $module_class = null;
        }

        if ( $is_default_method ) return $this->handleDefaultMethods(
            $route_method_to_call, 
            $request,
            $response,
            $module_class
        );

        return $this->{$route_method_to_call}($request, $response, $module_class);
    }

    protected function handleDefaultMethods($method, $request, $response, $module)
    {
        $data = $request->data();
        $method_to_call = $module . "::$method";

        try {
            $result = $method_to_call($data);
            return $response->json($result);

        } catch (\Throwable $e) {
            /** @todo add trace em env dev */
            return $response->json($e->getMessage(), false);
        }
    }

    protected function handleVendorModule($routeData, $request)
    {
        $response = new Response();
        
        try {

            $method = $routeData->api;
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