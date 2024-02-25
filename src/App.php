<?php

namespace App\Core;

class App
{

    public static function create($configure=[])
    {
        return new self();
    }

    public function run()
    {
        echo "App runing";
    }
}