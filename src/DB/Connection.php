<?php 

namespace Core\DB;

use Core\Response;
use Core\Singleton;

class Connection extends Singleton
{

    // Production mode
    private $host;
    private $db_name;
    private $username;
    private $password;
    private $charset;
    private $conn;

    /**
     * @todo lidar com pegar dados default no env default
     */
    public function connect($credentials=[]) 
    {
        $this->conn = null;

        $this->host = $credentials['host'];
        $this->db_name = $credentials['db_name'];
        $this->username = $credentials['username'];
        $this->password = $credentials['password'];
        $this->charset = $credentials['charset'] ?? "utf8";

        try { 
            $this->conn = new \PDO('mysql:host=' . $this->host . ';dbname=' . $this->db_name.';charset='.$this->charset, $this->username, $this->password, array(
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES ".$this->charset
            ));
        } catch(\PDOException $e) {
            echo Response::json("error", $e->getMessage());
        }
        return $this->conn;
    }
}