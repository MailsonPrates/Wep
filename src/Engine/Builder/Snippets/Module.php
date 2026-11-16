<?php

namespace Wep\Engine\Builder\Snippets;

use Wep\File;
use Wep\Obj;
use Wep\Str;
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
            'Services/' => 'dir'
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

        // cada parte vira pasta e namespace: só identificadores (antes "../../X" criava
        // arquivos fora de src/Modules)
        foreach( $name_parts as $part ){
            if ( !preg_match('/^[A-Z][A-Za-z0-9]*$/', $part) ){
                throw new Exception("Nome do módulo inválido: \"$part\". Use letras e números, começando por letra (ex.: Pedido, Admin/Pedido)");
            }
        }

        if ( count($name_parts) > 3 ){
            throw new Exception('Nome do módulo inválido: no máximo 3 níveis (ex.: Admin/Vendas/Pedido)');
        }

        // sets
        $is_vendor = $name_parts[0] === 'Vendor';
        $module_name = end($name_parts);
        $module_dir = DIR_MODULES . '/' . join('/', $name_parts);
        $module_namespace = APP_MODULES_NAMESPACE . join('\\', $name_parts);
        $options = $props['options'] ?? [];

       /* echo json_encode([
            'name' => $name,
            'is_vendor' => $is_vendor,
            'module_name' => $module_name,
            'module_dir' => $module_dir,
            'module_namespace' => $module_namespace,
            'options' => $options
        ]) . PHP_EOL;*/

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

        //var_dump($module_schema);

        foreach( $module_schema as $filename => $method ){
            $filename_parsed = str_replace('{module_name}', $module_name, $filename);
            $filename = $module_dir . '/' . $filename_parsed;

            // pastas (Services/, Middlewares/): só cria o diretório
            // (antes file_put_contents num caminho terminado em "/" gerava warnings)
            if ( $method === 'dir' ){
                if ( !is_dir($filename) ) mkdir($filename, 0775, true);
                continue;
            }

            $is_file_already_created = file_exists($filename) && !File::isEmpty($filename);

            if ( $is_file_already_created ) continue;

            $content = call_user_func_array([static::class, $method], [
                [
                    'module_name' => $module_name,
                    'namespace' => $module_namespace,
                    // Admin/PedidoItem → /admin/pedido-item
                    'path' => '/' . join('/', array_map(
                        fn ($part) => Str::camelToKebabCase($part),
                        $name_parts
                    )),
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
        $path = $props['path'] ?? '/';

        return join("\n", [
            '<?php',
            self::editAlert(),
            "return [",
            "    'title' => '" . addslashes($props['module_name'] ?? '') . "',",
            "    'db' => [",
            "        'table' => '',",
            "    ],",
            "    'middlewares' => [],",
            "    // nomes de tabelas usadas com DB::table() ficam aqui (ex.: 'TABLE_PEDIDO_ITEMS' => 'pedido_items')",
            "    'constants' => [],",
            "    'routes' => [",
            "        'path' => '$path',",
            "        // login e permissão do grupo (Admin > Grupo de usuários) em todas as rotas do módulo",
            "        'use_middlewares' => ['isLogged', 'checkPermission'],",
            "        'groups' => [",
            "            [",
            "                'path' => '/',",
            "                'view' => 'index.js'",
            "            ],",
            "            // método do {$props['module_name']}Api.php (chamado no front por {$props['module_name']}Api.exemplo())",
            "            [",
            "                'path' => '/exemplo',",
            "                'method' => 'exemplo',",
            "                'label' => 'exemplo'",
            "            ],",
            "        ]",
            "    ]",
            "];"
        ]);
    }

    /**
     * @param array $props
     * @param string $props->module_name
     * @param string $props->namespace
     */
    private static function regularModel($props)
    {
        $module_name = $props['module_name'] ?? "ModuleName";
        $namespace = $props['namespace'] ?? "Namespace";

        return join("\n", [
            '<?php',
            ' ',
            'namespace '.$namespace.';',
            ' ',
            'use Wep\Model;',
            ' ',
            '/**',
            ' * Model da tabela de \'db\' => [\'table\' => ...] no config/module.php.',
            ' * Ex.: ' . $module_name . '::find($id), ' . $module_name . '::create([\'fields\' => [...]]),',
            ' * ' . $module_name . '::updateById($id, $fields), ' . $module_name . '::deleteById($id).',
            ' **/',
            'class '.$module_name,
            '{',
            '   use Model;',
            '}'
        ]);
    }

    /**
     * @param array $props
     * @param string $props->module_name
     * @param string $props->namespace
     */
    private static function regularApi($props)
    {
        $module_name = $props['module_name'] ?? "ModuleName";
        $namespace = $props['namespace'] ?? "Namespace";

        return join("\n", [
            '<?php',
            '',
            "namespace ".$namespace.";",
            '',
            'use Wep\Controller;',
            'use Wep\Response;',
            'use Wep\Router\Http\RouterHttpRequest;',
            '',
            '/**',
            ' * Métodos chamados pelo front: cada rota com \'method\' no config/module.php.',
            ' *',
            ' * $request: dados enviados ($request->data(), $request->campo, $request->param(\'id\')),',
            ' *           headers, ip(), routeMapData()... Os dados vêm do cliente: valide antes de usar.',
            ' * $response: Response::success($data) / Response::error($mensagem) via $response->json().',
            ' */',
            "class ".$module_name."Api",
            '{',
            '    use Controller;',
            '',
            '    public function exemplo(RouterHttpRequest $request, Response $response)',
            '    {',
            '        $nome = trim((string) ($request->nome ?? \'\'));',
            '',
            '        return $response->json(Response::success([',
            '            \'nome\' => $nome,',
            '        ]));',
            '    }',
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

        return join("\n", [
            'import { TemplateMain } from "components";',
            '',
            "export default function " . $module_name . "() {",
            "    return (",
            "        <TemplateMain title=\"" . addslashes($module_name) . "\">",
            "            " . $module_name,
            "        </TemplateMain>",
            "    );",
            "}",
            ''
        ]);
    }

    private static function vendorConfig($props)
    {
        return join("\n", [
            "<?php",
            " ",
            "return [",
            "    // Só para chamar a API externa pelo front (rota /vendor/...). Sem isso, use o módulo",
            "    // apenas no PHP. Exposto, defina quem pode chamar:",
            "    // 'expose' => true,",
            "    // 'routes' => ['use_middlewares' => ['isLogged', 'checkPermission']],",
            "    'url' => '',",
            "    'headers' => [",
            " ",
            "    ],",
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

    /**
     * @param array $props
     * @param string $props->module_name
     * @param string $props->namespace
     */
    private static function vendorModel($props)
    {
        $module_name = $props['module_name'] ?? "ModuleName";
        $namespace = $props['namespace'] ?? "Namespace";

        return join("\n", [
            "<?php",
            " ",
            "namespace $namespace;",
            " ",
            "use Wep\Vendor;",
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
    
    /**
     * @param array $props
     * @param string $props->module_name
     * @param string $props->namespace
     */
    private static function vendorApi($props)
    {
        $module_name = $props['module_name'] ?? "ModuleName";
        $namespace = $props['namespace'] ?? "Namespace";

        return join("\n", [
            "<?php",
            " ",
            "namespace $namespace;",
            " ",
            "use Wep\Controller;",
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
        return join("\n", [
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