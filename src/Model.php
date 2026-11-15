<?php

namespace Wep;

use Wep\Api\ClientPayload;
use Wep\Api\InvalidPayloadException;
use Wep\Router\Http\RouterHttpRequest;

/**
 * Model de um módulo, ligado à tabela do config do módulo.
 *
 * Servidor (código PHP: acesso total ao query builder, inclusive o modo verbose):
 *   Pedido::get([...]); Pedido::create([...]); Pedido::update([...]); Pedido::delete([...]);
 *   Pedido::find($id); Pedido::list(['status' => 'on'], $page); Pedido::updateById($id, $campos); Pedido::deleteById($id);
 *
 * Cliente (CRUD automático): o front não envia queries, só dados e id. Use fromClient(),
 * que valida o payload pelo contrato de Wep\Api\ClientPayload e executa:
 *   return $response->json(Pedido::fromClient('update'));
 *
 * Repassar o payload bruto do cliente para os métodos do servidor (ex.: Pedido::update($request->data()))
 * é recusado com erro 400.
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

        if (is_array($props) && self::isRawRequestPayload($props)) {
            return Response::error(
                'O payload do cliente não pode ser repassado direto ao Model. Use ' . static::class . "::fromClient('$method').",
                ['http_code' => 400]
            );
        }

        return self::$table->{$method}($props);
    }

    /*
    |--------------------------------------------------------------------------
    | Cliente (CRUD automático)
    |--------------------------------------------------------------------------
    */

    /**
     * Valida o payload do cliente e executa a operação.
     *
     * @param string $operation get|create|update|delete
     * @param array|null $data padrão: $request->data() da requisição atual
     *
     * @return object Response (erro 400 quando o payload está fora do contrato)
     */
    public static function fromClient(string $operation, ?array $data = null)
    {
        try {
            $payload = static::clientPayload($operation, $data);
        } catch (InvalidPayloadException $e) {
            return Response::error($e->getMessage(), ['http_code' => 400]);
        }

        $operation = strtolower($operation);
        $hidden = self::options()['hidden'];

        if ($operation === 'get' || $operation === 'select') {
            if (isset($payload['id'])) {
                $result = static::find($payload['id']);

                if (empty($result->error) && $result->data === null) {
                    return Response::error('Registro não encontrado', ['http_code' => 404]);
                }

                return ClientPayload::filterRows($result, $hidden);
            }

            $result = static::list($payload['filters'], $payload['page'] ?? null, $payload['per_page'] ?? null);

            return ClientPayload::filterRows($result, $hidden);
        }

        return match ($operation) {
            'create', 'insert' => self::$table->create(['fields' => $payload['fields']]),
            'update' => static::updateById($payload['id'], $payload['fields']),
            'delete' => static::deleteById($payload['id']),
        };
    }

    /**
     * Valida o payload do cliente sem executar (para quem precisa complementar os dados no servidor).
     *
     * @return array{id?: string|int, fields?: array, filters?: array, page?: int, per_page?: int}
     * @throws InvalidPayloadException
     */
    public static function clientPayload(string $operation, ?array $data = null): array
    {
        self::getTable();

        if ($data === null) {
            $request = RouterHttpRequest::current();
            $data = $request ? $request->data() : [];
        }

        return ClientPayload::parse($data, $operation, self::$apiOptions);
    }

    /*
    |--------------------------------------------------------------------------
    | Servidor
    |--------------------------------------------------------------------------
    */

    /**
     * Um registro pela chave primária. data = linha (array) ou null.
     */
    public static function find($id)
    {
        $result = self::getTable()->get([
            'where' => [[self::options()['primary_key'], '=', $id]],
            'limit' => 1,
        ]);

        if (empty($result->error)) {
            $result->data = $result->data[0] ?? null;
        }

        return $result;
    }

    /**
     * Lista com filtros por igualdade (valor array vira IN), ordem e limite da config.
     * Com $page, pagina com $perPage (padrão "per_page" da config) e devolve page/per_page.
     */
    public static function list(array $filters = [], ?int $page = null, ?int $perPage = null)
    {
        $options = self::options();
        $query = [];

        foreach ($filters as $column => $value) {
            $query['where'][] = [$column, is_array($value) ? 'in' : '=', $value];
        }

        if ($options['order_by']) {
            $query['orderBy'] = $options['order_by'];
        }

        if ($page !== null) {
            $perPage = min($perPage ?? $options['per_page'], $options['max_limit']);
            $query['limit'] = [$perPage];
            $query['page'] = max(1, $page);
        } else {
            $query['limit'] = [$options['max_limit']];
        }

        $result = self::getTable()->get($query);

        if ($page !== null && empty($result->error)) {
            $result->page = $query['page'];
            $result->per_page = $perPage;
        }

        return $result;
    }

    public static function updateById($id, array $fields)
    {
        return self::getTable()->update([
            'fields' => $fields,
            'where' => [[self::options()['primary_key'], '=', $id]],
        ]);
    }

    public static function deleteById($id)
    {
        return self::getTable()->delete([
            'where' => [[self::options()['primary_key'], '=', $id]],
        ]);
    }

    public static function table()
    {
        return self::getTable();
    }

    /**
     * Opções do CRUD automático: config "api" do módulo e nome da tabela (cru, sem normalizar).
     */
    public static function apiOptions(): array
    {
        self::getTable();

        return self::$apiOptions;
    }

    private static function options(): array
    {
        return ClientPayload::options(self::apiOptions());
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
