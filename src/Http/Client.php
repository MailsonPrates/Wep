<?php

namespace App\Core\Http;

use GuzzleHttp\Client as GuzClient;

class Client extends GuzClient
{
    public function __construct() {
        parent::__construct(...func_get_args());
    }
}