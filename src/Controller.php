<?php

namespace App\Core;

use App\Core\Router\Http\Request;

trait Controller
{
    public function handle(Request $request)
    {

       Response::json([
        'data' => $request->data(),
        'route_data' => $request->routeMapData(),
       ]);
    }
}