<?php

namespace App\Core\DB;

use App\Core\DB\Query\Executor;
use App\Core\DB\Query\Methods\Filters;
use App\Core\DB\Query\Methods\Conditional;
use App\Core\DB\Query\Methods\Select;
use App\Core\DB\Query\Methods\Insert;
use App\Core\DB\Query\Methods\Update;
use App\Core\DB\Query\Methods\Delete;
use App\Core\DB\Query\Methods\Joins;
use App\Core\DB\Query\Methods\Others;
use App\Core\DB\Query\Methods\Aliases;
use App\Core\DB\Query\Helpers;
use App\Core\Response;

/**
 * Class monta as query strings de cada metodo
 * e executa a consulta
 */
class Handler
{
    use Filters;
    use Conditional;
    use Joins;
    use Others;
    use Aliases;
    use Helpers;

    /**
     * @var array
     */
    private $filters = [];

    /**
     * @var array
     */
    private $conditions = [];

    /**
     * @var array
     */
    private $joins = [];    

    /**
     * @var array
     */
    private $duplicateUpdate = [];
    
    /**
     * @var string
     */
    private $fetch_mode = "";

    
    /**
     * @var bool
     */
    private $is_debug = false;

    /**
     * @var bool
     */
    private $raw = false;

    /**
     * @var \PDO
     */
    private $pdo;

    /**
     * @var object
     */
    private $config;

    /**
     * @param \PDO $pdo
     * @param array $config
     * @param string $config->table
     */
    public function __construct($pdo, $config=[])
    {
        $this->pdo = $pdo;
        $this->config = (object)$config;
        $this->is_debug = $this->config->debug ?? false;
    }

    public function get($props=null)
    {
        $selectQuery = new Select([
            "filters" => $this->filters,
            "conditions" => $this->conditions,
            "joins" => $this->joins,
            "table" => $this->config->table
        ]);

        $this->resetProps();

        $fields = is_array($props) ? $props : (func_get_args() ?? null);

        $query = $selectQuery->build($fields);

        return $this->execute($query);
    }

    public function insert($fields=[])
    {
        if ( empty($fields) ) return Response::error("Invalid params");

        $insertQuery = new Insert([
            "debug" => $this->is_debug,
            "table" => $this->config->table,
            "duplicateUpdate" => $this->duplicateUpdate
        ]);

        $this->resetProps();

        $query = $insertQuery->build($fields);

        return $this->execute($query);
    }

    public function update($key, $value=null)
    {
        if ( empty($this->conditions) ) return Response::error("Insecure update, no where clause found");

        $updateQuery = new Update([
            "conditions" => $this->conditions,
            "table" => $this->config->table
        ]);

        $this->resetProps();

        $fields = is_array($key) ? $key : [$key => $value];

        $query = $updateQuery->build($fields);

        return $this->execute($query);
    }

    public function delete($key=null, $value=null)
    {
        if ( $key ){
            is_array($key)
                ? $this->where($key)
                : $this->where($key, $value);
        }

        if ( empty($this->conditions) ) return Response::error("Insecure delete, no where clause found");

        $updateQuery = new Delete([
            "conditions" => $this->conditions,
            "table" => $this->config->table
        ]);

        $this->resetProps();

        $query = $updateQuery->build();

        return $this->execute($query);
    }

    public function debug($bool=true)
    {
        $this->is_debug = $bool;
        return $this;
    }

    /**
     * @param object $query
     * @param string $query->type
     * @param string $query->string
     * @param array $query->fields
     */
    private function execute($query)
    {
        /**
         * @debug
         */
        if ( $this->is_debug ){
            $query->raw = $this::parseQueryRaw($query->string, $query->placeholders);
            return $query;
        }

        return Response::success("Query executada!");

        $pdo = $this->pdo ?? null;

        if ( isset($query->error) ) return $query;

        $fields = $query->fields ? (array) $query->fields : [];
       
        return Executor::execute($pdo, $query->string, $fields, [
            "fetch" => $this->fetch_mode,
            "raw" => $this->raw
        ]);
    }

}