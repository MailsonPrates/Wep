<?php

namespace Wep\View\Template;

interface iTemplate
{
    public function build($route, $request, $template): string;
}