<?php

namespace App\Core\DB\Query\Builders;

use App\Core\Obj;

class Operators
{

    /**
     * Registra aliases dos métodos de cada operador
     */
    private static $operator_methods_aliases = [
        
        // operadores de lógica
        "=" => "logic",
        "!=" => "logic",
        ">" => "logic",
        ">=" => "logic",
        "<" => "logic",
        "<=" => "logic",

        // operadores de valor
        "in" => "in",
        "not in" => "in",
        "like" => "like",
        "not like" => "like",
        "between" => "between",
        "not between" => "between",

        // operadores de tipo
        "is null" => "null",
        "is not null" => "null",
        "is not empty" => "null"

    ];

    /**
     * @param object $props
     * @param string $props->operator
     * @param string|array|int $props->value
     * @param string $props->type
     * 
     * @return object
     */
    static public function build($props)
    {
        $operator_method_name = self::$operator_methods_aliases[$props->operator] ?? null;

        if ( !$operator_method_name ){
            // TODO: lidar melhor com esse error
            exit();
        }

        $call_operator_method = call_user_func_array(
            ["self", $operator_method_name],
            [$props]
        );

        return $call_operator_method;
    }


    /**
     * @param object $props
     * @param string $props->operator
     * @param string|array|int|float $props->value
     * @param string $props->type
     * 
     * @return object
     */
    static private function logic($props)
    {
        $id = self::getPlaceholderId($props->col, $props->type);

        $placeholder_value = [];
        $placeholder_value[$id] = $props->value;

        return Obj::set([
            'string' => "$props->col $props->operator :$id",
            'string_raw' => "$props->col $props->operator $props->value",
            'placeholders' => $placeholder_value
        ]);
    }

    /**
     * @param string $col
     * @param string $type
     * 
     * @return string
     */
    static private function getPlaceholderId($col, $type)
    {
        return join("_", [$col, $type, uniqid()]);
    }

    /**
     * @param string $operator
     * @param string|array|int|float $value
     * @return string
     */
    static private function type($value="", $operator="")
    {
        return strtoupper($operator);
    }

    /**
     * @param object $props
     * @param string $props->operator
     * @param string|array|int|float $props->value
     * @param string $props->type
     * 
     * @return object
     */
    static private function in($props)
    {
        $list = is_array($props->value) ? $props->value : [$props->value];
        $has_not = $props->operator === "not in";

        $in_list = [];
        $in_list_raw = [];
        $placeholders = [];

        foreach( $list as $value ){
            $id = uniqid($has_not ? 'in_not_' : 'in_');
            $in_list[] = ":$id";
            $in_list_raw[] = $value;
            $placeholders[$id] = $value;
        }

        $in_type = $has_not ? "NOT IN" : "IN";
        $query_string = $props->col . " " . $in_type. "(" . join(',', $in_list) . ")";
        $query_string_raw = $props->col . " " . $in_type. "(" . join(',', $in_list_raw) . ")";

        return Obj::set([
            'string' => $query_string,
            'string_raw' => $query_string_raw,
            'placeholders' => $placeholders
        ]);
    }

    /**
     * @param object $props
     * @param string $props->operator
     * @param string|array|int|float $props->value
     * @param string $props->type
     * 
     * @return object
     */
    static private function like($props)
    {
        $has_not = $props->operator === "not like";
       
        $like_type = ($has_not ? "NOT LIKE" : "LIKE");
        $id = uniqid($has_not ? 'not_like_' : 'like_');
        $query_string = $props->col ." ". $like_type . " :$id";
        $query_string_raw = $props->col ." ". $like_type . " ". $props->value;

        $placeholders = [];
        $placeholders[$id] = $props->value;

        return Obj::set([
            'string' => $query_string,
            'string_raw' => $query_string_raw,
            'placeholders' => $placeholders
        ]);
    }

    /**
     * @param object $props
     * @param string $props->operator
     * @param string|array|int|float $props->value
     * @param string $props->type
     * 
     * @return object
     */
    static private function between($props)
    {
        $has_not = $props->operator === "not between";
        $values = $props->value ?? [];
        $value_start = $values[0] ?? "";
        $value_end = $values[1] ?? "";
       
        $between_type = ($has_not ? "NOT BETWEEN" : "BETWEEN");

        $start_id = uniqid('start_');
        $end_id = uniqid('end_');

        $query_string = $props->col ." ". $between_type . " :$start_id AND :$end_id";
        $query_string_raw = $props->col ." ". $between_type . " $value_start AND $value_end";

        $placeholders = [];
        $placeholders[$start_id] = $value_start;
        $placeholders[$end_id] = $value_end;

        return Obj::set([
            'string' => $query_string,
            'string_raw' => $query_string_raw,
            'placeholders' => $placeholders
        ]);
    }

    /**
     * @param object $props
     * @param string $props->operator
     * @param string $props->type
     * 
     * @return object
     */
    static private function null($props)
    {
        $types = [
            "is null" => "IS NULL",
            "is not null" => "IS NOT NULL",
            "is not empty" => "<>''"
        ];
        
        /** @todo tratar  */
        $null_type = $types[$props->operator];

        $query_string = $props->col ." ". $null_type;
        $query_string_raw = $query_string;

        $placeholders = [];

        return Obj::set([
            'string' => $query_string,
            'string_raw' => $query_string_raw,
            'placeholders' => $placeholders
        ]);
    }

}