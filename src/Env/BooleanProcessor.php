<?php

namespace Wep\Env;

use Wep\Env\AbstractProcessor;

class BooleanProcessor extends AbstractProcessor
{
    public function canBeProcessed(): bool
    {
        $loweredValue = strtolower($this->value);

        return in_array($loweredValue, ['true', 'false'], true);
    }

    public function execute()
    {
        return strtolower($this->value) === 'true';
    }
}