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
        $this->conditions = [];
        $this->joins = [];
        $this->duplicateUpdate = [];
        $this->id_debug = false;
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

}