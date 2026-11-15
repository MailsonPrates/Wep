<?php

use Wep\Http\Csp;
use Wep\View\Template\Html;

afterEach(fn () => Csp::reset());

describe('Html::get', function () {

    it('escapa valores de atributo (antes um \' injetava atributos)', function () {
        $html = Html::get('a', ['href' => "/x' onmouseover='alert(1)", 'title' => '"><script>alert(1)</script>']);

        expect($html)->toBe('<a href="/x&apos; onmouseover=&apos;alert(1)" title="&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;"></a>');
    });

    it('atributos booleanos, nulos e arrays', function () {
        expect(Html::get('input', ['type' => 'checkbox', 'checked' => true, 'disabled' => false, 'x' => null]))
            ->toBe('<input type="checkbox" checked>')
            ->and(Html::get('div', ['data-config' => ['a' => 1]]))
            ->toBe('<div data-config="{&quot;a&quot;:1}"></div>');
    });

    it('recusa nomes de tag e de atributo inválidos', function () {
        expect(fn () => Html::get('div onload=alert(1)', []))->toThrow(InvalidArgumentException::class)
            ->and(fn () => Html::get('div', ['onclick="x" y' => 1]))->toThrow(InvalidArgumentException::class);
    });

    it('conteúdo continua sendo HTML (elementos aninhados)', function () {
        expect(Html::get('div', ['class' => 'a', 'html' => Html::get('span', 'ok')]))->toBe('<div class="a"><span>ok</span></div>')
            ->and(Html::get('p', 'texto'))->toBe('<p>texto</p>')
            ->and(Html::get('br', 'ignorado'))->toBe('<br>');
    });

    it('text() e escape() para conteúdo vindo de usuário', function () {
        expect(Html::text('p', '<img src=x onerror=alert(1)>', ['class' => 'n']))
            ->toBe('<p class="n">&lt;img src=x onerror=alert(1)&gt;</p>')
            ->and(Html::escape("a'b\"c&<"))->toBe('a&apos;b&quot;c&amp;&lt;');
    });

    it('json() não deixa fechar o <script> nem abrir comentário', function () {
        $json = Html::json(['nome' => '</script><script>alert(1)</script>', 'x' => '<!--', 'q' => "'\"&"]);

        expect($json)->not->toContain('<')
            ->and($json)->not->toContain('>')
            ->and($json)->not->toContain("'")
            ->and(json_decode($json, true))->toBe(['nome' => '</script><script>alert(1)</script>', 'x' => '<!--', 'q' => "'\"&"]);
    });
});

describe('documento', function () {

    it('o <title> é escapado (título com parâmetro da URL não injeta HTML)', function () {
        $page = (new Html(['title' => 'Pedido #</title><script>alert(1)</script>']))->build();

        expect($page)->toContain('<title>Pedido #&lt;/title&gt;&lt;script&gt;alert(1)&lt;/script&gt;</title>')
            ->and($page)->not->toContain('<script>alert(1)');
    });

    it('monta head e body', function () {
        $html = new Html(['title' => 'T', 'head' => [['link', ['rel' => 'stylesheet', 'href' => '/a.css']]]]);
        $html->append('body', [Html::get('main', ['id' => 'app'])]);

        expect($html->build())
            ->toStartWith('<!DOCTYPE html><html><head><title>T</title>')
            ->toContain('<link rel="stylesheet" href="/a.css">')
            ->toEndWith('<body><main id="app"></main></body></html>');
    });

    it('append() só aceita head ou body', function () {
        (new Html([]))->append('footer', ['x']);
    })->throws(InvalidArgumentException::class);
});

describe('CSP', function () {

    it('desligada por padrão; nonce só nos scripts quando ativa', function () {
        expect(Csp::header([]))->toBeNull()
            ->and(Html::get('script', ['src' => '/a.js']))->toBe('<script src="/a.js"></script>');

        Csp::activate();

        expect(Html::get('script', ['src' => '/a.js']))->toBe('<script src="/a.js" nonce="' . Csp::nonce() . '"></script>')
            ->and(Html::get('style', 'x'))->toBe('<style>x</style>');
    });

    it('política padrão com o nonce da requisição', function () {
        [$name, $value] = Csp::header(['csp' => true]);

        expect($name)->toBe('Content-Security-Policy')
            ->and($value)->toContain("script-src 'self' 'nonce-" . Csp::nonce() . "'")
            ->and($value)->toContain("object-src 'none'")
            ->and($value)->toContain("frame-ancestors 'self'");
    });

    it('fontes extras, report_only e saneamento de valores', function () {
        [$name, $value] = Csp::header(['csp' => [
            'style-src' => ['https://fonts.googleapis.com'],
            'script-src' => ['https://cdn.test; script-src *'],
            'report_only' => true,
        ]]);

        expect($name)->toBe('Content-Security-Policy-Report-Only')
            ->and($value)->toContain("style-src 'self' 'unsafe-inline' https://fonts.googleapis.com")
            ->and($value)->toContain('https://cdn.testscript-src*');   // ; e espaços removidos: não cria diretiva nova

        $directives = array_map(fn ($part) => strtok($part, ' '), explode('; ', $value));

        expect(array_count_values($directives)['script-src'])->toBe(1);
    });

    it('o nonce muda a cada requisição', function () {
        $a = Csp::nonce();
        Csp::reset();

        expect(Csp::nonce())->not->toBe($a)
            ->and(strlen(base64_decode(Csp::nonce(), true)))->toBe(16);
    });
});
