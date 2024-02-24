<?php

namespace Core\DB\Query\Methods;

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
}