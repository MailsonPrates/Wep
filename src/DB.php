<?php

namespace App\Core;

use App\Core\DB\Connection;
use App\Core\DB\Handler;
use App\Core\DB\Query\Executor;
use App\Core\DB\Query\Helpers;
use Exception;

class DB
{
    use Helpers;

    public static $pdo;
    private static $host = "";
    private static $name = "";
    private static $username = "";
    private static $password = "";
    private static $charset = "";
    private static $debug = false;

    /**
     * @param string $tableName
     * @param array $config
     * @param bool $config->debug
     */
    public static function table($tableName, $config=[]) 
    {
        $config = !is_array($config) ? (array)$config : $config;

        $config["table"] = $tableName;

        if ( isset($config["debug"]) && $config["debug"] ){
            self::$pdo = (object)[];

        } else {
            self::connect();
        }

        return new Handler(self::$pdo, $config);
    }

    public static function query($query_string, $fields=[])
    {
        if ( !$query_string ) return Response::error("Invalid query string");

        $has_placeholders = str_contains($query_string, ":");
        $has_fields = !empty($fields);

        if ( $has_placeholders && !$has_fields ) return Response::error("Missing placeholder values");

        if ( self::$debug ){

            $query_raw = $query_string;

            if ( $has_fields ){
                $query_raw = self::parseQueryRaw($query_string, $fields);
            }

            return (object)[
                "query_string" => $query_string,
                'query_raw' => $query_raw,
                "fields" => $fields
            ];
        } else {

            self::connect();

        }

        return Executor::execute(self::$pdo, $query_string, $fields);
    }

    public static function beginTransaction()
    {
        self::$pdo->beginTransaction();
        return new self();
    }

    public static function commit()
    {
        self::$pdo->commit();
        return new self();
    }

    public static function rollback()
    {
        self::$pdo->rollback();
        return new self();
    }

    /**
     * @param array $config
     * @param string $config->host
     * @param string $config->name
     * @param string $config->username
     * @param string $config->password
     * @param string $config->charset
     */
    public static function setConfig($config=[])
    {
        self::$host = $config['host'] ?? self::$host;
        self::$name = $config['name'] ?? self::$name;
        self::$username = $config['username'] ?? self::$username;
        self::$password = $config['password'] ?? self::$password;
        self::$charset = $config['charset'] ?? self::$charset;
        self::$debug = $config['debug'] ?? self::$debug;
    }

    public static function connect($config=[]) 
    {
        if ( isset(self::$pdo) ) new self();

        $connection = Connection::instance();

        $host_key = $config['host'] ?? self::$host ?? 'db_host';
        $name_key = $config['name'] ?? self::$name ?? 'db_name';
        $username_key = $config['username'] ?? self::$username ?? 'db_username';
        $password_key = $config['password'] ?? self::$password ?? 'db_password';
        $charset_key = $config['charset'] ?? self::$charset ?? 'db_charset';

        $host = Core::config("env", $host_key, 'localhost');
        $name = Core::config("env", $name_key);
        $username = Core::config("env", $username_key);
        $password = Core::config("env", $password_key);
        $charset = Core::config("env", $charset_key, 'utf8');

        if ( !$name || !$username || !$password )
            throw new Exception("Dados do banco de dados inválidos");
        
        self::$pdo = $connection->connect([
            "db_host" => $host,
            "db_name" => $name,
            "db_username" => $username,
            "db_password" => $password,
            "db_charset" => $charset
        ]);

        return new self();
    }
}