<?php

namespace Wep\DB\Query\Methods;

use Wep\DB\Query\Builders\Identifier;
use Wep\DB\Query\Builders\Placeholders;
use Wep\DB\Query\Methods\MethodInterface;
use Wep\Obj;
use InvalidArgumentException;

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

    /**
     * @throws InvalidArgumentException
     */
    public function build(array $fields=[])
    {
        $bag = new Placeholders();
        $cols = [];
        $marks = [];

        foreach( $fields as $col => $value ){
            $cols[] = Identifier::quote($col);
            $marks[] = $bag->add($value);
        }

        // Inicia montagem da query
        $query = "INSERT INTO " . Identifier::quote($this->table) 
            . " (" . implode(", ", $cols) . ") VALUES (" . implode(", ", $marks) . ")";

        // On duplicate key update
        $duplicate_update_fields = $this->duplicateUpdate;
        
        if ( !empty($duplicate_update_fields) ){

            // ->onDuplicateUpdate(["name", "sobrenome"]);
            $is_direct_params = count($duplicate_update_fields) === 1 && is_array($duplicate_update_fields[0] ?? null);
            $duplicate_update_fields = $is_direct_params ?  $duplicate_update_fields[0] : $duplicate_update_fields;

            $updates = [];

            foreach( $duplicate_update_fields as $col ){

                if ( !is_string($col) ){
                    throw new InvalidArgumentException("Invalid onDuplicateUpdate column");
                }

                // coluna fora do insert, ou sem valor (null/""), não sobrescreve o registro existente.
                // Atenção: 0 e "0" SÃO valores válidos (antes eram descartados por !$value).
                $value = $fields[$col] ?? null;

                if ( $value === null || $value === "" ) continue;

                $updates[] = Identifier::quote($col) . " = " . $bag->add($value);
            }

            // sem nada para atualizar, não gera a cláusula (antes gerava SQL inválido)
            if ( !empty($updates) ){
                $query .= " ON DUPLICATE KEY UPDATE " . implode(", ", $updates);
            }
        }

        return Obj::set([
            'string' => $query,
            "placeholders" => $bag->all(),
            'duplicate_update_fields' => $this->duplicateUpdate
        ]);
    }


}
