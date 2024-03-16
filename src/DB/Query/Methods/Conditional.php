<?php 

namespace App\Core\DB\Query\Methods;

use App\Core\Obj;

trait Conditional
{

    private $type_operators = ["is null", "is not null", "is not empty"];

    public function where()
    {
        $args_count = func_num_args();

        if ( !$args_count ) return $this;

        $arg_0 = func_get_arg(0);
        $arg_1 = $args_count > 1 ? func_get_arg(1) : "";
        $arg_2 = $args_count > 2 ? func_get_arg(2) : "";

        $is_bulk = $args_count === 1 && is_array($arg_0);

        /*echo json_encode([
            'type' => "where", 
            'args' => func_get_args(), 
            'count' => $args_count, 
            'isBulk' => $is_bulk, 
            'arg0' => $arg_0, 
            'arg1' => $arg_1, 
            'arg2' => $arg_2
        ]);

       exit();*/

        $this->buildConditions("where", $is_bulk, $arg_0, $arg_1, $arg_2);

        return $this;
    }
    
    public function or()
    {
        $args_count = func_num_args();

        if ( !$args_count ) return $this;

        $arg_0 = func_get_arg(0);
        $arg_1 = $args_count > 1 ? func_get_arg(1) : "";
        $arg_2 = $args_count > 2 ? func_get_arg(2) : "";

        $is_bulk = $args_count === 1 && is_array($arg_0);
        $type = $is_bulk ? "orAnd" : "or";

        $this->buildConditions($type, $is_bulk , $arg_0, $arg_1, $arg_2);

        return $this;
    }

    public function andOr()
    {
        $args_count = func_num_args();

        if ( !$args_count ) return $this;

        $arg_0 = func_get_arg(0);
        $arg_1 = $args_count > 1 ? func_get_arg(1) : "";
        $arg_2 = $args_count > 2 ? func_get_arg(2) : "";

        $is_bulk = $args_count === 1 && is_array($arg_0);
        $type = "andOr";

        $this->buildConditions($type, $is_bulk , $arg_0, $arg_1, $arg_2);

        return $this;
    }

    /**
     * Monta lista de condição
     * @param string $type where|or
     * @param bool $is_bulk
     * @param string|array $arg_0
     * @param string $arg_1
     * @param string|array|int|null $arg_2
     * 
     * @return void
     */
    private function buildConditions($type, $is_bulk, $arg_0,  $arg_1,  $arg_2)
    {

        if ( $is_bulk ){
            
            $bulk = $arg_0;

            foreach( $bulk as $item ){

                $col = $item[0];
                $operator = $item[1] ?? "";
                $value = $item[2] ?? "";

                $condition = $this->getCondition($type, $col, $operator, $value);
                $this->setCondition($condition);
            }

            return;
        }

        $col = $arg_0;
        $operator =  $arg_1 ?? "";
        $value = $arg_2 ?? "";

        $condition = $this->getCondition($type, $col, $operator, $value);
        $this->setCondition($condition);
        return;
    }

    /**
     * Monta uma condição para adicionar na lista de condição
     * @param string $type
     * @param string $col
     * @param string $operator
     * @param string|array|int|null $value
     * 
     * @return object
     */
    private function getCondition($type, $col, $operator, $value)
    {
        $is_type_operator = in_array(strtolower($operator), $this->type_operators);
        $has_omitted_operator = !$value && !$is_type_operator;

        $value = $has_omitted_operator ? $operator : $value;
        $operator = $has_omitted_operator ? "=" : strtolower($operator);

        return Obj::set([
            "type" => $type,
            "col" => $col,
            "operator" => $operator,
            "value" => $value
        ]);
    }

    private function setCondition($condition)
    {
        $this->conditions[] = $condition;
    }

}