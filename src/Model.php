<?php

namespace Wep;

use Wep\Api\ClientQuery;
use Wep\Api\ClientQueryException;
use Wep\Router\Http\RouterHttpRequest;

/**
 * Model de um módulo: get/create/update/delete na tabela do config do módulo.
 *
 * Proteção do CRUD automático: quando o Model recebe exatamente o payload da requisição atual
 * ($request->data(), sem alterações), ele é tratado como vindo do cliente e passa pelo
 * ClientQuery (sem leftJoin/debug/raw, colunas só desta tabela, ocultas bloqueadas...).
 * Se o seu código altera o payload antes de repassar, sanitize explicitamente:
 *
 *   $data = Pedido::clientQuery($request->data(), 'update');
 *   $data['fields']['updated_by'] = $userId;
 *   Pedido::update($data);
 */
trait Model
{
    public static $table = null;
    public static $pdo = null;

    /** @var array|null opções "api" do config do módulo + nome da tabela */
    private static $apiOptions = null;

    protected static function run($method, $props)
    {
        self::getTable();

        $isClient = is_array($props) && (
            !empty($props[ClientQuery::MARKER]) || self::isRawRequestPayload($props)
        );

        if (!$isClient) {
            return self::$table->{$method}($props);
        }

        if (empty($props[ClientQuery::MARKER])) {
            try {
                $props = static::clientQuery($props, $method);
            } catch (ClientQueryException $e) {
                return Response::error($e->getMessage(), ['http_code' => 400]);
            }
        }

        unset($props[ClientQuery::MARKER]);

        $result = self::$table->{$method}($props);

        // SELECT * traz as colunas ocultas: remove do resultado (inclui sempre "password")
        return $method === 'get'
            ? ClientQuery::filterRows($result, ClientQuery::options(self::apiOptions())['hidden'])
            : $result;
    }

    /**
     * Saneia um payload vindo do cliente para esta tabela.
     *
     * @param string $operation get|create|update|delete
     * @throws ClientQueryException
     */
    public static function clientQuery(array $payload, string $operation): array
    {
        return ClientQuery::sanitize($payload, $operation, self::apiOptions());
    }

    public static function table()
    {
        return self::getTable();
    }

    /**
     * Opções do CRUD automático: config "api" do módulo e nome da tabela.
     */
    public static function apiOptions(): array
    {
        if (self::$apiOptions === null) {
            self::getTable();
        }

        return self::$apiOptions;
    }

    private static function getTable()
    {
        if ( !self::$table ){

            $module_namespace_parts = explode('Modules\\', static::class);
            $module_path = explode('\\', $module_namespace_parts[1]);
            array_pop($module_path);

            $module_name = join("\\", $module_path);
            $module_name = str_replace("\\", "/", $module_name);
            $module_config = Core::config('module', $module_name);
            $module_config_db = $module_config->db ?? [];

            $module_table_name = $module_config->table ?? $module_config_db['table'] ?? null;

            if ( !$module_table_name ) throw new \Exception("Nome da tabela não definida nas configurações do módulo: ". $module_name);

            // Repassa props do config do módulo importantes para o db
            $module_config_db['debug'] = $module_config->debug ?? false;
            $module_config_db['name'] = $module_table_name;

            $api = $module_config->api ?? [];
            self::$apiOptions = (is_array($api) ? $api : (array) $api) + ['table' => $module_table_name];

            self::$table = DB::table($module_table_name, $module_config_db);
            self::$pdo = self::$table->pdo;
        }

        return self::$table;
    }

    /**
     * O payload é exatamente o $request->data() da requisição atual?
     */
    private static function isRawRequestPayload(array $props): bool
    {
        $request = RouterHttpRequest::current();

        return $request !== null && $props !== [] && $props === $request->data();
    }

    public static function get($props=[])
    {
        return self::run('get', $props);
    }

    public static function create($props=[])
    {
        return self::run('create', $props);
    }

    public static function update($props=[])
    {
        return self::run('update', $props);
    }

    public static function delete($props=[])
    {
        return self::run('delete', $props);
    }
}
