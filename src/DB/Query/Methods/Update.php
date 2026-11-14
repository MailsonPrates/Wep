<?php

namespace App\Core\DB\Query\Methods;

use App\Core\DB\Query\Methods\MethodInterface;
use App\Core\DB\Query\Builders\Condition;
use App\Core\DB\Query\Builders\Identifier;
use App\Core\DB\Query\Builders\Placeholders;
use App\Core\Obj;
use InvalidArgumentException;

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

    /**
     * @throws InvalidArgumentException
     */
    public function build(array $fields=[])
    {
        if ( empty($fields) ){
            throw new InvalidArgumentException("Invalid params");
        }

        $bag = new Placeholders();

        // FIELDS
        $sets = [];

        foreach( $fields as $col => $value ){
            $sets[] = Identifier::quote($col) . " = " . $bag->add($value);
        }

        // CONDITIONS
        $conditions_query = Condition::buildQuery($this->conditions, $bag);

        return Obj::set([
            'string' => "UPDATE " . Identifier::quote($this->table) . " SET " . implode(", ", $sets) . " " . $conditions_query->string,
            "placeholders" => $bag->all()
        ]);
    }
}
