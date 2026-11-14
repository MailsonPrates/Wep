<?php

use Wep\Foundation\ErrorHandler;
use Wep\Router\Exceptions\NotFoundException;

/**
 * Renderiza a exceção capturando a resposta (sem enviar headers).
 *
 * @return array{body: string, status: int, type: string}
 */
function renderError(Throwable $e, bool $debug, array $server = []): array
{
    $backup = $_SERVER;
    $_SERVER = $server + ['REQUEST_URI' => '/pagina/'];
    $captured = [];

    ErrorHandler::configure($debug);
    ErrorHandler::setSender(function (string $body, int $status, string $type) use (&$captured) {
        $captured = compact('body', 'status', 'type');
    });

    try {
        ErrorHandler::render($e);
    } finally {
        ErrorHandler::setSender(null);
        ErrorHandler::configure(false);
        $_SERVER = $backup;
    }

    return $captured;
}

describe('ErrorHandler::render', function () {

    it('em produção responde HTML genérico, sem detalhes da exceção', function () {
        $r = renderError(new RuntimeException('SQLSTATE senha=123 em /var/www/app/src/X.php'), false);

        expect($r['status'])->toBe(500)
            ->and($r['type'])->toContain('text/html')
            ->and($r['body'])->toContain('Erro interno do servidor')
            ->and($r['body'])->not->toContain('senha=123')
            ->and($r['body'])->not->toContain('RuntimeException')
            ->and($r['body'])->not->toContain('X.php');
    });

    it('em DEV mostra classe, mensagem, arquivo:linha e trace, com HTML escapado', function () {
        $r = renderError(new RuntimeException('<script>alert(1)</script>'), true);

        expect($r['body'])->toContain('RuntimeException')
            ->and($r['body'])->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
            ->and($r['body'])->not->toContain('<script>alert(1)</script>')
            ->and($r['body'])->toContain('ErrorHandlerTest.php');
    });

    it('responde JSON em rotas de API', function () {
        $r = renderError(new RuntimeException('detalhe interno'), false, ['REQUEST_URI' => '/user/api/get/']);
        $json = json_decode($r['body'], true);

        expect($r['type'])->toContain('application/json')
            ->and($json)->toBe(['error' => true, 'message' => 'Erro interno do servidor', 'http_code' => 500]);
    });

    it('responde JSON quando o cliente pede (Accept ou X-Requested-With)', function () {
        expect(renderError(new RuntimeException('x'), false, ['HTTP_ACCEPT' => 'application/json'])['type'])
            ->toContain('application/json')
            ->and(renderError(new RuntimeException('x'), false, ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'])['type'])
            ->toContain('application/json');
    });

    it('JSON em DEV traz os detalhes', function () {
        $json = json_decode(renderError(new LogicException('falhou'), true, ['HTTP_ACCEPT' => 'application/json'])['body'], true);

        expect($json['message'])->toBe('falhou')
            ->and($json['exception'])->toBe(LogicException::class)
            ->and($json['line'])->toBeInt()
            ->and($json['trace'])->toBeArray();
    });

    it('usa o código HTTP das exceções do router', function () {
        $r = renderError(new NotFoundException('Route [/x] not found', 404), false);

        expect($r['status'])->toBe(404)
            ->and($r['body'])->toContain('Página não encontrada');
    });

    it('ignora códigos que não são HTTP (ex.: SQLSTATE do PDO)', function () {
        expect(ErrorHandler::statusOf(new RuntimeException('x', 1062)))->toBe(500)
            ->and(ErrorHandler::statusOf(new PDOException('x')))->toBe(500);
    });
});

describe('ErrorHandler: log', function () {

    it('registra exceções em storage/log/app-AAAA-MM-DD.log', function () {
        $dir = tempDir('log');

        ErrorHandler::configure(false, $dir);
        ErrorHandler::report(new RuntimeException('falha registrada', 0, new LogicException('causa original')));

        $log = file_get_contents($dir . '/app-' . date('Y-m-d') . '.log');

        expect($log)->toContain('ERROR: RuntimeException: falha registrada')
            ->and($log)->toContain('Causado por: LogicException: causa original');
    });

    it('warnings vão para o log e não interrompem a execução', function () {
        $dir = tempDir('log');
        ErrorHandler::configure(false, $dir);

        // register() usa E_ALL; o PHPUnit roda com um nível próprio
        $level = error_reporting(E_ALL);

        try {
            $handled = ErrorHandler::handleError(E_WARNING, 'algo estranho', 'arquivo.php', 10);
        } finally {
            error_reporting($level);
        }

        expect($handled)->toBeTrue()
            ->and(file_get_contents($dir . '/app-' . date('Y-m-d') . '.log'))->toContain('WARNING: algo estranho in arquivo.php:10');
    });

    it('em DEV devolve o warning para o PHP exibir', function () {
        ErrorHandler::configure(true, tempDir('log'));
        $level = error_reporting(E_ALL);

        try {
            expect(ErrorHandler::handleError(E_NOTICE, 'aviso', 'a.php', 1))->toBeFalse();
        } finally {
            error_reporting($level);
            ErrorHandler::configure(false);
        }
    });

    it('ignora erros suprimidos com @', function () {
        $dir = tempDir('log');
        ErrorHandler::configure(false, $dir);
        $level = error_reporting(E_ALL & ~E_WARNING);

        try {
            expect(ErrorHandler::handleError(E_WARNING, 'suprimido', 'a.php', 1))->toBeFalse()
                ->and(is_file($dir . '/app-' . date('Y-m-d') . '.log'))->toBeFalse();
        } finally {
            error_reporting($level);
        }
    });
});
