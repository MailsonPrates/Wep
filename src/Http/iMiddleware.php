<?php

namespace App\Core\Http;

interface iMiddleware 
{
    public function handle($request, $next);
}