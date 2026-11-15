<?php

namespace Wep;

use Wep\Foundation\ErrorHandler;
use Wep\Router\Http\RouterHttpRequest;

trait Controller
{
    public function moduleApiControllerHandle(RouterHttpRequest $request)
    {
        $route_data = $request->routeMapData();
        $is_vendor_module = $route_data->vendor ?? false;

        if ( $is_vendor_module ) return $this->handleVendorModule($route_data, $request);

        $route_method_to_call = $route_data->method ?? null;

        $is_default_method = in_array($route_method_to_call, ['get', 'create', 'delete', 'update'], true);
        $is_invalid_custom_method_call = !$is_default_method && !method_exists($this, (string) $route_method_to_call);

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

    /**
     * CRUD automático (get/create/update/delete).
     *
     * O cliente não envia queries: só id, fields e filtros declarados no módulo
     * (contrato em Wep\Api\ClientPayload). Antes o $request->data() ia direto para o
     * modo verbose do Model.
     */
    protected function handleDefaultMethods($method, $request, $response, $module_class)
    {
        if ( !class_exists($module_class) ) return $response->json(Response::error("Módulo indisponível"));

        try {

            // Método de mesmo nome no ModuleApi substitui o padrão
            if ( method_exists($this, $method) ){
                return call_user_func_array([$this, $method], [$request, $response]);
            }

            if ( !method_exists($module_class, 'fromClient') ){
                return $response->json(Response::error("O módulo não expõe CRUD automático (Model ausente)"));
            }

            return $response->json($module_class::fromClient($method, $request->data()));

        } catch (\Throwable $e) {
            return $response->json(Response::error(self::publicErrorMessage($e)));
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

            // "debug" vindo do cliente devolvia as opções da requisição externa, incluindo
            // headers e credenciais da API. Debug só pela config do módulo (e só em DEV).
            if ( is_array($data) ) unset($data['debug']);

            // params do cliente são substituídos na URL da API externa ({id}): codificados para
            // que "../", "?" ou "#" não mudem o caminho/destino da chamada
            if ( isset($data['params']) && is_array($data['params']) ){
                $data['params'] = array_map(
                    fn ($value) => is_scalar($value) ? rawurlencode((string) $value) : '',
                    $data['params']
                );
            }

            $result = $vendor_resource->{$method}($data);

            return $response->json($result);

        } catch (\Throwable $e) {
            return $response->json(Response::error(self::publicErrorMessage($e)));
        }
    }

    /**
     * Mensagem de erro para o cliente: a da exceção em DEV; genérica fora de DEV
     * (a exceção completa vai para o log).
     */
    private static function publicErrorMessage(\Throwable $e): string
    {
        ErrorHandler::report($e);

        $isDev = defined('ENV_DEV') && ENV_DEV;

        return $isDev ? $e->getMessage() : 'Não foi possível concluir a operação';
    }
}
