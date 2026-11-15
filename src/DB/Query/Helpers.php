<?php

namespace Wep\DB\Query;

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
        $this->is_debug = false;
    }

    /**
     * Verifica se array é associativo
     * (sem alocar range()/array_keys() como antes)
     * @param array $arr
     * @return bool
     */
    private function isAssoc($arr)
    {
        if ( !is_array($arr) ) return false;

        $i = 0;

        foreach( $arr as $key => $_ ){
            if ( $key !== $i++ ) return true;
        }

        return false;
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

                // lista de condições ou ["col" => valor]: vai como um único argumento (bulk)
                $is_bulk = (isset($arguments[0]) && is_array($arguments[0])) || $this->isAssoc($arguments);

                call_user_func_array([$this, $prop], $is_bulk ? [$arguments] : $arguments);
            }
        }

        if ( isset($props['fetch']) ){
            $this->fetch($props['fetch']);
        }

        if ( isset($props['raw']) ){
            $this->raw($props['raw']);
        }

        if ( isset($props['debug']) ){
            $this->debug($props['debug']);
        }
    }

}
