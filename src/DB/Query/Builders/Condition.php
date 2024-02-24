<?php

namespace Core\DB\Query\Builders;

use Core\Obj;
use Core\DB\Query\Builders\Operators;

class Condition
{

    private static $placeholders = [];

    /**
     * @param array $conditions
     */
    static public function buildQuery($conditions=[])
    {
        self::$placeholders = [];

        $conditions_by_type= Obj::set();
        $conditions_order = [];

        foreach( $conditions as $item ){

            $item = (object) $item;
            $type = $item->type;
            $type = $aliases[$type] ?? $type;

            $condition_item = Operators::build($item);

            $conditions_by_type->{$type}[] = $condition_item;

            if ( !in_array($type, $conditions_order) ){
                $conditions_order[] = $type;
            }
        }

        $response = Obj::set([
            'string' => []
        ]);

        foreach( $conditions_order as $type ){
            $condition_data = $conditions_by_type->{$type} ?? null;

            if ( !$condition_data ) continue;

            $condition_item = call_user_func_array(array(__CLASS__, $type), array($condition_data));

            $response->string[] = $condition_item->query_string;

        }

        $response->string = join(" ", $response->string);
        $response->placeholders = self::$placeholders ?? [];

        return $response;
    }

    static private function where($conditions)
    {
        $query = [];
        $placeholder_values = [];
        $query_string = [];

        foreach( $conditions as $item ){
            $query_string[] = $item->string;
            $placeholder_values = array_merge($placeholder_values, $item->placeholders);

        }

        $query = "WHERE " . join(" AND ", $query_string);

        $response = Obj::set([
            "query_string" =>  $query
        ]);

        self::$placeholders = array_merge(self::$placeholders, $placeholder_values);

        return $response;
    }

    static private function or($conditions)
    {
        $query = [];
        $placeholder_values = [];
        $query_string = [];

        foreach( $conditions as $item ){
            $query_string[] = $item->string;
            $placeholder_values = array_merge($placeholder_values, $item->placeholders);
        }

        $query = "OR " . join(" OR ", $query_string);

        $response = Obj::set([
            "query_string" =>  $query
        ]);

        self::$placeholders = array_merge(self::$placeholders, $placeholder_values);

        return $response;
    }

    static private function in($conditions)
    {
        $query = [];
        $placeholder_values = [];
        $query_string = [];

        foreach( $conditions as $item ){
            $query_string[] = $item->string;
            $placeholder_values = array_merge($placeholder_values, $item->placeholders);
        }

        $query = join(" ", $query_string);

        $response = Obj::set([
            "query_string" =>  $query
        ]);

        self::$placeholders = array_merge(self::$placeholders, $placeholder_values);

        return $response;
    }

    static private function orAnd($conditions)
    {
        $query = [];
        $placeholder_values = [];
        $query_string = [];

        foreach( $conditions as $item ){
            $query_string[] = $item->string;
            $placeholder_values = array_merge($placeholder_values, $item->placeholders);
        }

        $query = "OR (" . join(" AND ", $query_string) . ")";

        $response = Obj::set([
            "query_string" =>  $query
        ]);

        self::$placeholders = array_merge(self::$placeholders, $placeholder_values);

        return $response;
    }

    static private function andOr($conditions)
    {
        $query = [];
        $placeholder_values = [];
        $query_string = [];

        foreach( $conditions as $item ){
            $query_string[] = $item->string;
            $placeholder_values = array_merge($placeholder_values, $item->placeholders);

        }

        $query = "AND (" . join(" OR ", $query_string) . ")";

        $response = Obj::set([
            "query_string" =>  $query
        ]);

        self::$placeholders = array_merge(self::$placeholders, $placeholder_values);

        return $response;
    }
}