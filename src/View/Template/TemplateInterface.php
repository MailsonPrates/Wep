<?php

namespace App\Core\View\Template;

interface TemplateInterface
{
    public function build($route, $request, $template): string;
}