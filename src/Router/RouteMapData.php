<?php

namespace App\Core\Router;

/**
 * Objeto contendo os dados da rota
 * para cada http método
 */
class RouteMapData
{
    public $raw = [];

    public function __construct($routeRawData=[]) {
        $this->raw = $routeRawData;
    }

    public function get()
    {
        return $this->setMethodsData("get");
    }

    public function post()
    {
        return $this->setMethodsData("post");
    }

    public function put()
    {
        return $this->setMethodsData("put");
    }

    public function delete()
    {
        return $this->setMethodsData("delete");
    }

    public function hasMethod($method="")
    {
        return isset($this->raw[$method]);
    }

    private function setMethodsData($method=""): object
    {
        $data = $this->raw[strtoupper($method)] ?? false;

        if ( !$data ) return (object)[];

        $data = (object)$data;
        $data->view = (object)($data->view ?: []);

        return $data;
    }

}