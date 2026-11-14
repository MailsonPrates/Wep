<?php

namespace Wep\Http;

/**
 * Exceção com status HTTP (o código da exceção é o status). O ErrorHandler usa esse código
 * na resposta; fora de DEV o cliente recebe só a mensagem padrão do status.
 */
class HttpException extends \RuntimeException
{
    public function __construct(int $status, string $message = '', ?\Throwable $previous = null)
    {
        parent::__construct($message, $status, $previous);
    }

    public function status(): int
    {
        return $this->getCode();
    }
}
