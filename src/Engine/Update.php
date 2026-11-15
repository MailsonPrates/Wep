<?php

namespace Wep\Engine;

use Wep\Core;
use Wep\Engine\Builder\BuilderHooks;
use Wep\Engine\Builder\BuildFile;
use Wep\Engine\Builder\RoutesMap;
use Wep\Engine\Builder\Vendor\Vendor;
use Wep\Obj;
use Wep\Router\RouteMap;

/**
 * `php app update`: gera os arquivos de build (storage/builds).
 *
 * Arquivos PHP gerados são só dados (var_export) e gravados de forma atômica (Builder\BuildFile).
 * Hooks que falham viram avisos e o build continua; com `--strict` a falha aborta.
 */
class Update
{
    public static function handle($props = [])
    {
        $strict = in_array('--strict', (array) ($props['options'] ?? []), true);

        BuilderHooks::reset();
        BuilderHooks::setStrict($strict);

        // remove itens expirados e arquivos de cache no formato antigo (antes do Módulo 8)
        \Wep\Cache::refresh();

        try {
            return self::build();
        } catch (\RuntimeException $e) {
            // só chega aqui em modo estrito (falha de hook)
            return Obj::set(['error' => true, 'message' => $e->getMessage()]);
        } finally {
            BuilderHooks::setStrict(false);
        }
    }

    private static function build()
    {
        BuilderHooks::beforeBuild();

        foreach (['routes', 'configs', 'endpoints', 'customSets'] as $step) {
            $result = self::$step();

            if ($result->error) return $result;
        }

        $message = "App atualizado \\0/";

        // Fora de DEV compila o .env em storage/builds/env.build.php; em DEV remove o cache
        if ( Core::syncEnvCache() ){
            $message .= " (cache do .env gerado)";
        }

        BuilderHooks::afterBuild();

        return Obj::set([
            'error' => false,
            'message' => $message,
            'warnings' => array_merge(RoutesMap::warnings(), BuilderHooks::warnings()),
        ]);
    }

    private static function routes()
    {
        $errors = [];
        $routes_build = RoutesMap::build();

        if ( !BuildFile::php(ROUTE_MAPS_FILENAME, $routes_build->raw) ) $errors[] = "Erro ao gravar o mapa de rotas (route-maps.php)";
        if ( !BuildFile::php(ROUTE_MAPS_BUILD_FILENAME_BACK, $routes_build->backend) ) $errors[] = "Erro ao gravar as rotas do backend";
        if ( !BuildFile::write(ROUTE_MAPS_BUILD_FILENAME_FRONT, $routes_build->frontend) ) $errors[] = "Erro ao gravar as rotas do front";

        // storage/builds/apis/index.js: APIs dos módulos para o front
        if ( !empty($routes_build->module_apis) && !BuildFile::write(DIR_BUILD_MODULE_APIS . "/index.js", $routes_build->module_apis) ){
            $errors[] = "Erro ao gravar as APIs dos módulos";
        }

        // hooks do mesmo processo (after_build) passam a ler o mapa novo
        RouteMap::flush();

        return Obj::set([
            'error' => !empty($errors),
            'message' => $errors ? "Houve erro ao atualizar as rotas" : 'Rotas atualizadas',
            'data' => $errors
        ]);
    }

    /**
     * storage/builds/configs.build.json (configs públicas para o front)
     */
    private static function configs()
    {
        $configs = array_merge(
            [
                'name' => Core::config('name'),
                'title' => Core::config('title|name')
            ],
            [
                'app_url' => Core::config('env.app_url'),
                'env' => Core::config('env.env')
            ],
            Core::$data ?? []
        );

        $json = json_encode($configs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $ok = $json !== false && BuildFile::write(CONFIGS_BUILD_FILENAME_FRONT, $json);

        return Obj::set([
            'error' => !$ok,
            'message' => $ok ? 'Configs atualizadas' : "Erro ao atualizar o arquivo de configs",
            'data' => []
        ]);
    }

    /**
     * storage/builds/vendor-endpoints.build.php
     */
    private static function endpoints()
    {
        $ok = BuildFile::php(VENDOR_ENDPOINTS_MAP_FILENAME, Vendor::buildEndpointsMap());

        Vendor::flush();

        return Obj::set([
            'error' => !$ok,
            'message' => $ok ? 'Endpoints dos módulos vendor atualizados' : "Erro ao atualizar o arquivo de endpoint dos módulos vendor",
            'data' => []
        ]);
    }

    /**
     * storage/builds/constants.build.php: constantes do app e dos módulos ('constants' nas configs).
     *
     * Gravadas como dados; o Core define ao iniciar. Placeholders "{OUTRA_CONSTANTE}" no texto
     * referenciam outras constantes (ex.: '{DIR_ROOT}/uploads'). Antes o arquivo era código montado
     * por concatenação ("define('$key', $value)"): um apóstrofo no valor quebrava o site.
     */
    private static function customSets()
    {
        $constants = Core::config('constants') ?? [];

        foreach( Module::getConfigs() as $config ){
            $constants = array_merge($constants, $config['constants'] ?? []);
        }

        $data = [];
        $errors = [];

        foreach( $constants as $key => $value ){
            $name = strtoupper((string) $key);

            if ( !preg_match('/^[A-Z_][A-Z0-9_]*$/', $name) ){
                $errors[] = "Nome de constante inválido: $key";
                continue;
            }

            $data[$name] = is_string($value) ? self::constantParts($value) : ['value' => $value];
        }

        if ( $errors ){
            return Obj::set(['error' => true, 'message' => "Erro nas constantes", 'data' => $errors]);
        }

        if ( empty($data) ){
            if ( file_exists(APP_CONSTANTS_FILENAME) ) unlink(APP_CONSTANTS_FILENAME);

            return Obj::set(['error' => false, 'message' => 'Sem constantes', 'data' => []]);
        }

        $ok = BuildFile::php(APP_CONSTANTS_FILENAME, $data);

        return Obj::set([
            'error' => !$ok,
            'message' => $ok ? 'Arquivo de constantes criado' : 'Erro ao gravar o arquivo de constantes',
            'data' => []
        ]);
    }

    /**
     * "{DIR_ROOT}/uploads" → ['parts' => [['const', 'DIR_ROOT'], ['text', '/uploads']]]
     */
    private static function constantParts(string $text): array
    {
        if ( !str_contains($text, '{') ) return ['value' => $text];

        $parts = [];
        $chunks = preg_split('/\{\s*([A-Za-z_][A-Za-z0-9_]*)\s*\}/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);

        foreach( $chunks as $index => $chunk ){
            if ( $index % 2 === 1 ){
                $parts[] = ['const', strtoupper($chunk)];
            } elseif ( $chunk !== '' ){
                $parts[] = ['text', $chunk];
            }
        }

        return ['parts' => $parts];
    }
}
