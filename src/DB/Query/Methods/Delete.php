<?php

namespace App\Core\DB\Query\Methods;

use App\Core\DB\Query\Methods\MethodInterface;
use App\Core\DB\Query\Builders\Condition;
use App\Core\Obj;

class Delete implements MethodInterface
{

    /**
     * @var string
     */
    private $table;   

    /**
     * @var array
     */
    private $conditions = [];    
    
    /**
     * @param array $props;
     * @param string $props->table
     */
    public function __construct($props=[])
    {
        $props = (object) $props;

        $this->table = $props->table;
        $this->conditions = $props->conditions;
    }

    public function build()
    {
        // Inicia montagem da query
        $query = "DELETE FROM ".$this->table;
        $placeholders = [];

        // CONDITIONS
        $conditions_query = Condition::buildQuery($this->conditions);
        $placeholders = array_merge($placeholders, $conditions_query->placeholders ?? []);

        $query .= " ".$conditions_query->string;

        $response = Obj::set([
            'string' => $query,
            "placeholders" => $placeholders,
            'condition_query' => $conditions_query,
            'conditions' => $this->conditions
        ]);

        return $response;
    }
}