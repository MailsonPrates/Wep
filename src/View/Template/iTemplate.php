<?php

namespace App\Core\View\Template;

interface iTemplate
{
    public function build($route, $request, $template): string;
}