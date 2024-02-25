<?php

namespace App\Core\DB\Query\Methods;

trait Aliases
{

    public function create()
    {
        return call_user_func_array([$this, "insert"], func_get_args());
    }

    public function select()
    {
        return call_user_func_array([$this, "get"], func_get_args());
    }

}