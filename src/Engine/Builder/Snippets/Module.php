<?php

namespace App\Core\Engine\Builder\Snippets;

use App\Core\File;
use App\Core\Obj;
use Exception;

class Module
{
    private static $schemas = [
        'regular' => [
            'config/module.php' => 'regularConfig',
            '{module_name}.php' => 'regularModel',
            '{module_name}Api.php' => 'regularApi',
            'Services/' => 'dir',
            'Middlewares/' => 'dir',
            'view/index.js' => 'viewIndex'
        ],
        'vendor' => [
            'config/module.php' => 'vendorConfig',
            '{module_name}.php' => 'vendorModel',
            '{module_name}Api.php' => 'vendorApi',
            'Services/' => 'services'
        ]
    ];

    private static $option_aliases = [
        '-view' => [
            'schema' => 'regular',
            'file' => 'view/index.js',
            'operation' => 'skip'
        ]
    ];

    /**
     * @param string $name
     * 
     * @todo transformar esse método em uma classe separada exclusiva para commando
     */
    public static function create($props=[])
    {
        $name = $props['params'] ?? null;

        if ( !$name ) throw new Exception('Nome do módulo invalido');

        // tranforma Vendor\Module | vendor/module
        // em Vendor Module
        $name = str_replace(['/', '\\'], '__', $name);
        $name_parts = explode('__', $name);
        $name_parts = array_map('ucfirst', $name_parts);

        // sets
        $is_vendor = $name_parts[0] === 'Vendor';
        $module_name = end($name_parts);
        $module_dir = DIR_MODULES . '/' . join('/', $name_parts);
        $module_namespace = APP_MODULES_NAMESPACE . join('\\', $name_parts);
        $options = $props['options'] ?? [];

        /*echo json_encode([
            'name' => $name,
            'is_vendor' => $is_vendor,
            'module_name' => $module_name,
            'module_dir' => $module_dir,
            'module_namespace' => $module_namespace,
            'options' => $options
        ]);*/

        $module_type = $is_vendor ? 'vendor' : 'regular';
        $module_schema = self::$schemas[$module_type];

        // Lida com options
        if ( !empty($options) ){
    
            foreach( $options as $item ){

                $option = self::$option_aliases[$item];

                $is_not_same_type = $option['schema'] !== $module_type;

                if ( $is_not_same_type ) continue;

                if ( $option['operation'] == 'skip' ){
                    unset($module_schema[$option['file']]);
                }
            }
        }

        foreach( $module_schema as $filename => $method ){
            $filename_parsed = str_replace('{module_name}', $module_name, $filename);
            $filename = $module_dir . '/' . $filename_parsed;

            $is_file_already_created = file_exists($filename) && !File::isEmpty($filename);

            if ( $is_file_already_created ) continue;

            $content = call_user_func_array([static::class, $method], [
                [
                    'module_name' => $module_name
                ]
            ]);

            File::put($filename, $content);
        }

        return Obj::set([
            'error' => false,
            'message' => "Módulo '$module_name' criado!"
        ]);
    }

    private static function regularConfig($props)
    {
        return join("\r", [
            '<?php',
            self::editAlert(),
            "return [",
            "    'db' => [",
            "        'table' => '',", 
            "    ],",
            "    'middlewares' => [],",
            "    'constants' => [],",
            "    'routes' => [",
            "        'path' => '',",
            "        'api' => ['get', 'create', 'update', 'delete'],",
            "        'groups' => [",
            "            [",
            "                'path' => '/',",
            "                'view' => 'index.js'",
            "            ]",
            "        ]",
            "    ]",
            "];"
        ]);
    }

    private static function regularModel($props)
    {
        $module_name = $props['module_name'];

        return join("\r", [
            '<?php',
            ' ',
            'namespace App\Modules\\'.$module_name.';',
            ' ',
            'use App\Core\Model;',
            ' ',
            '/**',
            ' * Classe model responsável por implementar métodos',
            ' * com operações de banco de dados.',
            ' * Métodos implícitos: get, create, update, delete',
            ' **/',
            'class '.$module_name,
            '{',
            '   use Model;',
            '}'
        ]);
    }

    private static function regularApi($props)
    {
        $module_name = $props['module_name'];

        return join("\r", [
            '<?php',
            ' ',
            "namespace App\Modules\\".$module_name.";",
            ' ',
            'use App\Core\Controller;',
            ' ',
            '/**',
            ' * Classe controller responsável por implementar métodos',
            ' * que lidam com as requisições vindas do front',
            ' */',
            "class ".$module_name."Api",
            '{',
            '    use Controller;',
            ' ',
            '}'
        ]);
    }

    private static function dir($props)
    {
        return null;
    }

    private static function viewIndex($props)
    {
        $module_name = $props['module_name'];

        return join("\r", [
            "export default function ".$module_name. "(){",
            " ",
            "}"
        ]);
    }

    private static function vendorConfig($props)
    {
        return join("\r", [
            "<?php",
            " ",
            "return [",
            "    'endpoints' => [",
            " ",
            "    ],",
            "    'hooks' => [",
            "        'beforeRequest' => [],",
            "        'afterRequest' => [],",
            "        'onError' => [],",
            "        'onSuccess' => []",
            "    ]",
            "];"
        ]);
    }

    private static function vendorModel($props)
    {
        $module_name = $props['module_name'];

        return join("\r", [
            "<?php",
            " ",
            "namespace App\Modules\Vendor\\$module_name;",
            " ",
            "use App\Core\Vendor;",
            " ",
            "/**",
            " * Classe model responsável por implementar",
            " * métodos de cada endpoint",
            " */",
            "class $module_name",
            "{",
            "    use Vendor;",
            " ",
            "}",
        ]);
    }

    private static function vendorApi($props)
    {
        $module_name = $props['module_name'];

        return join("\r", [
            "<?php",
            " ",
            "namespace App\Modules\Vendor\\$module_name;",
            " ",
            "use App\Core\Controller;",
            " ",
            "/**",
            " * Classe controller responsável por implementar métodos",
            " * que lidam com as requisições vindas do front",
            " */",
            "class ".$module_name."Api",
            "{",
            "    use Controller;",
            " ",
            "}"
        ]);
    }

    private static function editAlert()
    {
        return join("\r", [
        " ",
        "/**",
        " * -----------------------------------------------",
        " *  ATENÇÃO: sempre que atualizar esse arquivo,",
        " *  execute o comando abaixo no terminal:",
        " *  >>> php app update",
        " * -----------------------------------------------",
        " */",
        " ",
        ]);
    }

}