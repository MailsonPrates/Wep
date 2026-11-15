<?php 

namespace Wep\DB;

use Wep\Singleton;

class Connection extends Singleton
{

    /**
     * @var \PDO|null
     */
    private $conn;

    /**
     * @param array $credentials db_host, db_name, db_username, db_password, db_charset,
     *                           db_timeout (opcional), db_multi_statements (opcional)
     *
     * @throws \InvalidArgumentException dados de conexão com formato inválido
     * @throws \PDOException
     */
    public function connect($credentials=[]) 
    {
        $this->conn = null;

        $host = (string) ($credentials['db_host'] ?? '');
        $db_name = (string) ($credentials['db_name'] ?? '');
        $charset = (string) ($credentials['db_charset'] ?? 'utf8mb4');

        // host/dbname/charset entram na string DSN, onde ";" separa parâmetros.
        // Validar evita DSN injection (ex.: db_name = "x;host=outro-servidor").
        if ( !preg_match('/^[A-Za-z0-9._-]+$/', $host) ) throw new \InvalidArgumentException("Invalid database host");
        if ( !preg_match('/^[A-Za-z0-9_$-]+$/', $db_name) ) throw new \InvalidArgumentException("Invalid database name");
        if ( !preg_match('/^[A-Za-z0-9_]+$/', $charset) ) throw new \InvalidArgumentException("Invalid database charset");

        $options = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_TIMEOUT => (int) ($credentials['db_timeout'] ?? 10),
        ];

        // Sem isso o MySQL aceita várias queries separadas por ";" numa só chamada
        // (o que transforma qualquer injection em "stacked queries").
        $multi_statements = self::mysqlAttribute('ATTR_MULTI_STATEMENTS');

        if ( $multi_statements !== null ){
            $options[$multi_statements] = (bool) ($credentials['db_multi_statements'] ?? false);
        }

        // O charset já vai no DSN: o "SET NAMES" que existia aqui era um round-trip
        // extra a cada conexão, sem efeito adicional.
        $this->conn = new \PDO(
            'mysql:host=' . $host . ';dbname=' . $db_name . ';charset=' . $charset,
            $credentials['db_username'] ?? null,
            $credentials['db_password'] ?? null,
            $options
        );

        return $this->conn;
    }

    /**
     * Constante do driver MySQL: PHP 8.4+ usa Pdo\Mysql::*, versões anteriores PDO::MYSQL_*
     */
    private static function mysqlAttribute(string $name)
    {
        foreach( ['Pdo\\Mysql::', 'PDO::MYSQL_'] as $prefix ){
            if ( defined($prefix . $name) ) return constant($prefix . $name);
        }

        return null;
    }
}
