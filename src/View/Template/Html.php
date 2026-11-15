<?php

namespace Wep\View\Template;

use Wep\Http\Csp;

/**
 * Monta um documento HTML.
 *
 * Escape:
 * - valores de atributo são SEMPRE escapados (antes iam crus: um ' injetava atributos);
 * - o <title> é escapado;
 * - o conteúdo dos elementos (get('div', $conteudo) e 'html' => ...) é HTML: é assim que os
 *   elementos aninhados são montados. Para texto vindo de usuário/banco use Html::text() ou
 *   Html::escape(); para dados num <script>, Html::json().
 *
 * Com a CSP ligada (Wep\Http\Csp), todo <script> recebe o nonce da requisição.
 */
class Html
{
    use HtmlElements;

    private const VOID = [
        "area", "base", "br", "col", "command", "embed", "hr",
        "img", "input", "keygen", "link", "meta", "param",
        "source", "track", "wbr"
    ];

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
     * Monta um elemento.
     *
     * @param string $tag qualquer elemento html
     * @param string|array|null $props conteúdo (HTML) ou atributos; a chave 'html' é o conteúdo (HTML)
     *
     * @return string elemento html
     *
     * @example
     * Html::get('div', ['class' => 'x', 'html' => Html::get('span', 'ok')]);   // <div class="x"><span>ok</span></div>
     * Html::get('input', ['type' => 'checkbox', 'checked' => true]);           // <input type="checkbox" checked>
     */
    public static function get($tag="", $props=[])
    {
        $tag = strtolower(trim(str_replace(["<", ">"], "", (string) $tag)));

        if ( !preg_match('/^[a-z][a-z0-9-]*$/', $tag) ){
            throw new \InvalidArgumentException("Tag HTML inválida: $tag");
        }

        $is_void_element = in_array($tag, self::VOID, true);
        $tag_close = $is_void_element ? "" : "</$tag>";

        if ( !is_array($props) ){
            $props = ['html' => (string) ($props ?? '')];
        }

        $content = (string) ($props["html"] ?? "");
        unset($props["html"]);

        if ( $tag === 'script' && Csp::isActive() && !isset($props['nonce']) ){
            $props['nonce'] = Csp::nonce();
        }

        return "<$tag" . self::attributes($props) . ">" . ($is_void_element ? "" : $content) . $tag_close;
    }

    /**
     * Elemento com conteúdo de TEXTO (escapado).
     *
     * @example Html::text('p', $usuario['nome'], ['class' => 'nome']);
     */
    public static function text($tag, $text, array $attributes = []): string
    {
        return self::get($tag, $attributes + ['html' => self::escape($text)]);
    }

    /**
     * Escapa texto para HTML (conteúdo ou atributo).
     */
    public static function escape($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    /**
     * JSON seguro para usar dentro de <script>: <, >, &, ' e " viram < etc., então o valor
     * não consegue fechar o script (</script>) nem abrir comentário (<!--).
     *
     * @example Html::get('script', ['html' => 'window.App = ' . Html::json($dados)]);
     */
    public static function json($data): string
    {
        return json_encode(
            $data,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR
        ) ?: 'null';
    }

    /**
     * @param string $target head|body
     * @param array $elements
     */
    public function append(string $target="", array $elements=[])
    {
        if ( !in_array($target, ['head', 'body'], true) ){
            throw new \InvalidArgumentException("Html::append: o alvo deve ser head ou body ($target)");
        }

        $this->{$target} = array_merge($this->{$target}, $elements);
        return $this;
    }

    /**
     * Atributos escapados. true vira atributo booleano, false/null é omitido, array vira JSON.
     */
    private static function attributes(array $props): string
    {
        $attributes = '';

        foreach( $props as $name => $value ){

            $name = (string) $name;

            if ( !preg_match('/^[A-Za-z_:@][A-Za-z0-9_:.@-]*$/', $name) ){
                throw new \InvalidArgumentException("Atributo HTML inválido: $name");
            }

            if ( $value === false || $value === null ) continue;

            if ( $value === true ){
                $attributes .= " $name";
                continue;
            }

            if ( is_array($value) || is_object($value) ){
                $value = json_encode($value);
            }

            $attributes .= " $name=\"" . self::escape($value) . "\"";
        }

        return $attributes;
    }

    private function set($content="")
    {
        $this->elements[] = $content;
    }

    private function setDefaults()
    {
        $config = $this->config;

        $this->append("head", [
            self::text("title", $config->title ?? "Página sem título"),
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
     * @param array $targetElements [[tag, props], ...]
     */
    private function appendDefaults($target, $targetElements=[])
    {
        if ( empty($targetElements) ) return;

        $elements = [];

        foreach( $targetElements as $item ){
            $elements[] = self::get($item[0], $item[1] ?? []);
        }

        $this->append($target, $elements);
    }

    private function getHead()
    {
        return self::get("head", join("", $this->head));
    }

    private function getBody()
    {
        return self::get("body", array_merge(
            ($this->config->bodyProps ?? []),
            [
                "html" => join("", $this->body)
            ]
        ));
    }

    public function build(): string
    {
        $this->set('<!DOCTYPE html>');

        $html = self::get("html", array_merge(
            ($this->config->htmlProps ?? []),
            [
                "html" => $this->getHead() . $this->getBody()
            ]
        ));

        $this->set($html);

        return join("", $this->elements);
    }
}
