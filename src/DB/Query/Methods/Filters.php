<?php

namespace Core\DB\Query\Methods;

trait Filters
{

    private function setFilter($type, $value)
    {
        $this->filters[$type] = $value;
    }

    /**
     * @example
     * ->limit(1);
     * ->limit(0, 20);
     * ->limit([0, 20])
     */
    public function limit($limit=null)
    {
        $range = func_get_args();

        if ( empty($range) ) return $this;

        $range = is_array($range[0]) ? $range[0] : $range;

        $this->setFilter("limit", $range);

        return $this;
    }

    /**
     * @example
     * ->page(2)
     */
    public function page($page=null)
    {
        if ( $page ){
            $this->setFilter("page", $page);
        }

        return $this;
    }

    /**
     * @example
     * ->orderBy("nome");
     * ->orderBy("nome", "DESC");
     * ->orderBy("nome", "sobrenome", "DESC");
     * ->orderBy(["nome", "sobrenome"]);
     * ->orderBy(["nome", "sobrenome"], "ASC");
     */
    public function orderBy()
    {
        $args_count = func_num_args();

        if ( !$args_count ) return $this;

        $orders = ["asc", "desc"];
        $args_list = func_get_args();

        $order = "ASC";
        
        if ( $args_count > 1 ){
            
            $last_arg = $args_list[$args_count -1];
            $is_last_an_order = is_string($last_arg) && in_array(strtolower($last_arg), $orders);

            if ( $is_last_an_order ){
                $order = strtoupper($last_arg);
                $args_list = array_slice($args_list, 0, -1);
            }
        }

        $first_arg_is_array = is_array($args_list[0]);
        $cols = $first_arg_is_array ? $args_list[0] : $args_list;

        if ( !empty($cols) ){
            $this->setFilter("orderBy", [
                "cols" => $cols,
                "order" => $order
            ]);
        }

        return $this;
    }    

}