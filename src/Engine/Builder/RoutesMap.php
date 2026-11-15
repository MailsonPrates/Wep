<?php

namespace Wep\Engine\Builder;

use Wep\Core;
use Wep\Engine\Builder\Warning;
use Wep\Obj;
use Wep\Str;
use Wep\Engine\Builder\Vendor\Vendor;
use Wep\Engine\Module;

class RoutesMap
{
    private static $type_aliases = [
        'create' => 'POST', 
        'update' => 'PUT', 
        'delete' => 'DELETE', 
        'get'    => 'GET',
        'post'   => 'POST',
        'put'    => 'PUT' 
    ];

    public static function build($raw=false)
    {
        self::$warnings = [];

        $modules_routes = self::getModulesRoutes();

        /**
         * @todo implementar o getAppRoutes()
         * para ter a possibilidade de usar sem módulos
         */

        $build_maps = self::handleBuildMaps($modules_routes);

        if ( $raw ) return $build_maps;

        return self::buildFileContents($build_maps);
    }

    public static function getModulesRoutes()
    {
        $routes_list = [];
        $default_apis = ['get', 'create', 'update', 'delete'];

        $modules_config_list = Module::getConfigs('regular');

        foreach($modules_config_list as $module_config){

            $active = $module_config['active'] ?? true;
            $namespace = $module_config['namespace'];
            $name = $module_config['module_name'] ?? "";

            if ( $active === false ) continue;

            $module_routes = $module_config['routes'] ?? [];
            $module_routes['namespace'] = $module_routes['namespace'] ?? $namespace;
            $module_routes['module_name'] = $name;
            $module_routes['module_title'] = $module_config['title'] ?? $module_config['label'] ?? "";
            $module_routes['groups'] = $module_routes['groups'] ?? [];

            $routes_list[] = $module_routes;
        }

        // Executa Hooks: "before_build


        //echo json_encode($modules_config_list) . PHP_EOL;
        //echo "--------------------------" . PHP_EOL;
        //echo ''.PHP_EOL;

        // Pega Vendors
        $vendor_routes_list = Vendor::buildRoutes();


        $routes_list = array_merge($routes_list, $vendor_routes_list);

        // Separa rotas agrupadas na prop api
        // "api" => ["update", "delete"]
        $routes_splited = []; 

        foreach( $routes_list as $routes ){

            $groups = $routes['groups'];
            $new_routes = $routes;
            $new_routes['groups'] = [];

            foreach( $groups as $item ){

                $active = $item['active'] ?? true;

                if ( $active === false ) continue;

                $api = $item['method'] ?? null;
                $is_api_shortcut = $api && is_array($api);

                if ( $is_api_shortcut ){
                    
                    $new_item = $item;
                    $new_item['view'] = false;

                    foreach( $api as $method ){
                        $new_item['method'] = $method;
                        $new_routes['groups'][] = $new_item;
                    }

                    unset($item['method']);
                    $new_routes['groups'][] = $item;

                } else {
                    $new_routes['groups'][] = $item;
                }
            }

            $module_apis = $routes['methods'] ?? false;

            if ( $module_apis ){

                $module_apis = is_array($module_apis)
                    ? $module_apis
                    : $default_apis;

                foreach( $module_apis as $apiMethod ){
                    $api_method_lower = strtolower($apiMethod);
                    $is_default_api = in_array($api_method_lower, $default_apis);
                    $sub_path = $is_default_api ? ('/' . $api_method_lower) : '';

                    $new_routes['groups'][] = [
                        'path' => $sub_path,
                        'method' => $apiMethod
                    ];
                }
            }

            $routes_splited[] = $new_routes;
        }

        //echo json_encode($routes_splited) . PHP_EOL;
        //echo "------" . PHP_EOL;

        return $routes_splited;
    }    

    private static function handleBuildMaps($routes)
    {
        $response = [
            'raw' => [],
            'backend' => [],
            'frontend' => [],
            'frontend_imports' => [],
            'frontend_elements' => [],
            'module_apis' => []
        ];

        $route_build_items_by_module = [];

        foreach( $routes as $route ){

            $route_build = self::buildMap($route);

            $route_module_name = $route['module_name'];
            //$has_vendor = str_contains($route['namespace'], "Vendor");
            //$namespace_parts = explode($has_vendor ? "Vendor\\" : "Modules\\", $route['namespace']);
            //$route_module_name = str_replace("\\", "", $namespace_parts[1]);

            //echo $route_module_name . PHP_EOL;

            if ( !isset($route_build_items_by_module[$route_module_name]) ){
                $route_build_items_by_module[$route_module_name] = [];
            }

            $route_build_items_by_module[$route_module_name][] = $route_build;

            $route_backend_map = self::buildBackendMap($route_build);
            $route_frontend_map = self::buildFrontendMap($route_build);

            // mescla por path e método: antes um array_merge por path fazia um módulo apagar os
            // outros métodos do mesmo path, e rotas duplicadas eram sobrescritas em silêncio
            foreach( $route_build as $path => $types ){
                foreach( $types as $type => $item ){
                    if ( isset($response['raw'][$path][$type]) ){
                        self::$warnings[] = "Rota duplicada: $type $path (módulos " . ($response['raw'][$path][$type]['module'] ?? '?') . " e " . ($item['module'] ?? '?') . "); vale a do último";
                    }

                    $response['raw'][$path][$type] = $item;
                }
            }

            $response['backend'] = array_merge($response['backend'], $route_backend_map);
            $response['frontend'] = array_merge($response['frontend'], $route_frontend_map->items);
            $response['frontend_imports'] = array_merge($response['frontend_imports'], $route_frontend_map->imports);
            $response['frontend_elements'] = array_merge($response['frontend_elements'], $route_frontend_map->elements);
        }

        if ( !empty($route_build_items_by_module)){
            $response['module_apis'] = self::buildModuleApis($route_build_items_by_module);
        }

        /**
         * Fallback
         */
        $fallback = Core::config("fallback");
        $response['fallback'] = !empty($fallback) && is_string($fallback)
            ? ltrim($fallback, '\\')
            : 'Wep\\View\\View@handle';

        return Obj::set($response);
    }

    /**
     * raw e backend saem como DADOS (gravados com var_export pelo Update); frontend e
     * module_apis como texto JS.
     */
    private static function buildFileContents($buildMaps)
    {
        // FRONT
        $front = [self::getEditWarning()];
        $front[] = join("\n", array_unique($buildMaps->frontend_imports));
        $front[] = '';
        $front[] = 'export default function RouteMaps(){';
        $front[] = join("\n", $buildMaps->frontend_elements);
        $front[] = '';
        $front[] = "\treturn (";
        $front[] = "\t\t<>";
        $front[] = join("\n", $buildMaps->frontend);
        $front[] = "\t\t</>";
        $front[] = "\t)";
        $front[] = "}";
        $front[] = '';

        return Obj::set([
            'raw' => $buildMaps->raw,
            'frontend' => join("\n", $front),
            'backend' => [
                'middlewares' => self::getMiddlewaresSets(),
                'routes' => $buildMaps->backend,
                'fallback' => $buildMaps->fallback,
            ],
            'module_apis' => $buildMaps->module_apis
        ]);
    }

    /** @var string[] avisos do último build (ex.: rotas duplicadas) */
    private static array $warnings = [];

    /**
     * @return string[]
     */
    public static function warnings(): array
    {
        return self::$warnings;
    }

    /**
     * Registra um aviso do build (exibido como ALERTA no php app update).
     */
    public static function warn(string $message): void
    {
        self::$warnings[] = $message;
    }

    private static function buildMap($route)
    {
        $route = Obj::set($route);

        $response = [];

        $prefix_path = $route->path ?? "/";
        $groups = $route->groups ?? [];
        $namespace = $route->namespace;
        $namespace_parts = explode("\\", $namespace);
        $module_name_parts = array_slice($namespace_parts, 2);
        $module_name_last = end($module_name_parts);
        $module_name = count($module_name_parts) > 1 ? join("/", $module_name_parts) : $module_name_parts[0];
        $is_vendor_module = in_array("Vendor", $namespace_parts);
        $module_name = $is_vendor_module ? $module_name_last : $module_name;
        $route_middlewares = $route->use_middlewares ?? [];

        $parent_view = $route->view ?? Obj::set();
        $parent_view_placeholder = self::getViewPlaceholder($parent_view);
        $parent_view_template = self::getViewTemplate(($route->view['template'] ?? null), $namespace);
        $route_controller = $route->controller ?? null;

        foreach( $groups as $item ){

            $item = Obj::set($item);

            $api = $item->method ?? false;
            
            $vendor_path = $is_vendor_module ? '/vendor' : '';
            $api_path = $api ? '/api' : '';
            $path = $vendor_path. $prefix_path .  $api_path . ($item->path ?? "");
            $path = str_replace('//', '/', $path);
            $path = mb_substr($path, -1) == "/" ? $path : ($path . "/");

            if ( str_starts_with($path, "/api") ){

                if ( in_array($path, ['/api/get/', '/api/create/', '/api/update/', '/api/delete/']) ){
                    $path = '/' . str_replace("-", "/", Str::camelToKebabCase($module_name)) . $path;
                
                } else {
                    // Adiciona "/api" antes do ultimos items
                    $path_parts = explode("/", $item->path);
                    $offset = count($path_parts) - 1;
                    array_splice($path_parts, $offset, 0, "api");
                    $path = join("/", $path_parts);
                }
            }

            $item->main_path = $path;

            $type = self::getType($item);
            
            $title = $item->title ?? '';
            $view = self::getView($item, $module_name, $namespace);

            $controller = self::getController($item, $namespace, $module_name_last, $route_controller);

            $custom = $item->custom ?? [];

            // Middlewares
            $item_middlewares = $item->use_middlewares ?? [];
            $middlewares = self::getMiddlewares($item_middlewares, $route_middlewares);

            // SETS
            if ( !isset($response[$path]) ){
                $response[$path] = [];
            }

            $module_title = $route->module_title ?? "";
            $module_title = $module_title ?: $module_name_last;

            $response_item = [
                'module' => $module_name,
                'module_title' => $module_title,
                'module_last' => $module_name_last,
                'path' => $path,
                'title' => $title,
                'type' => $type,
                'method' => $api,
                'custom' => $custom,
                'resource' => $item->resource ?? null,
                'namespace' => $namespace,
                'middlewares' => $middlewares,
                'view' => $view->main,
                'view_template' => $api ? false : ($view->template ?? $parent_view_template),
                'vendor' => $is_vendor_module,
                'view_placeholder' => isset($view->placeholder) && $view->placeholder 
                    ? $view->placeholder
                    : $parent_view_placeholder,
                'controller' => $controller
            ];

            /**
             * Executa Hook each_route
             */

            $response_item = BuilderHooks::eachRoute($response_item, [
                'path' => $path,
                'type' => $type,
                'raw' => $item,
                'module' => $route
            ]);

            //echo json_encode($response_item) . PHP_EOL;
            //echo "--------------" . PHP_EOL;
            //echo "".PHP_EOL;

            $response[$path][$type] = $response_item;
        }

        return $response;
    }

    private static function buildBackendMap($map)
    {
        $result = [];

        foreach( $map as $item ){

            foreach( $item as $route ){
                $route = Obj::set($route);

                // dados (o RouteRegistrar registra): [método, path, controller, middlewares]
                $result[] = [
                    strtolower($route->type),
                    (string) $route->path,
                    (string) $route->controller,
                    array_values($route->middlewares ?? [])
                ];
            }
        }

        return $result;
    }

    private static function buildModuleApis($routesByModules=[])
    {
        $alert = self::getEditWarning();

        $module_methods = [];
        $module_declarations = [];

       //echo json_encode($routesByModules) . PHP_EOL;
       //echo "----------------" . PHP_EOL;

        foreach( $routesByModules as $moduleName => $items ){

            $module_name = $moduleName;
            $module_routes = [];
            $module_vendor_routes = [];

            foreach( $items as $methods ){
                
                foreach( $methods as $item ){

                    foreach( $item as $route ){

                        $route = Obj::set($route);

                        $method = $route->method ?? null;
    
                        if ( !$method ) continue;
    
                        $type = strtolower($route->type);
                        $path = $route->path;
                        $resource = $route->resource ?? null;
                        $custom = $route->custom ?? [];
    
                        if ( $resource ){

                            if ( !isset($module_vendor_routes[$resource]) ){
                                $module_vendor_routes[$resource] = [];
                            }

                            $module_vendor_routes[$resource][$method] = [
                                'type' => $type,
                                'method' => $method,
                                'path' => $path,
                                'resource' => $resource,
                                'custom' => $custom
                            ];

                            continue;
                        }

                        $module_routes[$method] = [
                            'type' => $type,
                            'method' => $method,
                            'path' => $path,
                            'resource' => $resource,
                            'custom' => $custom
                        ];
                    }
                }
            }

            $module_routes = empty($module_vendor_routes) ? $module_routes : $module_vendor_routes;

            if ( empty($module_routes) ) continue;

            $module_routes = json_encode((object)$module_routes);
            $module_name = BuildFile::identifier(str_replace("\\", "", $module_name), 'Module');
            $module_method = $module_name . "Api";

            $module_declarations[] = 'const '.$module_method." = App.apiFactory($module_routes);";
            $module_methods[] = $module_method;
        }

        //return $result;
        return join("\n", [
            $alert,
            'import App from "app";',
            "\n",
            join("\n", $module_declarations),
            "\n",
            'export {'.join(", ", $module_methods) . '};'
        ]);
    }    

    private static function buildFrontendMap($map)
    {
        $result = Obj::set([
            'imports' => [
                'import React, { lazy } from "react";',
                'import {Route} from "react-router";'
            ],
            'items' => [],
            'elements' => []
        ]);

        if ( empty($map) ) return $result;

        $elements = [];
        $routes = [];

        foreach( $map as $index => $item ){
           
            foreach( $item as $key => $route ){
                $route = Obj::set($route);
                $path = $route->path;
                $view = $route->view;

                if ( !$view ) continue;

                $custom = $route->custom
                    ? json_encode($route->custom)
                    : '{}';

                $view_placeholder = $route->view_placeholder ?? Obj::set();
                $view_placeholder_path = $view_placeholder->path ?? null;
                $view_placeholder_name = $view_placeholder->name ?? 'null';

                if ( $view_placeholder_path && !in_array($view_placeholder_path, $result->imports) ){
                    $result->imports[] = "import " . BuildFile::identifier((string) $view_placeholder_name, 'Placeholder') . " from " . BuildFile::js($view_placeholder_path) . ";";
                }

                //$is_custom_view_template = $route->view_template != APP_TEMPLATES_NAMESPACE . 'Main';
                //$title = $route->title;
                $path = str_replace(["}/", "/{"], ["/", "/:"], $path);
              
                $view = str_replace(["}", "{"], ["", ""], $view);
                $view = str_replace("//", "/", $view);
                $element_name = explode("/", $view);
                $element_name = array_map('ucfirst', $element_name);
                $element_name = join("", $element_name);
                $element_name = explode(".", $element_name)[0];
                $element_name = BuildFile::identifier(str_replace("\\", "", $element_name), 'View');

                $element_declaration = "\tconst $element_name = lazy(() => import(" . BuildFile::js($view) . "));";
              
                if ( in_array($element_declaration, $elements) ) continue;

                $elements[] = $element_declaration;
                $routes[] = "\t\t\t<Route key=" . BuildFile::js($element_name) . " path={" . BuildFile::js($path) . "} element={<$element_name />} />";

                //echo $element_name. PHP_EOL;

               // $result->items[] = "{title:'$title',path:'$path',custom:$custom,handler:{component:{main:()=>import(`$view`),placeholder:". $view_placeholder_name."},revalidate:".($is_custom_view_template ? 'true' : 'false')."}}";
            }
        }


        $result->elements = $elements;
        $result->items = $routes;

        return $result;
    }      

    private static function getType($route)
    {
        $raw_type = $route->type ?? null;

        /**
         * "type" => "post",
         * "method" => "sync"
         */
        if ( isset($route->method) ){

            return $raw_type 
                ? self::$type_aliases[strtolower($raw_type)] 
                //: self::$type_aliases[strtolower($route->api ?? 'post')];
                : 'POST';
        }

        return $raw_type 
            ?  (self::$type_aliases[strtolower($raw_type)] ?: strtoupper($raw_type))
            : "GET";
    }

    private static function getMiddlewares($route, $global)
    {
        $middlewares = $global;

        foreach($route as $key){

            $is_remove = $key[0] == '-';
            
            if ( $is_remove ){

                $key_to_remove = ltrim($key, '-'); 
                $index_to_remove = array_search($key_to_remove, $middlewares);
                unset($middlewares[$index_to_remove]);
                continue;
            }

            $middlewares[] = $key;
        }

        return $middlewares;
    }

    private static function getController($route, $namespace, $moduleName, $route_controller=null)
    {
        $controller = null;

        if ( isset($route->controller) ) {
            $controller = $route->controller;
        }

        if ( $route_controller ){
            $controller = $route_controller;
        }

        if ( $controller ){

            if ( !str_contains($controller, "App\\") ){
                $controller = "$namespace\\$controller";
            }

            if ( !str_contains($controller, "@") ){
                $method_name = $route->method ?? "handle";
                $controller = "$controller@$method_name";
            }

            return $controller;
        }

        if ( isset($route->method) ){
            $default_api_controller = "$namespace\\$moduleName" . "Api@moduleApiControllerHandle";
            return $default_api_controller;
        }

        return DEFAULT_VIEW_CONTROLLER;
    }

    private static function getView($route, $module_name, $module_namespace)
    {
        $response = Obj::set([
            'main' => false
        ]);

        if ( isset($route->method) ) return $response;

       //echo 'module_name: ' . $module_name . PHP_EOL;
       //echo 'module_namespace: ' . $module_namespace . PHP_EOL;
       //echo '----------------------' . PHP_EOL;
       //echo json_encode($route) . PHP_EOL;

        $view = $route->view ?? null;
        $modules_path = '/src/' . explode("/src/", DIR_MODULES)[1];
        $path = $modules_path . '/' . $module_name . '/view';
        //$path = str_replace("//", "/", $path);

        $response->main = $path . '/index.js';

        if ( $view ){

            $view = Obj::set($view);

            if ( !isset($view->main) ){
                $view = Obj::set([
                    'main' => $view
                ]);
            }

            if ( str_starts_with($view->main, "Modules/") ){
                $path = '/src/';
            }

            if ( str_starts_with($view->main, "src/") ){
                $path = '';
            }

            $has_extension = str_contains($view->main, ".js");
            $filename = $path . '/' . $view->main . ($has_extension ? "" : ".js");
            
            $response->main = $filename;
            $response->placeholder = self::getViewPlaceholder($view);

            if ( isset($view->template) ){
                $response->template = self::getViewTemplate($view->template, $module_namespace);
            }
        }

       // echo json_encode($response) . PHP_EOL;
        
        return $response;
    }

    private static function getViewPlaceholder($view=[])
    {
        $view = Obj::set($view);

        $raw_path = $view->placeholder ?? '';

        if ( !$raw_path ) return $raw_path ;

        // 'ui/templates/main/placeholder'
        $aliases = [
            'ui' => DIR_COMMON . '/view/ui',
            'modules' => DIR_MODULES,
            'app' => DIR_COMMON . '/view/app'
        ];
        
        $path_parts = explode('/', $raw_path);
        $first = strtolower($path_parts[0] ?? '');
        $first_has_slash = $first[0] == '/';
        $first_without_slash = $first_has_slash ? str_replace('/', '', $first) : $first;

        $real_path = $raw_path;

        $path_aliase = $aliases[$first_without_slash] ?? null;

        if ( $path_aliase ){
            $key = $first_has_slash ? '/'.$first_without_slash : $first;
            $real_path = str_replace($key, $path_aliase, $real_path);
        }

        $has_not_js_extension = !str_contains($real_path, ".js");

        if ( $has_not_js_extension ){
            $real_path .= '.js';
        }

        $method_name = '';

        if ( file_exists($real_path) ){
            $content = file_get_contents($real_path) ?? '';
            $content_lines = explode('export', $content);
            $export_line = explode("  ", $content_lines[1] ?? '')[0] ?? '';
            $export_line = preg_replace('/[^A-Za-z0-9\-]/', ' ', $export_line);
            
            $removes = ['export', 'default', 'function', ''];
            $method_name = str_replace($removes, '', $export_line) ?? '';
            $method_name = trim($method_name);

            $real_path = '/src/' . explode('/src/', $real_path)[1] ?? $real_path;
        }

        return Obj::set([
            'path' => $real_path,
            'name' => $method_name
        ]);
    }

    private static function getViewTemplate($viewTemplate, $namespace)
    {
        $template = APP_TEMPLATES_NAMESPACE . 'Main';

        if ( !$viewTemplate ) return $template;

        $view_has_namespace = str_contains($viewTemplate, 'App\\');

        if ( $view_has_namespace ) return $viewTemplate;

        // Pega template da pasta Templates do módulo
        return $namespace . '\\Templates\\' . $viewTemplate;
    }

    /**
     * Aliases de middleware (módulos + app) => classe.
     *
     * @return array<string, string>
     */
    private static function getMiddlewaresSets()
    {
        // "active" precisa vir no filtro (antes não vinha, então módulos desativados
        // continuavam registrando middlewares)
        $modules_configs = Module::getConfigs(':middlewares|namespace|active');
        $module_middlewares = [];

        foreach( $modules_configs as $module ){

            if ( ($module['active'] ?? true) === false ) continue;

            $middlewares_items = $module['middlewares'] ?? [];
            $module_namespace = $module['namespace'] ?? '';

            if ( empty($middlewares_items) ) continue;

            foreach( $middlewares_items as $key => $class ){

                $has_namespace = str_contains($class, 'App\\');

                $module_middlewares[$key] = $has_namespace
                    ? $class
                    : $module_namespace . '\\Middlewares\\'.$class;
            }
        }

        $app_middlewares = Core::config('middlewares') ?? [];

        $middlewares = [];

        foreach( array_merge($module_middlewares, $app_middlewares) as $key => $class ){
            // aceita "Classe", "\Classe" e "Classe::class" (formato antigo da config)
            $middlewares[(string) $key] = ltrim(preg_replace('/::class$/', '', (string) $class), '\\');
        }

        return $middlewares;
    }

    private static function getEditWarning()
    {
       return Warning::get();
    }
}