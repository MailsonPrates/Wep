<?php

namespace App\Core\Engine\Builder;

use App\Core\Core;
use App\Core\Engine\Builder\Warning;
use App\Core\Obj;
use App\Core\Str;
use App\Core\Engine\Builder\Vendor\Vendor;
use App\Core\Engine\Module;

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

        /**
         * @todo usar Module::getConfigs()
         */
        $modules_config_filenames = glob(DIR_MODULES . "/*/config/module.php");

        foreach($modules_config_filenames as $filename){
            
            $parts = explode("Modules/", $filename);
            $item = $parts[1] ?? "";
            $module_name = explode("/", $item)[0] ?? "";
            $module_namespace = APP_MODULES_NAMESPACE . $module_name;

            $module_config = include_once($filename) ?? [];

            $active = $module_config['active'] ?? true;
            
            if ( $active === false ) continue;

            $module_routes = $module_config['routes'] ?? [];
            $module_routes['namespace'] = $module_routes['namespace'] ?? $module_namespace;
            $module_routes['module_name'] = $module_name;
            $module_routes['groups'] = $module_routes['groups'] ?? [];

            $routes_list[] = $module_routes;
        }

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

        return $routes_splited;
    }    

    private static function handleBuildMaps($routes)
    {
        $response = [
            'raw' => [],
            'backend' => [],
            'frontend' => [],
            'frontend_imports' => [],
            'module_apis' => []
        ];

        $route_build_items_by_module = [];

        foreach( $routes as $route ){

            $route_build = self::buildMap($route);
            $route_module_name = $route['module_name'];

            if ( !isset($route_build_items_by_module[$route_module_name]) ){
                $route_build_items_by_module[$route_module_name] = [];
            }

            $route_build_items_by_module[$route_module_name][] = $route_build;

            $route_backend_map = self::buildBackendMap($route_build);
            $route_frontend_map = self::buildFrontendMap($route_build);

            $response['raw'] = array_merge($response['raw'], $route_build);
            $response['backend'] = array_merge($response['backend'], $route_backend_map);
            $response['frontend'] = array_merge($response['frontend'], $route_frontend_map->items);
            $response['frontend_imports'] = array_merge($response['frontend_imports'], $route_frontend_map->imports);
        }

        if ( !empty($route_build_items_by_module)){
            $response['module_apis'] = self::buildModuleApis($route_build_items_by_module);
        }

        /**
         * Fallback
         */
        $fallback_class = 'App\Core\View\View';
        $fallback_method = 'handle';
        $fallback = Core::config("fallback") ?? '';

        if ( !empty($fallback) && is_string($fallback) ){
            [$fallback_class, $fallback_method] = explode('@', $fallback);
        }

        $response['backend'][] = 'Route::fallback(fn($req) => (new \\'.$fallback_class.' )->'.$fallback_method.'($req));';

        return Obj::set($response);
    }

    private static function buildFileContents($buildMaps)
    {
        $alert = self::getEditWarning();

        // FRONT
        $imports = array_unique($buildMaps->frontend_imports);

        $front = [$alert];
        $front[] = join("\r", $imports);
        $front[] = "\r";
        $front[] = 'const RouteMaps = [';
        $front[] = join(",\r", $buildMaps->frontend);
        $front[] = '];';
        $front[] = 'export default RouteMaps;';
        $front = join("\r", $front);

        // BACK
        $back = ['<?php'];
        $back[] = "use App\Core\Router\Route;";
        $back[] = $alert;
        $back[] = self::getMiddlewaresSets();
        $back[] = join("\r", $buildMaps->backend);
        $back = join("\r", $back);

        // RAW
        $raw = ['<?php'];
        $raw[] = $alert;
        $raw[] = "return";
        $raw[] = var_export($buildMaps->raw, true) . ";";
        $raw = join("\r", $raw);

        return Obj::set([
            'raw' => $raw,
            'frontend' => $front,
            'backend' => $back,
            'module_apis' => $buildMaps->module_apis
        ]);
    }

    private static function buildMap($route)
    {
        $route = Obj::set($route);

        $response = [];

        $prefix_path = $route->path ?? "/";
        $groups = $route->groups ?? [];
        $namespace = $route->namespace;
        $namespace_parts = explode("\\", $namespace);
        $module_name = end($namespace_parts);
        $is_vendor_module = in_array("Vendor", $namespace_parts);
        $route_middlewares = $route->use_middlewares ?? [];

        $parent_view = $route->view ?? Obj::set();
        $parent_view_placeholder = self::getViewPlaceholder($parent_view);

        foreach( $groups as $item ){

            $item = Obj::set($item);

            $api = $item->method ?? false;
            
            $vendor_path = $is_vendor_module ? '/vendor' : '';
            $api_path = $api ? '/api' : '';
            $path = $vendor_path. $prefix_path .  $api_path . ($item->path ?? "");
            $path = str_replace('//', '/', $path);
            $path = mb_substr($path, -1) == "/" ? $path : ($path . "/");

            $type = self::getType($item);
            
            $view = self::getView($item, $module_name);

            /**
             * @todo implementar o uso de template diferente para a rota ou grupo de rotas
             * 
             * module config:
             * view => ["template" => 'Main']
             */

            $controller = self::getController($item, $namespace, $module_name);

            $custom = $item->custom ?? [];

            // Middlewares
            $item_middlewares = $item->use_middlewares ?? [];
            $middlewares = self::getMiddlewares($item_middlewares, $route_middlewares);

            // SETS
            if ( !isset($response[$path]) ){
                $response[$path] = [];
            }

            $response_item = [
                'module' => $module_name,
                'path' => $path,
                'type' => $type,
                'method' => $api,
                'custom' => $custom,
                'resource' => $item->resource ?? null,
                'namespace' => $namespace,
                'middlewares' => $middlewares,
                'view' => $view->main,
                'vendor' => $is_vendor_module,
                'view_placeholder' => isset($view->placeholder) && $view->placeholder 
                    ? $view->placeholder
                    : $parent_view_placeholder,
                'controller' => $controller
            ];

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
                $method = strtolower($route->type);
                $path = $route->path;
                $controller = $route->controller;
                $middlewares = $route->middlewares ?? [];
    
                $define = "Route::$method('$path', '$controller')";

                if ( !empty($middlewares) ){
                    $use = array_map(fn($a) => "'$a'", $middlewares);
                    $define .= '->middleware(['.join(',', $use).'])';
                }

                $define .= ';';

                $result[] = $define;
            }
        }

        return $result;
    }    

    private static function buildModuleApis($routesByModules=[])
    {
        $alert = self::getEditWarning();

        $result = [];

        foreach( $routesByModules as $moduleName => $items ){

            $module_name = $moduleName;
            $module_routes = [];

            foreach( $items as $methods ){
                
                foreach( $methods as $item ){

                    foreach( $item as $route ){

                        $route = Obj::set($route);

                        $method = $route->method ?? null;
    
                        if ( !$method ) continue;
    
                        $type = strtolower($route->type);
                        $path = $route->path;
                        $resource = $route->resource ?? null;
    
                        $module_routes[] = [
                            'type' => $type,
                            'method' => $method,
                            'path' => $path,
                            'resource' => $resource
                        ];
                    }
                }
            }

            $module_routes = json_encode($module_routes);
            $module_method = $module_name . "Api";

            $module_filename = Str::camelToKebabCase($module_name) . '.js';

            $result[$module_name] = [
                'content' => join("\r", [
                    $alert,
                    'import {apiFactory} from "core";',
                    'const '.$module_method." = apiFactory({routes:$module_routes});",
                    'export default '.$module_method . ';'
                ]),
                'filename' => $module_filename
            ];
        }

        return $result;
    }    

    private static function buildFrontendMap($map)
    {
        $result = Obj::set([
            'imports' => [],
            'items' => []
        ]);

        foreach( $map as $item ){
           
            foreach( $item as $route ){
                $route = Obj::set($route);
                $path = $route->path;
                $view = $route->view;
          
                if ( !$view ) continue;

                $custom = $route->custom
                    ? json_encode( $route->custom)
                    : '{}';

                $view_placeholder = $route->view_placeholder ?? Obj::set();
                $view_placeholder_path = $view_placeholder->path ?? null;
                $view_placeholder_name = $view_placeholder->name ?? 'null';

                if ( $view_placeholder_path && !in_array($view_placeholder_path, $result->imports) ){
                    $result->imports[] = "import $view_placeholder_name from '$view_placeholder_path';";
                }

                $result->items[] = "{path:'$path',custom:$custom,handler:{component:{main:()=>import(`$view`),placeholder:". $view_placeholder_name."}}}";
            }
        }

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

    private static function getController($route, $namespace, $moduleName)
    {
        if ( isset($route->controller) ) return $route->controller;

        if ( isset($route->method) ){
            $default_api_controller = "$namespace\\$moduleName" . "Api@moduleApiControllerHandle";
            return $default_api_controller;
        }

        return DEFAULT_VIEW_CONTROLLER;
    }

    private static function getView($route, $module_name)
    {
        $response = Obj::set([
            'main' => false
        ]);

        if ( isset($route->method) ) return $response;

        $view = $route->view ?? null;
        $modules_path = '/src/' . explode("/src/", DIR_MODULES)[1];
        $path = $modules_path . '/' . $module_name . '/view';

        $response->main = $path . '/index.js';

        if ( $view ){

            $view = Obj::set($view);

            if ( !isset($view->main) ){
                $view = Obj::set([
                    'main' => $view
                ]);
            }

            $has_extension = str_contains($view->main, ".js");
            $filename = $path . '/' . $view->main . ($has_extension ? "" : ".js");
            
            $response->main = $filename;
            $response->placeholder = self::getViewPlaceholder($view);
        }
        
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

    private static function getMiddlewaresSets()
    {
        $modules_configs = Module::getConfigs(':middlewares|namespace');
        $module_middlewares = [];

        foreach( $modules_configs as $module ){

            $active = $module['active'] ?? true;

            if ( $active === false ) return;

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

        $middlewares_items = array_merge($module_middlewares, $app_middlewares);

        $middlewares = [];
        $classes_flag = [];

        foreach( $middlewares_items as $key => $class ){

            // Add method
            if ( !str_contains($class, '::') ){
                $class .= '::class';
            }   

            // Add '/' inicial
            if ( $class[0] != '\\' ){
                $class = '\\'.$class;
            }

            $classes_flag[] = $class;
            $middlewares[] = "'$key' => $class";
        }

        $content = ['Route::globalMiddlewares(['];
        $content[] = join(",\r", $middlewares);
        $content[] = ']);';
        $content[] = ' ';

        return join("\r", $content);
    }

    private static function getEditWarning()
    {
       return Warning::get();
    }
}