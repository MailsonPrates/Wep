<?php

namespace App\Core;

use App\Core\Router\Http\Request;

trait Controller
{
    public function handle(Request $request)
    {
        $this->request = $request;

        $data = array_merge($request->all(), $request->query());
        $route_data = $request->routeMapData();

       Response::json([
        'data' => $data,
        'route_data' => $route_data
       ]);
    }
}