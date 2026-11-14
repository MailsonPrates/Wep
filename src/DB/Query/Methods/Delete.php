<?php

namespace App\Core\DB\Query\Methods;

use App\Core\DB\Query\Methods\MethodInterface;
use App\Core\DB\Query\Builders\Condition;
use App\Core\DB\Query\Builders\Identifier;
use App\Core\DB\Query\Builders\Placeholders;
use App\Core\Obj;
use InvalidArgumentException;

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

    /**
     * @throws InvalidArgumentException
     */
    public function build()
    {
        $bag = new Placeholders();

        $conditions_query = Condition::buildQuery($this->conditions, $bag);

        return Obj::set([
            'string' => "DELETE FROM " . Identifier::quote($this->table) . " " . $conditions_query->string,
            "placeholders" => $bag->all(),
            'condition_query' => $conditions_query,
            'conditions' => $this->conditions
        ]);
    }
}
