<?php

namespace Wep;

use Wep\DB\Connection;
use Wep\DB\Handler;
use Wep\DB\Query\Executor;
use Wep\DB\Query\Formatter;
use Wep\DB\Query\Raw;
use Exception;

class DB
{
    public static $pdo;
    private static $host = "";
    private static $name = "";
    private static $username = "";
    private static $password = "";
    private static $charset = "";
    private static $debug = false;
    private static $multi_statements = false;

    /**
     * @param string $tableName
     * @param array $config
     * @param bool $config->debug
     */
    public static function table($tableName, $config=[]) 
    {
        $config = !is_array($config) ? (array)$config : $config;

        $config["table"] = $tableName;

        $pdo = null;

        // Em debug a query só é montada, nunca executada: não abre conexão.
        // (Antes isso sobrescrevia DB::$pdo com um objeto vazio e quebrava
        // todas as queries seguintes.)
        if ( empty($config["debug"]) ){
            self::connect();
            $pdo = self::$pdo;
        }

        return new Handler($pdo, $config);
    }

    public static function query($query_string, $fields=[], $configs=[])
    {
        if ( !$query_string ) return Response::error("Invalid query string");

        $has_placeholders = Formatter::hasNamedPlaceholders($query_string);
        $has_fields = !empty($fields);

        if ( $has_placeholders && !$has_fields ) return Response::error("Missing placeholder values");

        if ( self::$debug ){

            $query_raw = $has_fields
                ? Formatter::interpolate($query_string, $fields)
                : $query_string;

            return (object)[
                "query_string" => $query_string,
                'query_raw' => $query_raw,
                "fields" => $fields
            ];
        }

        self::connect();

        $configs['custom_query'] = true;

        return Executor::execute(self::$pdo, $query_string, $fields, $configs);
    }

    /**
     * Fragmento SQL sem validação nem escape, para expressões que o builder não cobre.
     * Use somente com texto escrito no código, nunca com input de usuário.
     *
     * @example DB::table('users')->orderBy(DB::raw('RAND()'))->get();
     */
    public static function raw($sql)
    {
        return new Raw((string) $sql);
    }

    public static function beginTransaction()
    {
        self::connect();

        if (!self::$pdo->inTransaction()) {
            self::$pdo->beginTransaction();
        }

        return new self();
    }

    public static function commit()
    {
        if (self::$pdo && self::$pdo->inTransaction()) {
            return self::$pdo->commit();
        }

        return false;
    }

    public static function rollback()
    {
        if (self::$pdo && self::$pdo->inTransaction()) {
            self::$pdo->rollBack();
        }

        return new self();
    }

    /**
     * @param array $config
     * @param string $config->host
     * @param string $config->name
     * @param string $config->username
     * @param string $config->password
     * @param string $config->charset
     * @param bool $config->debug
     * @param bool $config->null_as_empty (padrão true) grava NULL como "" (comportamento legado)
     * @param bool $config->expose_errors (padrão false) devolve a mensagem crua do banco em Response::error
     * @param bool $config->multi_statements (padrão false) permite várias queries separadas por ";" em uma chamada
     */
    public static function setConfig($config=[])
    {
        self::$host = $config['host'] ?? self::$host;
        self::$name = $config['name'] ?? self::$name;
        self::$username = $config['username'] ?? self::$username;
        self::$password = $config['password'] ?? self::$password;
        self::$charset = $config['charset'] ?? self::$charset;
        self::$debug = $config['debug'] ?? self::$debug;
        self::$multi_statements = $config['multi_statements'] ?? self::$multi_statements;

        Executor::setOptions($config);
    }

    public static function connect($config = [])
    {
        if (isset(self::$pdo)) {
            return new self();
        }

        $connection = Connection::instance();

        $host_key = $config['host'] ?? (self::$host ?: 'db_host');
        $name_key = $config['name'] ?? (self::$name ?: 'db_name');
        $username_key = $config['username'] ?? (self::$username ?: 'db_username');
        $password_key = $config['password'] ?? (self::$password ?: 'db_password');
        $charset_key = $config['charset'] ?? (self::$charset ?: 'db_charset');

        $host = Core::config("env", $host_key, 'localhost');
        $name = Core::config("env", $name_key);
        $username = Core::config("env", $username_key);
        $password = Core::config("env", $password_key);
        $charset = Core::config("env", $charset_key, 'utf8mb4');

        // senha vazia é válida (ex.: MySQL local); o próprio servidor recusa credenciais erradas
        if (!$name || !$username) {
            throw new Exception("Dados do banco de dados inválidos");
        }

        try {
            self::$pdo = $connection->connect([
                "db_host" => $host,
                "db_name" => $name,
                "db_username" => $username,
                "db_password" => $password,
                "db_charset" => $charset,
                "db_multi_statements" => self::$multi_statements
            ]);
        } catch (\Throwable $th) {

            // A mensagem do driver traz host/usuário do banco. Vai para o log; quem
            // chama recebe uma mensagem genérica (a menos que expose_errors esteja ligado).
            // Não encadeamos $th como "previous" para o stack trace não carregar credenciais.
            error_log('[DB] connection failed: ' . $th->getMessage());

            // Erros de validação da config (InvalidArgumentException) não carregam dados
            // sensíveis, então a mensagem é repassada para facilitar o diagnóstico.
            $show = Executor::option('expose_errors') || $th instanceof \InvalidArgumentException;

            throw new Exception($show ? $th->getMessage() : "Não foi possível conectar ao banco de dados");
        }

        return new self();
    }

    /**
     * @deprecated Não use para montar SQL: escape manual é frágil (depende do charset da
     *             conexão). Passe os valores como parâmetros (where/insert/update/DB::query),
     *             que usam prepared statements.
     */
    public static function escape($value="")
    {
        if ( is_null($value) || $value === "" ) return $value;

        return str_replace(array("\\", "\0", "\n", "\r", "\x1a", "'", '"'), array("\\\\", "\\0", "\\n", "\\r", "\Z", "\'", '\"'), $value);
    }
}
