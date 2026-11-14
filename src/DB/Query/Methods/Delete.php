<?php

namespace Wep\DB\Query\Methods;

use Wep\DB\Query\Methods\MethodInterface;
use Wep\DB\Query\Builders\Condition;
use Wep\DB\Query\Builders\Identifier;
use Wep\DB\Query\Builders\Placeholders;
use Wep\Obj;
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
