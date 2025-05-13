<?php

namespace App\Core\Http;

use GuzzleHttp\Psr7\Response as GuzResponse;

class Response extends GuzResponse
{
    public function __construct() {
        parent::__construct(...func_get_args());
    }
}