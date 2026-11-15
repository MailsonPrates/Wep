<?php

namespace Wep\DB\Query;

use Wep\Response;
use InvalidArgumentException;
use PDO;
use PDOException;
use PDOStatement;

class Executor
{
    /**
     * Modos de fetch aceitos (whitelist). Antes o nome vinha direto para constant(),
     * então um valor inválido gerava Error não tratado.
     */
    private const FETCH_MODES = [
        'ASSOC'    => PDO::FETCH_ASSOC,
        'OBJ'      => PDO::FETCH_OBJ,
        'NUM'      => PDO::FETCH_NUM,
        'BOTH'     => PDO::FETCH_BOTH,
        'COLUMN'   => PDO::FETCH_COLUMN,
        'KEY_PAIR' => PDO::FETCH_KEY_PAIR,
        'NAMED'    => PDO::FETCH_NAMED,
        'CLASS'    => PDO::FETCH_CLASS,
        'UNIQUE'   => PDO::FETCH_UNIQUE,
        'GROUP'    => PDO::FETCH_GROUP,
    ];

    /**
     * @var array
     * null_as_empty: mantém o comportamento antigo (NULL é gravado como ""). Recomendado
     *                desligar quando as colunas aceitarem NULL de verdade.
     * expose_errors: devolve a mensagem crua do banco em Response::error. Por padrão fica
     *                desligado: a mensagem real vai para o error_log e o chamador recebe
     *                uma mensagem genérica (mensagens do MySQL revelam tabelas/colunas).
     */
    private static $options = [
        'null_as_empty' => true,
        'expose_errors' => false,
    ];

    public static function setOptions(array $options): void
    {
        self::$options = array_merge(self::$options, array_intersect_key($options, self::$options));
    }

    public static function option(string $key)
    {
        return self::$options[$key] ?? null;
    }

    /**
     * @param PDO|null $pdo
     * @param string $query
     * @param array $fields valores dos placeholders (nome => valor, sem ":"; ou lista para "?")
     * @param array $config raw|debug|fetch|custom_query
     */
    public static function execute(?PDO $pdo, string $query, array $fields=[], $config=[])
    {
        $config = (array) $config;
        $raw = $config['raw'] ?? false;
        $debug = $config['debug'] ?? false;

        try {
            $binds = self::normalize($fields);
        } catch (InvalidArgumentException $e) {
            return Response::error($e->getMessage());
        }

        // debug não precisa (nem deve) abrir conexão
        if ( $debug ){
            return [
                'bind' => $binds,
                'query' => $query,
                'fields' => $fields,
                'config' => $config
            ];
        }

        if ( !$pdo ) return Response::error("Erro de conexão com banco de dados");

        $fetch_name = strtoupper(($config['fetch'] ?? '') ?: 'assoc');
        $fetch_mode = self::FETCH_MODES[$fetch_name] ?? null;

        if ( $fetch_mode === null ) return Response::error("Invalid fetch mode");

        try {

            $stmt = $pdo->prepare($query);

            if ( !$stmt ) return Response::error();

            foreach( $binds as $name => $value ){
                $stmt->bindValue($name, $value, $value === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            }

            if ( !$stmt->execute() ) return Response::error();

            // Quem decide se há linhas para ler é o próprio statement. Antes o método era
            // deduzido do primeiro "token" da query (quebrava com espaço/quebra de linha no
            // início, WITH, SHOW...) e queries custom sem retorno (INSERT) tentavam fetchAll.
            if ( $stmt->columnCount() > 0 ){

                // "obj" sempre devolveu apenas a primeira linha; mantido por compatibilidade
                $data = $fetch_name === 'OBJ' ? $stmt->fetch($fetch_mode) : $stmt->fetchAll($fetch_mode);

                if ( $data === false ) $data = [];

                if ( $raw ) return Response::success($data, [
                    "stmt" => $stmt
                ]);

                return Response::success($data);
            }

            if ( $raw ) return Response::success([], [
                "stmt" => $stmt,
                'pdo' => $pdo
            ]);

            return Response::success([], [
                'last_id' => $pdo->lastInsertId(),
                'affected_rows' => $stmt->rowCount()
            ]);

        } catch (PDOException $e){
            return self::fail($e, $query);
        }
    }

    /**
     * Normaliza os valores para o bind.
     *
     * Tudo continua indo como string (como antes) de propósito: bindar inteiro contra
     * coluna VARCHAR faz o MySQL converter a coluna para número (`code = 0` casa com
     * qualquer texto) e ainda impede o uso de índice.
     *
     * @throws InvalidArgumentException
     */
    private static function normalize(array $fields): array
    {
        $binds = [];

        foreach( $fields as $key => $value ){

            $name = is_int($key) ? $key + 1 : ':' . ltrim($key, ':');

            if ( $value === null ){
                $value = self::$options['null_as_empty'] ? "" : null;

            } elseif ( is_bool($value) ){
                $value = $value ? "1" : "0";

            } elseif ( is_array($value) || (is_object($value) && !method_exists($value, '__toString')) ){
                throw new InvalidArgumentException("Invalid value for parameter \"$key\"");
            }

            $binds[$name] = $value;
        }

        return $binds;
    }

    private static function fail(PDOException $e, string $query)
    {
        // detalhe técnico só no log do servidor
        error_log('[DB] ' . $e->getMessage() . ' | SQL: ' . $query);

        if ( self::$options['expose_errors'] ){
            return Response::error($e->getMessage());
        }

        switch( $e->errorInfo[1] ?? null ){
            case 1062:
                return Response::error("Registro duplicado");
            case 1451:
            case 1452:
                return Response::error("Violação de integridade referencial");
        }

        return Response::error("Erro ao executar a consulta no banco de dados");
    }
}
