<?php

namespace Core\DB\Query\Methods;

use Core\DB\Query\Methods\MethodInterface;
use Core\DB\Query\Builders\Condition;
use Core\Obj;

class Update implements MethodInterface
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

    public function build(array $fields=[])
    {
        // Inicia montagem da query
        $query = "UPDATE ".$this->table;
        $placeholders = [];
        $fields_query = [];

        // FIELDS
        $id = uniqid("_");

        foreach( $fields as $col => $value ){

            $key = $col.$id;

            $fields_query[] = "$col = :$key";
            $placeholders[$key] = $value;
        }

        $query .= " SET ".join(", ", $fields_query);

        // CONDITIONS
        $conditions_query = Condition::buildQuery($this->conditions);
        $placeholders = array_merge($placeholders, $conditions_query->placeholders ?? []);

        $query .= " ".$conditions_query->string;

        $response = Obj::set([
            'string' => $query,
            "placeholders" => $placeholders,
            //'conditions' => $this->conditions
        ]);

        return $response;
    }
}