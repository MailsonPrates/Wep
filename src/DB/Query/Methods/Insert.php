<?php

namespace App\Core\DB\Query\Methods;

use App\Core\DB\Query\Methods\MethodInterface;
use App\Core\Obj;

class Insert implements MethodInterface
{
    /**
     * @var array
     */
    private $duplicateUpdate = [];

    /**
     * @var string
     */
    private $table;   
    
    /**
     * @param array $props;
     * @param array $props->duplicateUpdate
     * @param string $props->table
     */
    public function __construct($props=[])
    {
        $props = (object) $props;

        $this->duplicateUpdate = $props->duplicateUpdate;
        $this->table = $props->table;
    }

    public function build(array $fields=[])
    {
        // Fields
        $fields_cols = array_keys($fields);
        $placeholders = [];
        $id = uniqid("_");
        $fields_cols_keys_list = [];

        foreach( $fields_cols as $col ){
            $key = $col.$id;
            $fields_cols_keys_list[] = $key;

            $placeholders[$key] = $fields[$col];
        }

        
        $fields_cols_joined = join(", ", $fields_cols);
        $fields_cols_keys = join(", ", preg_filter('/^/', ":", $fields_cols_keys_list));

        // Inicia montagem da query
        $query = "INSERT INTO $this->table ($fields_cols_joined) VALUES ($fields_cols_keys)";

        // On duplicate key update
        $duplicate_update_fields = $this->duplicateUpdate;
        $has_duplicate_update = !empty($duplicate_update_fields);
        
        if ( $has_duplicate_update ){

            // ->onDuplicateUpdate("name", "sobrenome");
            $is_direct_params = count($duplicate_update_fields) === 1 && is_array($duplicate_update_fields[0]);
            $duplicate_update_fields = $is_direct_params ?  $duplicate_update_fields[0] : $duplicate_update_fields;

            $duplicate_key = [];

            foreach( $duplicate_update_fields as $col ){
                $value = $fields[$col] ?? null;

                if ( !$value ) continue;

                $duplicate_key[] = "$col = :$col".$id;
            }

            $query .= " ON DUPLICATE KEY UPDATE ".join(", ", $duplicate_key);
        }


        $response = Obj::set([
            'string' => $query,
            "placeholders" => $placeholders,
            'duplicate_update_fields' => $this->duplicateUpdate
        ]);

        return $response;
    }


}