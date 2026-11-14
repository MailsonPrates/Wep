<?php

namespace Wep\Http;

/**
 * Contrato dos middlewares.
 *
 * handle() deve chamar $next() para seguir e devolver true. Para barrar a requisição,
 * responda (ou redirecione) e encerre, ou lance uma Wep\Http\HttpException.
 */
interface Middleware
{
    public function handle($request, $next);
}
