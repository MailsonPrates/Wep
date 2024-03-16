<?php

namespace App\Core\DB\Query\Methods;

trait Joins
{
    /**
     * $user->leftJoin("order", "order.id", "user.id");
     * $user->leftJoin("order", ["order.id", "user.id"], ["order.number", "user.number"]);
     * 
     * $user->leftJoin([
     *  ["order", "order.id", "user.id"],
     *  ["product", "order.product_id", "product.id"]
     * ]);
     * 
     * $user->leftJoin([
     *  "order" => ["order.id", "user.id"],
     *  "product" => ["order.product_id", "product.id"]
     * ]);
     * 
     * $user->leftJoin("order", "id", "user.id");
     */
    public function leftJoin()
    {
        $args_count = func_num_args();

        if ( !$args_count ) return $this;

        $args = func_get_args();
        $is_multiples_joins = is_array($args[0]);

        $join_list = $is_multiples_joins ? $args[0] : [$args];

        $joins = [];

        foreach( $join_list as $key => $join ){

            // "order" => ["order.id", "user.id"],
            $is_associative = is_string($key);
            $src_i = $is_associative ? 0 : 1;
            $target_i = $is_associative ? 1 : 2;

            $table = $is_associative ? $key : ($join[0] ?? "");
            $on_source = $join[$src_i] ?? "";
            $on_target = $join[$target_i] ?? "";

            if ( !$table || !$on_source || !$on_target ) continue;

            $join_item = [
                "table" => $table,
                "on" => []
            ];

            $is_multiples_conditions = is_array($on_source);

            if ( $is_multiples_conditions ){

                // retira o nome da tabela e usa o resto como
                // condição on
                $conditions = array_slice($join, 1);

                foreach( $conditions as $on ){

                    $source = $on[0] ?? "";
                    $target = $on[1] ?? "";

                    if ( !$target || !$source ) continue;

                    $join_item["on"][] = [
                        "source" => self::getSourceField($table, $source),
                        "target" => $target
                    ];
                }

            } else {

                $join_item["on"][] = [
                    "source" => self::getSourceField($table, $on_source),
                    "target" => $on_target
                ];
            }

            $joins[] = $join_item;
        }

        $this->joins = array_merge($this->joins, $joins);

        return $this;
    }

    private static function getSourceField($table, $field)
    {
        return str_contains($field, ".")
            ? $field
            : $table . '.' . $field; 
    }

}