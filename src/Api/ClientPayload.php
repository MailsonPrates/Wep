<?php

namespace Wep\Api;

/**
 * Contrato do CRUD automático entre o front e o servidor.
 *
 * O cliente não envia queries. Só envia dados e, quando preciso, a chave primária:
 *
 *   get     {}                                   lista (ordem e limite definidos no servidor)
 *   get     {id}                                 um registro
 *   get     {filters: {col: valor}, page, per_page}  lista filtrada (só filtros declarados, por igualdade)
 *   create  {fields: {col: valor}}
 *   update  {id, fields: {col: valor}}
 *   delete  {id}
 *
 * Só as chaves acima são lidas; o resto do $request->data() (custom da rota, query string...)
 * é ignorado. Chaves do formato antigo de query (where, orderBy, leftJoin...) são recusadas.
 *
 * Config do módulo (config/module.php):
 *   'api' => [
 *       'primary_key' => 'user_group_id',      // padrão "id"
 *       'order_by'    => ['user_group_id', 'DESC'],
 *       'filters'     => ['status'],           // colunas que o cliente pode filtrar
 *       'hidden'      => ['code'],             // além de password: nunca lidas nem gravadas
 *       'fillable'    => ['name', 'roles'],    // se definido, só essas colunas são gravadas
 *       'per_page'    => 50,                   // padrão da paginação
 *       'max_limit'   => 1000,                 // teto de linhas por consulta
 *   ]
 */
final class ClientPayload
{
    public const DEFAULT_PRIMARY_KEY = 'id';

    public const DEFAULT_MAX_LIMIT = 1000;

    public const DEFAULT_PER_PAGE = 50;

    public const ALWAYS_HIDDEN = ['password'];

    /** Chaves do formato antigo (o front montava a query): recusadas. */
    public const LEGACY_KEYS = ['where', 'or', 'andOr', 'orderBy', 'limit', 'leftJoin', 'debug', 'raw', 'fetch', 'onDuplicateUpdate'];

    private const NAME = '/^[A-Za-z_][A-Za-z0-9_$]*$/';

    /**
     * Valida e normaliza o payload do cliente.
     *
     * @param array<string, mixed> $data $request->data()
     * @param string $operation get|create|update|delete
     *
     * @return array{id?: string|int, fields?: array, filters?: array, page?: int, per_page?: int}
     * @throws InvalidPayloadException
     */
    public static function parse(array $data, string $operation, array $options): array
    {
        $options = self::options($options);
        $operation = strtolower($operation);

        $legacy = array_values(array_intersect(self::LEGACY_KEYS, array_keys($data)));

        if ($legacy) {
            throw new InvalidPayloadException(
                'O front não monta mais queries (' . implode(', ', $legacy) . '). '
                . 'Use get(), get(id), get({filtro: valor}), create(campos), update(id, campos) ou delete(id).'
            );
        }

        return match ($operation) {
            'get', 'select' => self::forGet($data, $options),
            'create', 'insert' => ['fields' => self::fields($data, $options, false)],
            'update' => ['id' => self::id($data), 'fields' => self::fields($data, $options, true)],
            'delete' => ['id' => self::id($data)],
            default => throw new InvalidPayloadException("Operação não permitida: $operation"),
        };
    }

    /**
     * @return array{table: string, primary_key: string, hidden: string[], fillable: string[]|null,
     *               filters: string[], order_by: mixed, per_page: int, max_limit: int}
     */
    public static function options(array $options): array
    {
        $table = trim((string) ($options['table'] ?? ''));

        if ($table === '') {
            throw new InvalidPayloadException('Módulo sem tabela configurada para o CRUD automático');
        }

        $primaryKey = (string) ($options['primary_key'] ?? self::DEFAULT_PRIMARY_KEY);

        if (!preg_match(self::NAME, $primaryKey)) {
            throw new \InvalidArgumentException("primary_key inválida: $primaryKey");
        }

        $lower = fn ($list) => array_values(array_unique(array_map('strtolower', (array) $list)));
        $maxLimit = max(1, (int) ($options['max_limit'] ?? self::DEFAULT_MAX_LIMIT));

        return [
            'table' => $table,
            'primary_key' => $primaryKey,
            'hidden' => $lower(array_merge(self::ALWAYS_HIDDEN, (array) ($options['hidden'] ?? []))),
            'fillable' => isset($options['fillable']) ? $lower($options['fillable']) : null,
            'filters' => $lower($options['filters'] ?? []),
            'order_by' => $options['order_by'] ?? null,
            'per_page' => min($maxLimit, max(1, (int) ($options['per_page'] ?? self::DEFAULT_PER_PAGE))),
            'max_limit' => $maxLimit,
        ];
    }

    /**
     * Remove colunas ocultas do resultado (lista de linhas ou uma linha).
     */
    public static function filterRows(mixed $response, array $hidden): mixed
    {
        if (!is_object($response) || !empty($response->error) || !isset($response->data) || !is_array($response->data)) {
            return $response;
        }

        $hidden = array_flip(array_map('strtolower', $hidden));

        $strip = function ($row) use ($hidden) {
            if (!is_array($row)) return $row;

            foreach (array_keys($row) as $key) {
                if (isset($hidden[strtolower((string) $key)])) unset($row[$key]);
            }

            return $row;
        };

        $response->data = array_is_list($response->data)
            ? array_map($strip, $response->data)
            : $strip($response->data);

        return $response;
    }

    private static function forGet(array $data, array $o): array
    {
        if (array_key_exists('id', $data) && $data['id'] !== null && $data['id'] !== '') {
            return ['id' => self::id($data)];
        }

        $query = ['filters' => []];
        $filters = $data['filters'] ?? [];

        if (!is_array($filters) || ($filters && array_is_list($filters))) {
            throw new InvalidPayloadException('"filters" deve ser um objeto { coluna: valor }');
        }

        foreach ($filters as $column => $value) {
            $column = (string) $column;

            if (!in_array(strtolower($column), $o['filters'], true)) {
                throw new InvalidPayloadException("Filtro não permitido: $column");
            }

            $values = is_array($value) ? $value : [$value];

            if (!array_is_list($values)) {
                throw new InvalidPayloadException("Valor inválido para o filtro \"$column\"");
            }

            foreach ($values as $item) {
                if (!is_scalar($item) && $item !== null) {
                    throw new InvalidPayloadException("Valor inválido para o filtro \"$column\"");
                }
            }

            $query['filters'][$column] = $value;
        }

        foreach (['page', 'per_page'] as $key) {
            if (!isset($data[$key]) || $data[$key] === '') continue;

            if (!is_numeric($data[$key]) || (int) $data[$key] != $data[$key] || (int) $data[$key] < 1) {
                throw new InvalidPayloadException("\"$key\" inválido");
            }

            $query[$key] = (int) $data[$key];
        }

        if (isset($query['per_page'])) {
            $query['per_page'] = min($query['per_page'], $o['max_limit']);
        }

        return $query;
    }

    /**
     * @return string|int
     */
    private static function id(array $data): string|int
    {
        $id = $data['id'] ?? null;

        if ($id === null || $id === '' || is_bool($id) || !is_scalar($id) || strlen((string) $id) > 255) {
            throw new InvalidPayloadException('"id" ausente ou inválido');
        }

        return is_int($id) ? $id : (string) $id;
    }

    private static function fields(array $data, array $o, bool $isUpdate): array
    {
        $fields = $data['fields'] ?? null;

        if (!is_array($fields) || !$fields || array_is_list($fields)) {
            throw new InvalidPayloadException('"fields" deve ser um objeto { coluna: valor }');
        }

        $pk = strtolower($o['primary_key']);

        foreach ($fields as $column => $value) {
            $column = (string) $column;
            $lower = strtolower($column);

            if (!preg_match(self::NAME, $column) || in_array($lower, $o['hidden'], true)) {
                throw new InvalidPayloadException("Coluna não permitida: $column");
            }

            // a chave primária não é alterada pelo cliente e, no create, só se estiver em "fillable"
            if ($lower === $pk && ($isUpdate || $o['fillable'] === null || !in_array($pk, $o['fillable'], true))) {
                throw new InvalidPayloadException("A chave primária \"$column\" não pode ser enviada pelo cliente");
            }

            if ($o['fillable'] !== null && !in_array($lower, $o['fillable'], true)) {
                throw new InvalidPayloadException("A coluna \"$column\" não pode ser gravada por este módulo");
            }

            if (!is_scalar($value) && $value !== null) {
                throw new InvalidPayloadException("Valor inválido para \"$column\"");
            }
        }

        return $fields;
    }
}
