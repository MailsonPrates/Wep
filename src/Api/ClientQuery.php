<?php

namespace Wep\Api;

/**
 * Saneia a query enviada pelo cliente (front/api-factory) antes de chegar ao Model.
 *
 * O modo "verbose" do Model aceita leftJoin, debug, raw, fetch... e o servidor usa isso
 * legitimamente. Vindo do navegador, as mesmas chaves permitiam ler qualquer tabela do banco
 * (ex.: leftJoin com "user" para obter hashes de senha). Este saneador é a fronteira:
 *
 * - aceita só as chaves que o front envia: fields, where, or, andOr, orderBy, limit, page;
 * - colunas apenas da tabela do módulo ("outra_tabela.col" e "banco.tabela.col" são recusados);
 * - colunas ocultas ("hidden", sempre inclui "password") não podem ser lidas, filtradas,
 *   ordenadas nem gravadas;
 * - create/update gravam só colunas permitidas ("fillable", se configurado);
 * - update/delete exigem condição de igualdade (= ou in) e não aceitam or/andOr, a menos que
 *   o módulo libere "allow_bulk";
 * - limit com teto ("max_limit", padrão 1000), aplicado também quando o cliente não envia limit.
 *
 * Config do módulo (config/module.php):
 *   'api' => ['hidden' => ['code'], 'fillable' => ['name'], 'max_limit' => 200, 'allow_bulk' => false]
 */
final class ClientQuery
{
    /** Marca um array já saneado (o Model aplica o filtro de colunas ocultas no resultado). */
    public const MARKER = '__wep_client_query';

    public const DEFAULT_MAX_LIMIT = 1000;

    public const ALWAYS_HIDDEN = ['password'];

    /** Chaves do modo verbose que não são campos (descartadas de payloads "planos"). */
    private const RESERVED = [
        'fields', 'where', 'or', 'andOr', 'orderBy', 'limit', 'page',
        'leftJoin', 'debug', 'raw', 'fetch', 'onDuplicateUpdate', 'requestOptions', self::MARKER,
    ];

    private const PART = '[A-Za-z_][A-Za-z0-9_$]*';

    private const NON_EQUALITY_TYPE_OPERATORS = ['is null', 'is not null', 'is not empty'];

    /**
     * @param array<string, mixed> $payload dados do cliente ($request->data())
     * @param string $operation get|select|create|insert|update|delete
     * @param array{table: string, hidden?: string[], fillable?: string[]|null, max_limit?: int, allow_bulk?: bool} $options
     *
     * @return array<string, mixed> payload verbose seguro para o Model
     * @throws ClientQueryException
     */
    public static function sanitize(array $payload, string $operation, array $options): array
    {
        $options = self::options($options);

        $query = match (strtolower($operation)) {
            'get', 'select' => self::forGet($payload, $options),
            'create', 'insert' => ['fields' => self::writableFields($payload, $options)],
            'update' => self::forUpdate($payload, $options),
            'delete' => self::conditionsFor('delete', $payload, $options),
            default => throw new ClientQueryException("Operação não permitida: $operation"),
        };

        $query[self::MARKER] = true;

        return $query;
    }

    /**
     * Remove colunas ocultas das linhas do resultado (consultas com SELECT *).
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

        // lista de linhas (fetch assoc) ou uma linha (fetch obj/assoc único)
        $response->data = array_is_list($response->data)
            ? array_map($strip, $response->data)
            : $strip($response->data);

        return $response;
    }

    /**
     * @return array{table: string, hidden: string[], fillable: string[]|null, max_limit: int, allow_bulk: bool}
     */
    public static function options(array $options): array
    {
        $table = trim((string) ($options['table'] ?? ''));

        if ($table === '') {
            throw new ClientQueryException('Módulo sem tabela configurada para o CRUD automático');
        }

        // "orders o" → tabela "orders", alias "o"
        $parts = preg_split('/\s+(?:AS\s+)?/i', $table);

        $hidden = array_merge(self::ALWAYS_HIDDEN, (array) ($options['hidden'] ?? []));
        $fillable = $options['fillable'] ?? null;

        return [
            'table' => $parts[0],
            'alias' => $parts[1] ?? null,
            'hidden' => array_values(array_unique(array_map('strtolower', $hidden))),
            'fillable' => $fillable === null ? null : array_map('strtolower', (array) $fillable),
            'max_limit' => max(1, (int) ($options['max_limit'] ?? self::DEFAULT_MAX_LIMIT)),
            'allow_bulk' => (bool) ($options['allow_bulk'] ?? false),
        ];
    }

    private static function forGet(array $payload, array $o): array
    {
        $query = [];

        $fields = $payload['fields'] ?? [];

        if (!is_array($fields) || ($fields && !array_is_list($fields))) {
            throw new ClientQueryException('"fields" deve ser uma lista de colunas');
        }

        foreach ($fields as $field) {
            self::selectExpression($field, $o);
        }

        $query['fields'] = $fields;

        foreach (['where', 'or', 'andOr'] as $type) {
            if (!empty($payload[$type])) {
                $query[$type] = self::conditions($payload[$type], $o);
            }
        }

        if (!empty($payload['orderBy'])) {
            $query['orderBy'] = self::orderBy($payload['orderBy'], $o);
        }

        $query['limit'] = self::limit($payload['limit'] ?? null, $o['max_limit']);

        $page = $payload['page'] ?? null;

        if ($page !== null && $page !== '') {
            if (!is_numeric($page) || (int) $page != $page) {
                throw new ClientQueryException('"page" inválido');
            }

            $query['page'] = max(1, (int) $page);
        }

        return $query;
    }

    private static function forUpdate(array $payload, array $o): array
    {
        $fields = self::writableFields($payload, $o);
        $query = self::conditionsFor('update', $payload, $o);

        return ['fields' => $fields] + $query;
    }

    /**
     * where/or/andOr de update e delete, com a regra de operação em massa.
     */
    private static function conditionsFor(string $operation, array $payload, array $o): array
    {
        $isFlat = !array_key_exists('where', $payload) && !array_key_exists('fields', $payload);

        if ($isFlat && $operation === 'delete') {
            // delete({"id": 5}) vira where por igualdade (comportamento do Model), já no formato lista
            $where = [];

            foreach (array_diff_key($payload, array_flip(self::RESERVED)) as $column => $value) {
                $where[] = [$column, is_array($value) ? 'in' : '=', $value];
            }
        } else {
            // update plano ({"nome": "x"}) não tem where: é recusado abaixo (antes de virar um
            // where por todas as colunas, que seria um no-op silencioso)
            $where = $payload['where'] ?? [];
        }

        $query = ['where' => self::conditions($where, $o)];

        if (!$query['where']) {
            throw new ClientQueryException("$operation sem where não é permitido");
        }

        foreach (['or', 'andOr'] as $type) {
            if (!empty($payload[$type])) {
                if (!$o['allow_bulk']) {
                    throw new ClientQueryException("$operation com \"$type\" não é permitido (pode afetar várias linhas)");
                }

                $query[$type] = self::conditions($payload[$type], $o);
            }
        }

        if (!$o['allow_bulk'] && !self::hasEquality($query['where'])) {
            throw new ClientQueryException("$operation exige uma condição de igualdade (= ou in) no where");
        }

        return $query;
    }

    /**
     * Campos de create/update: colunas da tabela, fora das ocultas e dentro de "fillable".
     */
    private static function writableFields(array $payload, array $o): array
    {
        $fields = array_key_exists('fields', $payload)
            ? $payload['fields']
            : array_diff_key($payload, array_flip(self::RESERVED));

        if (!is_array($fields) || !$fields || array_is_list($fields)) {
            throw new ClientQueryException('"fields" deve ser um objeto { coluna: valor }');
        }

        $clean = [];

        foreach ($fields as $column => $value) {
            $name = self::column((string) $column, $o);

            if ($o['fillable'] !== null && !in_array(strtolower($name), $o['fillable'], true)) {
                throw new ClientQueryException("A coluna \"$name\" não pode ser gravada por este módulo");
            }

            if (is_array($value) || is_object($value)) {
                throw new ClientQueryException("Valor inválido para \"$name\"");
            }

            $clean[$column] = $value;
        }

        return $clean;
    }

    /**
     * Normaliza where/or/andOr para lista de [col, op, valor] ou assoc { col: valor }.
     */
    private static function conditions(mixed $value, array $o): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }

        if (!is_array($value)) {
            throw new ClientQueryException('Condição inválida');
        }

        // { "status": "on", "id": [1, 2] }
        if (!array_is_list($value)) {
            foreach ($value as $column => $item) {
                self::column((string) $column, $o);

                if (is_array($item) && !array_is_list($item)) {
                    throw new ClientQueryException('Condição inválida');
                }
            }

            return $value;
        }

        // condição única: ["id", "=", 1]
        if (is_string($value[0] ?? null)) {
            $value = [$value];
        }

        foreach ($value as $condition) {
            if (!is_array($condition) || !array_is_list($condition) || count($condition) < 2 || count($condition) > 3 || !is_string($condition[0])) {
                throw new ClientQueryException('Condição inválida: use [coluna, operador, valor]');
            }

            self::column($condition[0], $o);
        }

        return $value;
    }

    private static function hasEquality(array $conditions): bool
    {
        if (!array_is_list($conditions)) {
            return true; // { col: valor } é sempre igualdade/in
        }

        foreach ($conditions as $condition) {
            if (count($condition) === 2) {
                $second = $condition[1];
                $isTypeOperator = is_string($second) && in_array(strtolower(trim($second)), self::NON_EQUALITY_TYPE_OPERATORS, true);

                if (!$isTypeOperator) return true;
                continue;
            }

            if (is_string($condition[1]) && in_array(strtolower(trim($condition[1])), ['=', 'in'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * orderBy nos formatos do front: "col" | ["a", "b"] | ["a", "desc"] | [["a", "b"], "desc"].
     */
    private static function orderBy(mixed $value, array $o): array
    {
        $args = is_array($value) ? $value : [$value];

        foreach ($args as $index => $arg) {
            $isLast = $index === array_key_last($args);

            if (is_string($arg) && $isLast && $index > 0 && in_array(strtolower($arg), ['asc', 'desc'], true)) {
                continue;
            }

            foreach (is_array($arg) ? $arg : [$arg] as $column) {
                if (!is_string($column)) {
                    throw new ClientQueryException('orderBy inválido');
                }

                if (strtoupper(trim($column)) !== 'RAND()') {
                    self::column($column, $o);
                }
            }
        }

        return array_values($args);
    }

    /**
     * @return int[] [n] ou [offset, n], com n <= max
     */
    private static function limit(mixed $value, int $max): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [$max];
        }

        $values = is_array($value) ? array_values($value) : [$value];

        if (count($values) > 2) {
            throw new ClientQueryException('"limit" inválido');
        }

        foreach ($values as $n) {
            if (!is_numeric($n) || (int) $n != $n || $n < 0) {
                throw new ClientQueryException('"limit" inválido');
            }
        }

        $values = array_map('intval', $values);
        $last = count($values) - 1;
        $values[$last] = min($values[$last], $max);

        return $values;
    }

    /**
     * Valida uma expressão de select: [DISTINCT] col | tabela.col | * | FUNC([DISTINCT] col|*) [ [AS] alias ].
     */
    private static function selectExpression(mixed $expression, array $o): void
    {
        if (!is_string($expression) || trim($expression) === '') {
            throw new ClientQueryException('Campo inválido em "fields"');
        }

        $expr = trim($expression);
        $expr = preg_replace('/^DISTINCT\s+/i', '', $expr);
        $expr = preg_replace('/\s+(?:AS\s+)?' . self::PART . '$/i', '', $expr);

        if (preg_match('/^(?:COUNT|SUM|AVG|MIN|MAX)\s*\(\s*(?:DISTINCT\s+)?([^()]+?)\s*\)$/i', $expr, $m)) {
            $expr = $m[1];
        }

        self::column($expr, $o);
    }

    /**
     * Valida uma coluna da tabela do módulo e devolve o nome sem a tabela.
     */
    private static function column(string $column, array $o): string
    {
        $column = trim($column);
        $parts = explode('.', $column);

        if (count($parts) > 2) {
            throw new ClientQueryException("Coluna não permitida: $column");
        }

        if (count($parts) === 2) {
            $owner = strtolower($parts[0]);
            $allowed = array_filter([strtolower($o['table']), strtolower((string) $o['alias'])]);

            if (!in_array($owner, $allowed, true)) {
                throw new ClientQueryException("Coluna de outra tabela não é permitida: $column");
            }
        }

        $name = end($parts);

        if ($name !== '*' && !preg_match('/^' . self::PART . '$/', $name)) {
            throw new ClientQueryException("Coluna inválida: $column");
        }

        if (in_array(strtolower($name), $o['hidden'], true)) {
            throw new ClientQueryException("Coluna não permitida: $column");
        }

        return $name;
    }
}
