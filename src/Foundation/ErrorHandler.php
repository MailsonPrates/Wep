<?php

namespace Wep\Foundation;

use ErrorException;
use Throwable;

/**
 * Tratamento central de erros.
 *
 * - Exceções não capturadas e erros fatais: registrados em storage/log/app-AAAA-MM-DD.log e
 *   respondidos com JSON (requisições de API) ou com uma página HTML simples.
 *   Em DEV a resposta traz mensagem, arquivo:linha e stack trace; fora de DEV é sempre genérica.
 * - Warnings/notices/deprecations: apenas registrados no log (não interrompem a requisição).
 *   Em DEV o PHP continua exibindo como antes.
 */
final class ErrorHandler
{
    private const FATAL = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR;

    private const MESSAGES = [
        400 => 'Requisição inválida',
        401 => 'Não autenticado',
        403 => 'Acesso negado',
        404 => 'Página não encontrada',
        405 => 'Método não permitido',
        500 => 'Erro interno do servidor',
    ];

    private static bool $registered = false;

    private static bool $debug = false;

    private static ?string $logDir = null;

    /** @var (callable(string $body, int $status, string $contentType): void)|null */
    private static $sender = null;

    public static function register(bool $debug = false, ?string $logDir = null): void
    {
        self::configure($debug, $logDir);

        if (self::$registered) {
            return;
        }

        self::$registered = true;

        error_reporting(E_ALL);
        set_error_handler([self::class, 'handleError']);
        set_exception_handler([self::class, 'handleException']);
        register_shutdown_function([self::class, 'handleShutdown']);
    }

    public static function configure(bool $debug, ?string $logDir = null): void
    {
        self::$debug = $debug;

        if ($logDir !== null) {
            self::$logDir = rtrim($logDir, '/\\');
        }

        ini_set('display_errors', $debug ? '1' : '0');
        ini_set('log_errors', '1');
    }

    public static function isDebug(): bool
    {
        return self::$debug;
    }

    /**
     * Troca a forma de enviar a resposta (usado nos testes).
     *
     * @param (callable(string $body, int $status, string $contentType): void)|null $sender
     */
    public static function setSender(?callable $sender): void
    {
        self::$sender = $sender;
    }

    public static function handleError(int $level, string $message, string $file = '', int $line = 0): bool
    {
        // erro suprimido com @ (ou fora do error_reporting atual)
        if (!(error_reporting() & $level)) {
            return false;
        }

        self::log(self::levelName($level), "$message in $file:$line");

        // em DEV devolve para o handler padrão do PHP, que exibe o erro como sempre
        return !self::$debug;
    }

    public static function handleException(Throwable $e): void
    {
        self::report($e);
        self::render($e);
    }

    public static function handleShutdown(): void
    {
        $error = error_get_last();

        if (!$error || !($error['type'] & self::FATAL)) {
            return;
        }

        $exception = new ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']);

        self::report($exception);

        // em DEV o PHP já exibiu o fatal (display_errors=1)
        if (!self::$debug) {
            self::render($exception);
        }
    }

    public static function report(Throwable $e): void
    {
        $text = self::describe($e) . "\n" . $e->getTraceAsString();

        for ($previous = $e->getPrevious(); $previous; $previous = $previous->getPrevious()) {
            $text .= "\nCausado por: " . self::describe($previous);
        }

        self::log('ERROR', $text);
    }

    public static function render(Throwable $e): void
    {
        if (PHP_SAPI === 'cli' && self::$sender === null) {
            fwrite(STDERR, self::describe($e) . PHP_EOL . (self::$debug ? $e->getTraceAsString() . PHP_EOL : ''));
            return;
        }

        $status = self::statusOf($e);

        if (self::wantsJson()) {
            self::send(self::jsonBody($e, $status), $status, 'application/json; charset=utf-8');
            return;
        }

        self::send(self::htmlBody($e, $status), $status, 'text/html; charset=utf-8');
    }

    /**
     * Status HTTP da exceção: o código dela quando for 4xx/5xx (exceções do router usam 400/404/405),
     * senão 500.
     */
    public static function statusOf(Throwable $e): int
    {
        $code = $e->getCode();

        return is_int($code) && $code >= 400 && $code <= 599 ? $code : 500;
    }

    public static function wantsJson(): bool
    {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
        $requestedWith = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
        return str_contains($accept, 'application/json')
            || str_contains($contentType, 'application/json')
            || strcasecmp($requestedWith, 'XMLHttpRequest') === 0
            || str_contains(\Wep\Http\RequestPath::current(), '/api/');
    }

    private static function jsonBody(Throwable $e, int $status): string
    {
        $body = [
            'error' => true,
            'message' => self::$debug ? $e->getMessage() : self::publicMessage($status),
            'http_code' => $status,
        ];

        if (self::$debug) {
            $body['exception'] = get_class($e);
            $body['file'] = $e->getFile();
            $body['line'] = $e->getLine();
            $body['trace'] = explode("\n", $e->getTraceAsString());
        }

        return json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '{"error":true}';
    }

    private static function htmlBody(Throwable $e, int $status): string
    {
        $esc = fn (string $text) => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $title = $esc(self::publicMessage($status));
        $details = '';

        if (self::$debug) {
            $details = '<h2>' . $esc(get_class($e)) . '</h2>'
                . '<p class="msg">' . $esc($e->getMessage()) . '</p>'
                . '<p class="loc">' . $esc($e->getFile()) . ':' . $e->getLine() . '</p>'
                . '<pre>' . $esc($e->getTraceAsString()) . '</pre>';
        }

        return '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex, nofollow">'
            . "<title>$status | $title</title>"
            . '<style>body{font-family:system-ui,sans-serif;margin:0;padding:2rem 1rem;color:#222;background:#f6f6f6}'
            . 'main{max-width:960px;margin:0 auto}h1{font-size:1.5rem}h2{font-size:1rem;color:#b00020;margin-top:2rem}'
            . '.msg{font-size:1.1rem}.loc{color:#666;word-break:break-all}'
            . 'pre{background:#fff;border:1px solid #ddd;padding:1rem;overflow:auto;font-size:.8rem}</style>'
            . '</head><body><main>'
            . "<h1>$status · $title</h1>"
            . $details
            . '</main></body></html>';
    }

    private static function publicMessage(int $status): string
    {
        return self::MESSAGES[$status] ?? ($status >= 500 ? self::MESSAGES[500] : self::MESSAGES[400]);
    }

    private static function send(string $body, int $status, string $contentType): void
    {
        if (self::$sender !== null) {
            (self::$sender)($body, $status, $contentType);
            return;
        }

        if (!headers_sent()) {
            // descarta saída parcial (ex.: HTML pela metade antes de um fatal)
            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            http_response_code($status);
            header('Content-Type: ' . $contentType);
            header('Cache-Control: no-store');
        }

        echo $body;
    }

    private static function describe(Throwable $e): string
    {
        return get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
    }

    /**
     * Registra uma linha no log da aplicação (storage/log/app-AAAA-MM-DD.log).
     */
    public static function log(string $level, string $message): void
    {
        $line = '[' . date('Y-m-d H:i:s') . "] $level: $message" . PHP_EOL;

        if (self::$logDir !== null) {
            if (!is_dir(self::$logDir)) {
                @mkdir(self::$logDir, 0775, true);
            }

            if (@error_log($line, 3, self::$logDir . '/app-' . date('Y-m-d') . '.log')) {
                return;
            }
        }

        error_log(rtrim($line));
    }

    private static function levelName(int $level): string
    {
        return match ($level) {
            E_WARNING, E_USER_WARNING, E_CORE_WARNING, E_COMPILE_WARNING => 'WARNING',
            E_NOTICE, E_USER_NOTICE => 'NOTICE',
            E_DEPRECATED, E_USER_DEPRECATED => 'DEPRECATED',
            default => 'ERROR',
        };
    }
}
