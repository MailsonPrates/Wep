<?php

namespace App\Core;

class Obj
{
    /**
     * Transforma array de dados em objeto,
     * normalizando o parametro props
     * @param array|object $props
     * @return object
     */
    public static function set($props=[])
    {
        return is_array($props) ? (object) $props : $props;
    }

    public static function get($data=[], $keys="", $fallback_key=null)
    {
        $has_dots = str_contains($keys, ".");

        if ( !$has_dots ) return $data[$keys] ?? (
            $fallback_key
                ? (isset($data[$fallback_key]) ? $data[$fallback_key] : null)
                : null
        );

         // Divida as chaves usando o ponto como delimitador
        $keys = explode('.', $keys);

        // Inicialize o valor atual com os dados fornecidos
        $currentValue = $data;

        // Itere sobre as chaves
        foreach ($keys as $key) {
            // Verifique se $currentValue é um array ou um objeto e se a chave existe
            if (is_array($currentValue) && array_key_exists($key, $currentValue)) {
                $currentValue = $currentValue[$key];
            } elseif (is_object($currentValue) && property_exists($currentValue, $key)) {
                $currentValue = $currentValue->$key;
            } else {
                // Se a chave não existir, retorna o default ou retorne null
                return $fallback_key
                    ? self::get($data, $fallback_key)
                    : null;
            }
        }

        return $currentValue;
    }
}