<?php

namespace Wep\Security;

/**
 * Tokens de uso único com validade (recuperação de senha, senha temporária, convites...).
 *
 * O token vai para o usuário (link/e-mail); no banco fica só o "selo": hash SHA-256 + expiração,
 * no formato "<sha256 hex>:<timestamp>". Quem lê o banco não consegue usar os tokens.
 *
 * @example
 * $token = Token::generate();
 * User::updateById($id, ['code' => Token::seal($token, 3600)]);   // envia $token por e-mail
 *
 * // ao receber o token de volta:
 * $user = User::get(['where' => [['code', 'like', Token::lookup($token)]]])->data[0] ?? null;
 * if ($user && Token::verify($user['code'], $token)) { ... }
 */
final class Token
{
    public static function generate(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }

    /**
     * Senha legível (sem caracteres ambíguos como 0/O e 1/l/I), para senhas temporárias.
     */
    public static function password(int $length = 12): string
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $max = strlen($alphabet) - 1;
        $password = '';

        for ($i = 0; $i < $length; $i++) {
            $password .= $alphabet[random_int(0, $max)];
        }

        return $password;
    }

    /**
     * Selo para guardar no banco: hash do token + expiração.
     */
    public static function seal(string $token, int $ttlSeconds): string
    {
        return self::hash($token) . ':' . (time() + $ttlSeconds);
    }

    /**
     * Padrão LIKE para localizar o selo de um token no banco (o hash só tem [0-9a-f]).
     */
    public static function lookup(string $token): string
    {
        return self::hash($token) . ':%';
    }

    /**
     * O token confere com o selo e ainda é válido?
     */
    public static function verify(?string $sealed, string $token): bool
    {
        $parts = self::parse($sealed);

        if ($parts === null || $token === '') {
            return false;
        }

        return $parts['expires_at'] >= time() && hash_equals($parts['hash'], self::hash($token));
    }

    /**
     * O selo existe e venceu?
     */
    public static function isExpired(?string $sealed): bool
    {
        $parts = self::parse($sealed);

        return $parts !== null && $parts['expires_at'] < time();
    }

    /**
     * @return array{hash: string, expires_at: int}|null
     */
    public static function parse(?string $sealed): ?array
    {
        if (!is_string($sealed) || !preg_match('/^([a-f0-9]{64}):(\d{1,12})$/', $sealed, $m)) {
            return null;
        }

        return ['hash' => $m[1], 'expires_at' => (int) $m[2]];
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
