<?php

namespace App\Core\Env;

use App\Core\Env\IProcessor;

abstract class AbstractProcessor implements IProcessor
{
    /**
     * The value to process
     * @var string
     */
    protected $value;

    public function __construct(string $value)
    {
        $this->value = $value;
    }
}