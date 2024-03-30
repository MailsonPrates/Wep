<?php

namespace App\Core\View;

use App\Core\View\Template\Template;

class View
{

    /**
     * Método que lida com as views default,
     * quando não foi definido na config da rota
     * um handle específico
     * @param Request $request
     */
    public function handle($request)
    {
        $route = $request->routeMapData();

        $template_classname = $route->view_template ?? APP_TEMPLATES_NAMESPACE . 'Main';
        $template = new Template($template_classname, $route, $request);

        echo $template->build();
    }
}