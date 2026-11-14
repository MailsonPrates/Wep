<?php

namespace App\Core\DB\Query\Methods;

use App\Core\Obj;
use App\Core\DB\Query\Builders\Condition;
use App\Core\DB\Query\Builders\Identifier;
use App\Core\DB\Query\Builders\Placeholders;
use App\Core\DB\Query\Methods\MethodInterface;
use InvalidArgumentException;

/**
 * Monta query string select
 */
class Select implements MethodInterface
{

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
     * @throws InvalidArgumentException
     */
    public function build(array $fields=[])
    {
        $bag = new Placeholders();

        /**
         * FIELDS
         */
        $columns = empty($fields)
            ? "*"
            : implode(", ", array_map([Identifier::class, "select"], array_values($fields)));

        // Inicia montagem da query
        $query = ["SELECT " . $columns . " FROM " . Identifier::table($this->table)];

        $response = Obj::set([
            "filters" => $this->filters, 
            "conditions" => $this->conditions,
            "joins" => $this->joins,
            "fields_list" => [$columns],
            "placeholders" => []
        ]);

        /**
         * JOINS
         */
        foreach( $this->joins ?? [] as $join ){

            $on_query = [];

            foreach( $join["on"] as $on ){
                $on_query[] = Identifier::quote($on["source"]) . " = " . Identifier::quote($on["target"]);
            }

            $on_query_string = count($on_query) > 1
                ? "(" . implode(" AND ", $on_query) . ")"
                : $on_query[0];

            $query[] = "LEFT JOIN " . Identifier::table($join["table"]) . " ON " . $on_query_string;
        }

        /**
         * CONDITIONS
         */
        if ( !empty($this->conditions) ){
            $conditions_query = Condition::buildQuery($this->conditions, $bag);

            $response->conditions_query = $conditions_query;

            $query[] = $conditions_query->string;
        }

        /**
         * FILTERS
         */
        if ( !empty($this->filters) ){

            // Order By
            $orderBy = $this->filters["orderBy"] ?? [];

            if ( !empty($orderBy) ){

                $order = strtoupper($orderBy["order"] ?? "ASC");

                if ( !in_array($order, ["ASC", "DESC"], true) ){
                    throw new InvalidArgumentException("Invalid order direction");
                }

                $cols = implode(", ", array_map([Identifier::class, "order"], $orderBy["cols"]));

                $query[] = "ORDER BY $cols $order";
            }

            // Limit (+ Page)
            $limit = $this->filters["limit"] ?? [];

            if ( !empty($limit) ){

                $limit = array_values($limit);

                // o LIMIT não aceita bind em modo emulado: força inteiros >= 0
                if ( count($limit) > 2 ){
                    throw new InvalidArgumentException("Invalid limit");
                }

                foreach( $limit as $n ){
                    if ( !is_numeric($n) || (int) $n != $n || $n < 0 ){
                        throw new InvalidArgumentException("Invalid limit");
                    }
                }

                $limit = array_map("intval", $limit);
                $page = $this->filters["page"] ?? null;

                if ( !is_null($page) ){
                    $per_page = $limit[0];
                    $page_number = max(1, (int) $page);

                    $limit = [($page_number - 1) * $per_page, $per_page];
                }

                $query[] = "LIMIT " . implode(", ", $limit);
            }

        }

        /**
         * FINALIZA QUERY
         */
        $response->placeholders = $bag->all();
        $response->string = implode(" ", $query);

        return $response;
    }
}
