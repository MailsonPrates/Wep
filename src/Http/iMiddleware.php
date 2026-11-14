<?php

namespace Wep\Http;

interface iMiddleware 
{
    public function handle($request, $next);
}