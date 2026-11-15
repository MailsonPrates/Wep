<?php

namespace Wep\View\Template;

use Wep\View\Template\Html;


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

        // antes: echo do nome da classe + exit (ignorava o ErrorHandler)
        if ( !class_exists($template_class) ){
            throw new \RuntimeException("Template não encontrado: $template_class");
        }

        if ( !is_subclass_of($template_class, iTemplate::class) ){
            throw new \RuntimeException("O template $template_class precisa implementar " . iTemplate::class);
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