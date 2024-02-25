<?php

namespace App\Core\DB\Query\Methods;

trait Others
{

    /**
     * @example
     * ->onDuplicateUpdate(["name", "sobrenome"]);
     * ->onDuplicateUpdate("name", "sobrenome");
     */
    public function onDuplicateUpdate()
    {
        $fields = func_get_args();
        $this->duplicateUpdate = array_merge($this->duplicateUpdate, $fields);
        return $this;
    }

    /**
     * ->fetch("assoc|obj|...");
     */
    public function fetch($mode="")
    {
        $this->fetch_mode = $mode;
        return $this;
    }

     /**
     * ->fetch("assoc|obj|...");
     */
    public function raw($mode=true)
    {
        $this->raw = $mode;
        return $this;
    }
}