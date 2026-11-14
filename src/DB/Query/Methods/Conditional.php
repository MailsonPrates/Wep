<?php

namespace App\Core\DB\Query\Methods;

use InvalidArgumentException;

trait Conditional
{

    private $type_operators = ["is null", "is not null", "is not empty"];

    /**
     * @example
     * ->where("id", 1);
     * ->where("age", ">", 18);
     * ->where("deleted_at", "is null");
     * ->where([["id", 1], ["age", ">", 18]]);
     * ->where(["status" => "on", "type" => "admin"]);
     */
    public function where()
    {
        $this->addConditions("where", func_get_args());
        return $this;
    }

    public function or()
    {
        $args = func_get_args();
        $is_bulk = count($args) === 1 && is_array($args[0]);

        $this->addConditions($is_bulk ? "orAnd" : "or", $args);
        return $this;
    }

    public function andOr()
    {
        $this->addConditions("andOr", func_get_args());
        return $this;
    }

    /**
     * Monta lista de condição
     * @param string $type where|or|orAnd|andOr
     * @param array $args argumentos recebidos pelo método público
     *
     * @return void
     */
    private function addConditions($type, array $args)
    {
        $args_count = count($args);

        if (!$args_count) return;

        $is_bulk = $args_count === 1 && is_array($args[0]);

        if ( !$is_bulk ){
            $this->setCondition($this->getCondition($type, $args[0], $args[1] ?? "", $args[2] ?? "", $args_count > 2));
            return;
        }

        foreach( $args[0] as $key => $item ){

            // ["status" => "on"]
            if ( is_string($key) && !is_array($item) ){
                $this->setCondition($this->getCondition($type, $key, $item, "", false));
                continue;
            }

            // ["status" => [1, 2]] ou [["status", "on"], ["age", ">", 18]]
            if ( !is_array($item) ){
                throw new InvalidArgumentException("Invalid condition");
            }

            $has_key = is_string($key);
            $col = $has_key ? $key : ($item[0] ?? "");
            $operator = $has_key ? $item : ($item[1] ?? "");
            $value = $has_key ? "" : ($item[2] ?? "");
            $has_value = !$has_key && count($item) > 2;

            $this->setCondition($this->getCondition($type, $col, $operator, $value, $has_value));
        }
    }

    /**
     * Monta uma condição para adicionar na lista de condição
     * @param string $type
     * @param string $col
     * @param string|array $operator
     * @param string|array|int|null $value
     * @param bool $has_value se o valor foi informado de fato (3º argumento)
     *
     * @return array
     */
    private function getCondition($type, $col, $operator, $value, $has_value)
    {
        $operator_name = is_string($operator) ? strtolower(trim($operator)) : $operator;
        $is_type_operator = is_string($operator_name) && in_array($operator_name, $this->type_operators, true);

        // where("col", "valor"): operador omitido, vale "=".
        // Decidido pela QUANTIDADE de argumentos e não pelo valor: antes, valores
        // como 0, "" ou null eram confundidos com "omitido" (where("stock", ">", 0)
        // virava stock = ">").
        $has_omitted_operator = !$has_value && !$is_type_operator;

        if ( $has_omitted_operator ){
            $value = $operator;
            $operator_name = is_array($value) ? "in" : "=";
        }

        return [
            "type" => $type,
            "col" => $col,
            "operator" => $operator_name,
            "value" => $value
        ];
    }

    private function setCondition($condition)
    {
        $this->conditions[] = $condition;
    }

}
