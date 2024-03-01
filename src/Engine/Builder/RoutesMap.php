<?php

namespace App\Core\Engine\Builder;

use App\Core\Core;
use App\Core\Obj;

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

        $build_maps = self::handleBuildMaps($modules_routes);

        if ( $raw ) return $build_maps;
        
        return self::buildFileContents($build_maps);
    }

    private static function buildMap($route)
    {
        $response = [];

        $prefix_path = $route->path ?? "/";
        $groups = $route->groups ?? [];
        $namespace = $route->namespace;
        $namespace_parts = explode("\\", $namespace);
        $module_name = end($namespace_parts);

        $parent_view = $route->view ?? Obj::set();
        $parent_view_placeholder = self::getViewPlaceholder($parent_view);

        foreach( $groups as $item ){

            $item = Obj::set($item);
            
            $path = $prefix_path . ($item->path ?? "");
            $path = mb_substr($path, -1) == "/" ? $path : ($path . "/");

            $type = self::getType($item);
            
            $view = self::getView($item, $module_name);

            $controller = self::getController($item, $namespace, $module_name);

            // SETS
            if ( !isset($response[$path]) ){
                $response[$path] = [];
            }

            $response[$path][$type] = [
                "path" => $path,
                "type" => $type,
                "namespace" => $namespace,
                "view" => $view->main,
                'view_placeholder' => isset($view->placeholder) && $view->placeholder 
                    ? $view->placeholder
                    : $parent_view_placeholder,
                "controller" => $controller
           ];
        }

        return $response;
    }

    private static function handleBuildMaps($routes)
    {
        $response = [
            'raw' => [],
            'backend' => [],
            'frontend' => [],
            'frontend_imports' => []
        ];

        foreach( $routes as $route ){

            $route_build = self::buildMap($route);
            $route_backend_map = self::buildBackendMap($route_build);
            $route_frontend_map = self::buildFrontendMap($route_build);

            $response['raw'] = array_merge($response['raw'], $route_build);
            $response['backend'] = array_merge($response['backend'], $route_backend_map);
            $response['frontend'] = array_merge($response['frontend'], $route_frontend_map->items);
            $response['frontend_imports'] = array_merge($response['frontend_imports'], $route_frontend_map->imports);
        }

        $error = Core::config("error") ?? [];
        $not_found = "/erro/pagina-nao-encontrada";

        if ( !empty($error) ){
            $not_found = $error["404"] ?? $not_found;
        }

        $response['backend'][] = 'Route::fallback(fn() => header("Location: '.$not_found.'"));';

        return Obj::set($response);
    }

    private static function buildFileContents($buildMaps)
    {

        $alert = self::getAlert();

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
            'backend' => $back
        ]);
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
    
                $result[] = "Route::$method('$path', '$controller');";
            }
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

                $view_placeholder = $route->view_placeholder ?? Obj::set();
                $view_placeholder_path = $view_placeholder->path ?? null;
                $view_placeholder_name = $view_placeholder->name ?? 'null';

                if ( $view_placeholder_path && !in_array($view_placeholder_path, $result->imports) ){
                    $result->imports[] = "import $view_placeholder_name from '$view_placeholder_path';";
                }

                $result->items[] = "{path:'$path',handler:{ component:{ main:()=>import(`$view`),placeholder:". $view_placeholder_name."}}}";
            }
        }

        return $result;
    }      

    private static function getType($route)
    {
        $raw_type = $route->type ?? null;

        /**
         * "type" => "post",
         * "api" => "sync"
         */
        if ( isset($route->api) ){
            return $raw_type 
                ? self::$type_aliases[strtolower($raw_type)] 
                : self::$type_aliases[strtolower($route->api)];
        }

        return $raw_type 
            ?  (self::$type_aliases[strtolower($raw_type)] ?: strtoupper($raw_type))
            : "GET";
    }

    private static function getController($route, $namespace, $moduleName)
    {
        if ( isset($route->controller) ) return $route->controller;

        if ( isset($route->api) ){
            $default_api_controller = "$namespace\\$moduleName" . "Api@handle";
            return $default_api_controller;
        }

        return DEFAULT_VIEW_CONTROLLER;
    }

    private static function getView($route, $module_name)
    {
        $response = Obj::set([
            'main' => false
        ]);

        if ( isset($route->api) ) return $response;

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

    public static function getModulesRoutes()
    {
        $routes_list = [];

        /** @todo use File */
        $modules_config_filenames = glob(DIR_MODULES . "/*/config/module.php");

        foreach($modules_config_filenames as $filename){
            
            $parts = explode("Modules/", $filename);
            $item = $parts[1] ?? "";
            $module_name = explode("/", $item)[0] ?? "";
            $module_namespace = APP_MODULES_NAMESPACE . $module_name;

            $module_config = include_once($filename) ?? [];
            $module_config = Obj::set($module_config);

            $module_routes = Obj::set($module_config->routes ?? []);
            $module_routes->namespace = $module_routes->namespace ?? $module_namespace;

            $routes_list[] = $module_routes;
        }

        // Separa rotas agrupadas na prop api
        // "api" => ["update", "delete"]
        $routes_splited = []; 

        foreach( $routes_list as $routes ){

            $groups = $routes->groups;
            $new_routes = $routes;
            $new_routes->groups = [];

            foreach( $groups as $item ){

                $api = $item['api'] ?? null;
                $is_api_shortcut = $api && is_array($api);

                if ( $is_api_shortcut ){
                    
                    $new_item = $item;
                    $new_item['view'] = false;

                    foreach( $api as $method ){
                        $new_item['api'] = $method;
                        $new_routes->groups[] = $new_item;
                    }

                    unset($item['api']);
                    $new_routes->groups[] = $item;

                } else {
                    $new_routes->groups[] = $item;
                }
            }

            $routes_splited[] = $new_routes;
        }

        return $routes_splited;
    }

    private static function getAlert()
    {
        return  '
/**
 * -------------------- CUIDADO -----------------------
 *   Arquivo gerado automaticamente. NÃO o modifique 
 *   ou o funcionamento da aplicação será comprometido
 *   Build: '.date("d/m/Y h:i:s").'
 * ----------------------------------------------------
 */    
        ';
    }
}