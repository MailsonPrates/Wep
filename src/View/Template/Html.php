<?php

namespace App\Core\View\Template;

use App\Core\View\Template\HtmlElements;

/**
 * Class responsável por montar um html
 */
class Html
{
    use HtmlElements;

    /**
     * @var object
     */
    private $config;

    /**
     * @var array
     */
    private $elements = [];


    /**
     * @var array
     */
    private $head = [];

    /**
     * @var array
     */
    private $body = [];


    /**
     * @param array $config
     * @param string $config->title
     * @param string $config->viewport
     * @param string $config->robots
     * @param string $config->contentType
     * @param string $config->favicon
     * @param array $config->htmlProps
     * @param array $config->bodyProps
     * @param array $config->head
     * @param array $config->body
     */
    public function __construct($config=[]) {
        $this->config = (object) $config;

        $this->setDefaults();
    }


    /**
     * @param string $tag - any html element,
     * @param string|array|null $props -html element props
     * 
     * @return string element html
     */
    public static function get($tag="", $props=[])
    {
        $voidElements = [
            "area", "base", "br", "col", "command", "embed", "hr", 
            "img", "input", "keygen", "link", "meta", "param", 
            "source", "track", "wbr"
        ];

        $tag = str_replace(["<", ">"], "", $tag);
        $is_void_element = in_array($tag, $voidElements);
        $tag_open = "<$tag>";
        $tag_close = $is_void_element ? "" : "</$tag>";

        $props_type = gettype($props);

        if ( $props_type == "string" || $props_type == "null" ) return join("", [$tag_open, $props, $tag_close]);

        $props_list = [];
        $skip_props = ["html"];
        $content = $props["html"] ?? "";

        foreach( $props as $key=>$value ){

            $is_skip_props = in_array($key, $skip_props);

            if ( $is_skip_props ) continue;

            $props_list[] = "$key='$value'";
        }

        return join("", [
            "<$tag ",
            join(" ", $props_list),
            ">",
            $content,
            $tag_close
        ]);
    }
    
    
    /**
     * @param string $target head|body
     * @param array $elements
     */
    public function append(string $target="", array $elements=[])
    {
        $target_element_exist = isset($this->{$target});

        if ( !$target_element_exist ){
            self::get("strong", "[Error] Target element $target not found!");
        };

        $this->{$target} = array_merge($this->{$target}, $elements);
        return $this;
    }

    private function set($content="")
    {
        $this->elements[] = $content;
    }

    private function setDefaults()
    {
        $config = $this->config;

        $this->append("head", [
            self::get("title", $config->title ?? "Página sem título"),
            self::get("meta", [
                "name" => "viewport",
                "content" => $config->viewport ?? "width=device-width, initial-scale=1.0, maximum-scale=1.0"
            ]),
            self::get("meta", [
                "name" => "robots",
                "content" => $config->robots ?? "noindex, nofollow"
            ]),
            self::get("meta", [
                "http-equiv" => "Content-Type",
                "content" => $config->contentType ?? "text/html;charset=utf-8"
            ]),
            self::get("meta", [
                "http-equiv" => "X-UA-Compatible",
                "content" => "IE=edge"
            ]),
            self::get("link", [
                "rel" => "icon",
                "href" => $config->favicon ?? "/assets/images/favicon.png"
            ]),
            self::get("link", [
                "rel" => "apple-touch-icon",
                "href" => $config->favicon ?? "/assets/images/favicon.png"
            ])
        ]);

        $default_head = $this->config->head ?? [];
        $default_body = $this->config->body ?? [];

        $this->appendDefaults("head", $default_head);
        $this->appendDefaults("body", $default_body);
    }

    /**
     * @param string $target head|body
     * @param array $targetElements
     */
    private function appendDefaults($target, $targetElements=[])
    {
        $is_empty = count($targetElements) == 0;
        
        if ( $is_empty ) return;

        $elements = [];

        foreach( $targetElements as $index=>$item ){
            $element_item = $targetElements[$index];
            $tag = $element_item[0];
            $props = $element_item[1];

            $elements[] = self::get($tag, $props);
        }

        $this->append($target, $elements);
    }

    private function getHead()
    {
        $head = join("", $this->head);
        return self::get("head", $head);
    }

    private function getBody()
    {
        $body = join("", $this->body);

        return self::get("body", array_merge(
            ($this->config->bodyProps ?? []),
            [
                "html" => $body
            ]
        ));
    }

    public function build(): string
    {
        $this->set('<!DOCTYPE html>');

        $head = $this->getHead();
        $body = $this->getBody();
        $content = join("", [$head, $body]);

        $html = self::get("html", array_merge(
            ($this->config->htmlProps ?? []),
            [
                "html" => $content
            ]
        ));

        $this->set($html);

        return join("", $this->elements);
    }
}