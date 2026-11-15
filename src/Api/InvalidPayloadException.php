<?php

namespace Wep\Api;

use Wep\Http\HttpException;

/**
 * Payload do cliente fora do contrato do CRUD automático (status 400).
 * A mensagem é segura para devolver ao cliente.
 */
class InvalidPayloadException extends HttpException
{
    public function __construct(string $message)
    {
        parent::__construct(400, $message);
    }
}
