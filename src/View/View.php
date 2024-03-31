<?php

namespace App\Core\View;

use App\Core\Core;
use App\Core\Str;
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

        /**
         * @todo pq nao tá funcionado?
         */
        //echo json_encode($request->all());
        //exit();

        $title = $route->title ?? '';

        $route->title = $this->paramTitle($title, $request);
        $route->document_title = ($title
            ? $title . ' | '
            : '') . Core::config('name|title') ?? 'App';

        $template_classname = $route->view_template ?? APP_TEMPLATES_NAMESPACE . 'Main';
        $template = new Template($template_classname, $route, $request);

        echo $template->build();
    }

    private function paramTitle($title, $request)
    {
        $title_has_params = str_contains($title, '{');

        if ( !$title || !$title_has_params ) return $title;

        $words = explode(' ', $title);

        foreach( $words as $word ){

            $key = Str::between($word, '{', '}');

            if ( !$key ) continue;

            $value = $request->{$key} ?? '';

            $title = str_replace('{' . $key . '}', $value, $title);
        }

        return $title;
    }
}