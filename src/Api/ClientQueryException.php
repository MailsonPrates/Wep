<?php

namespace Wep\Api;

use Wep\Http\HttpException;

/**
 * Payload do cliente recusado pelo ClientQuery (status 400). A mensagem é segura para
 * devolver ao cliente: não contém dados internos.
 */
class ClientQueryException extends HttpException
{
    public function __construct(string $message)
    {
        parent::__construct(400, $message);
    }
}
