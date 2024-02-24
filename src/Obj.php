<?php

namespace Core;

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
}