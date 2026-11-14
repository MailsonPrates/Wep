<?php

namespace App\Core\DB;

use App\Core\DB\Query\Executor;
use App\Core\DB\Query\Formatter;
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
use InvalidArgumentException;

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
    private $fields = [];

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
     * @var \PDO|null
     */
    public $pdo;

    /**
     * @var object
     */
    private $config;

    /**
     * @param \PDO|null $pdo
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
        $fields = is_array($props) ? $props : func_get_args();

        return $this->run(function() use ($props, $fields) {

            if ( $this->isAssoc($fields) ){
                $this->setVerbose($props);
                $fields = $this->fields;
            }

            $selectQuery = new Select([
                "filters" => $this->filters,
                "conditions" => $this->conditions,
                "joins" => $this->joins,
                "table" => $this->config->table
            ]);

            return $selectQuery->build($fields);
        });
    }

    public function insert($fields=[])
    {
        return $this->run(function() use ($fields) {

            $is_verbose = !empty($fields['fields']);

            if ( $is_verbose ){

                if ( !empty($fields['onDuplicateUpdate']) ){
                    $this->onDuplicateUpdate($fields['onDuplicateUpdate']);
                }

                $fields = $fields['fields'];
            }

            if ( empty($fields) ) throw new InvalidArgumentException("Invalid params");

            $insertQuery = new Insert([
                "table" => $this->config->table,
                "duplicateUpdate" => $this->duplicateUpdate
            ]);

            return $insertQuery->build($fields);
        });
    }

    public function update($key, $value=null)
    {
        return $this->run(function() use ($key, $value) {

            $fields = is_array($key) ? $key : [$key => $value];
            $is_verbose = $this->isAssoc($fields) && !empty($fields['where']);

            if ( $is_verbose ){
                $this->setVerbose($key);
                $fields = $this->fields;
            }

            if ( empty($this->conditions) ) throw new InvalidArgumentException("Insecure update, no where clause found");

            $updateQuery = new Update([
                "conditions" => $this->conditions,
                "table" => $this->config->table
            ]);

            return $updateQuery->build($fields);
        });
    }

    public function delete($key=null, $value=null)
    {
        return $this->run(function() use ($key, $value) {

            if ( $key ){

                if ( !is_array($key) ){
                    $this->where($key, $value);

                } elseif ( isset($key['where']) ){

                    // mesmas formas do update verboso: ['id', 1] ou [['id', 1], ['age', '>', 3]] ou ['id' => 1]
                    $where = $key['where'];
                    $is_bulk = is_array($where) && ((isset($where[0]) && is_array($where[0])) || $this->isAssoc($where));

                    $is_bulk ? $this->where($where) : call_user_func_array([$this, 'where'], (array) $where);

                } else {
                    $this->where($key);
                }
            }

            if ( empty($this->conditions) ) throw new InvalidArgumentException("Insecure delete, no where clause found");

            $deleteQuery = new Delete([
                "conditions" => $this->conditions,
                "table" => $this->config->table
            ]);

            return $deleteQuery->build();
        });
    }

    public function debug($bool=true)
    {
        $this->is_debug = $bool;
        return $this;
    }

    /**
     * Monta a query e executa. Erros de montagem (identificador/operador inválido,
     * update/delete sem where...) viram Response::error, como já era o padrão.
     *
     * @param callable $build deve retornar o objeto da query
     */
    private function run(callable $build)
    {
        try {
            $query = $build();
        } catch (InvalidArgumentException $e) {
            $this->resetProps();
            return Response::error($e->getMessage());
        }

        return $this->execute($query);
    }

    /**
     * @param object $query
     * @param string $query->string
     * @param array $query->placeholders
     */
    private function execute($query)
    {
        if ( isset($query->error) ) return $query;

        $fields = isset($query->placeholders) ? (array)$query->placeholders : [];

        try {

            $execute = Executor::execute($this->pdo ?? null, $query->string, $fields, [
                "fetch" => $this->fetch_mode,
                "raw" => $this->raw,
                'debug' => $this->is_debug
            ]);

            if ( $this->is_debug ){
                $query->raw = Formatter::interpolate($query->string, $fields);
                $query->execute = $execute;
                return $query;
            }

            return $execute;

        } finally {

            // mesmo com erro/exceção o estado não vaza para a próxima query
            if ( !$this->is_debug ) $this->resetProps();
        }
    }

}
