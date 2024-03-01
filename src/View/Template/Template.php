<?php

namespace App\Core\View\Template;

use App\Core\View\Template\Html;


class Template
{
    /**
     * @var RouteData
     */
    private $route;

    /**
     * @var Request
     */
    private $request;

    /**
     * @var string
     */
    private $name;

    public function __construct($name, $route, $request) {
        $this->request = $request; 
        $this->route = $route;
        $this->name = $name;
    }
    
    /**
     * Método responsável por retornar
     * uma nova instancia de um template
     */
    public function build()
    {
        $template_class = APP_TEMPLATES_NAMESPACE.$this->name;

        if ( !class_exists($template_class) ){
            echo "Classe não existe: $template_class";
            exit();
        }

        $template = new $template_class();

        return $template->build($this->route, $this->request, $this);
    }

    /**
     * @return Html
     */
    public function html($props=[])
    {
        $props["head"] = $props["head"] ?? [];
        $props["body"] = $props["body"] ?? [];

        return new Html($props);
    }
}