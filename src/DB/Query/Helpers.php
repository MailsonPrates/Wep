<?php

namespace App\Core\DB\Query;

trait Helpers
{
    /**
     * Reseta props para evitar que os dados
     * de uma query seja usado dentro de outra
     */
    private function resetProps()
    {
        $this->filters = [];
        $this->fields = [];
        $this->conditions = [];
        $this->joins = [];
        $this->duplicateUpdate = [];
        $this->fetch_mode = "";
        $this->raw = false;
    }

    /**
     * Verifica se array é associativo
     * @param array $arr
     * @return bool
     */
    private function isAssoc($arr)
    {
        if (array() === $arr) return false;
        return array_keys($arr) !== range(0, count($arr) - 1);
    }

    private static function parseQueryRaw($query_string, $placeholders=[])
    {
        if ( empty($placeholders) ) return $query_string;

        $parts = explode(":", $query_string);
        $parsed = [];

        foreach( $parts as $index => $word ){

            if ( $index === 0 ){
                $parsed[] = $word;
                continue;
            };

            $key = explode(" ", $word)[0];
            $key = str_replace([",", ")"], "", $key);
            $key_value = $placeholders[$key] ?? $key;

            $parsed[] = str_replace($key, $key_value, $word);
        }

        $parsed = join("", $parsed);

        return $parsed;
    }

    /**
     * Recebe dado da query de forma verbosa e
     * adiciona os valores nos tipos de campos (fields, where...)
     */
    private function setVerbose($props=[])
    {
        $this->fields = $props['fields'] ?? [];

        $regular_cases = [
            'limit',
            'orderBy',
            'page'
        ];

        foreach( $regular_cases as $prop ){

            $arguments = $props[$prop] ?? null;

            if ( !is_null($arguments) ){
                $arguments = is_array($arguments) ? $arguments : [$arguments];
                call_user_func_array([$this, $prop], $arguments);
            }
        }

        if ( isset($props['leftJoin']) ){

            $arguments = $props['leftJoin'];
            $first_is_array = isset($arguments[0]) && is_array($arguments[0]);
            $is_assoc_array = $this->isAssoc($arguments);

            if ( $first_is_array || $is_assoc_array){
                $arguments = [$arguments];
            }

            /*echo json_encode([
                'isAssc' => $is_assoc_array,
                'first_is_array' => $first_is_array,
                'props' => $props['leftJoin'],
                'arguments' => $arguments
            ]);
            exit();*/

            call_user_func_array([$this, 'leftJoin'], $arguments);
        }

        $conditional_cases = [
            'where',
            'or',
            'andOr'
        ];

        foreach( $conditional_cases as $prop ){

            $arguments = $props[$prop] ?? null;

            if ( !is_null($arguments) ){
                $arguments = isset($props[$prop][0]) && is_array($props[$prop][0]) 
                    ? [$props[$prop]] 
                    : $props[$prop];
    
                call_user_func_array([$this, $prop], $arguments);
            }
        }
    }

}