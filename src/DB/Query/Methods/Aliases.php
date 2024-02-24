<?php

namespace Core\DB\Query\Methods;

trait Aliases
{

    public function create()
    {
        return call_user_func_array([$this, "insert"], func_get_args());
    }

}