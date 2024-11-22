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
    private $classname;

    public function __construct($classname, $route, $request) {
        $this->request = $request; 
        $this->route = $route;
        $this->classname = $classname;
    }
    
    /**
     * Método responsável por retornar
     * uma nova instancia de um template
     */
    public function build()
    {
        $template_class = $this->classname;

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