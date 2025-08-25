<?php

namespace App\Core\DB\Query\Methods;

use App\Core\Obj;
use App\Core\DB\Query\Helpers;
use App\Core\DB\Query\Builders\Condition;
use App\Core\DB\Query\Methods\MethodInterface;

/**
 * Monta query string select
 */
class Select implements MethodInterface
{

    use Helpers;

    /**
     * @var array
     */
    private $filters;

    /**
     * @var array
     */
    private $conditions;

    /**
     * @var array
     */
    private $joins;    

    /**
     * @var string
     */
    private $table;

    /**
     * @param array $props;
     * @param array $props->filters
     * @param array $props->conditions
     * @param array $props->joins
     * @param string $props->table
     */
    public function __construct($props=[])
    {
        $props = (object) $props;

        $this->filters = $props->filters;
        $this->conditions = $props->conditions;
        $this->joins = $props->joins;
        $this->table = $props->table;
    }

    /**
     * 
     */
    public function build(array $fields=[])
    {
        $has_args = count($fields) > 0;
        
        /**
         * FIELDS
         */
        $fields_list = $has_args
            ? [join(", ", preg_filter('/^/', "", $fields))]
            : ["*"];

        // Inicia montagem da query
        $query = ["SELECT ".join(", ", $fields_list). " FROM ".$this->table];

        $response = Obj::set([
            "filters" => $this->filters, 
            "conditions" => $this->conditions,
            "joins" => $this->joins,
            "fields_list" => $fields_list,
            "placeholders" => []
        ]);
       

        /**
         * JOINS
         */

        $joins = $this->joins ?? [];
        $has_joins = !empty($joins);

        if ( $has_joins ){

            $joins_query = [];

            foreach( $joins as $join ){

                $table = $join["table"];
                $on_conditions = $join["on"];

                $on_query = [];

                foreach( $on_conditions as $on ){
                    $on_query[] = $on["source"] . " = " .$on["target"]; 
                }

                $has_multiple_ons = count($on_query) > 1;

                $on_query_string = " ON ";
                $on_query_string .= $has_multiple_ons ? "(" : "";
                $on_query_string .= join(" AND ", $on_query);
                $on_query_string .= $has_multiple_ons ? ")" : "";

                $joins_query[] = "LEFT JOIN ".$table . $on_query_string;
            }

            $query_string = join(" ", $joins_query);

            $query[] = $query_string;

        }

        /**
         * CONDITIONS
         */
        $has_conditions = !empty($this->conditions);

        if ( $has_conditions ){
            $conditions_query = Condition::buildQuery($this->conditions);

            $response->placeholders = array_merge($response->placeholders, $conditions_query->placeholders ?? []);
            $response->conditions_query = $conditions_query;

            if ( $has_joins ){
                //$query[] = "AND";
            }

            $query[] = $conditions_query->string;
        }


        /**
         * FILTERS
         */
        $has_filters = !empty($this->filters);

        if ( $has_filters ){

            // Order By

            $orderBy = $this->filters["orderBy"] ?? [];

            if ( !empty($orderBy) ){

                $cols = join(", ", $orderBy["cols"]);
                $order = $orderBy["order"] ?? "ASC";

                $query[] = "ORDER BY $cols $order";
            }

            // Page
            $page = $this->filters["page"] ?? null;

            // Limit
            $limit = $this->filters["limit"] ?? [];

            if ( !empty($limit) ){

                if ( !is_null($page) ){
                    $page_limit = $limit[0] ?? 10;
                    $page_number = (int) $page;
                    $limit_start = ($page_number * $page_limit) - $page_limit;
                    $limit_end = $page_limit;

                    $limit = [$limit_start, $limit_end];
                }

                $query[] = "LIMIT ".join(", ", $limit);
            }

        }


        /**
         * FINALIZA QUERY
         */

        $query = join(" ", $query);

        $response->string = $query;

        return $response;
    }
}