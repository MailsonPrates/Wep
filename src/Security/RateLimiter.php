<?php

namespace Wep\Security;

use Wep\Core;

/**
 * Limite de tentativas por chave (ex.: "login:usuario|ip"), guardado em arquivo.
 *
 * @example
 * $limiter = RateLimiter::make();
 * $key = 'login:' . strtolower($username) . '|' . $request->ip();
 *
 * if ($limiter->tooManyAttempts($key, 5)) {
 *     return Response::error('Muitas tentativas. Tente novamente em ' . $limiter->availableIn($key) . 's');
 * }
 *
 * if (!$senhaCorreta) $limiter->hit($key, 900);   // janela de 15 min
 * else $limiter->clear($key);
 */
final class RateLimiter
{
    public function __construct(private string $dir)
    {
    }

    /**
     * Limiter da aplicação (storage/cache/rate-limit).
     */
    public static function make(): self
    {
        return new self(Core::paths()->cache('rate-limit'));
    }

    public function tooManyAttempts(string $key, int $maxAttempts): bool
    {
        return $this->attempts($key) >= $maxAttempts;
    }

    /**
     * Registra uma tentativa. A janela começa na primeira tentativa.
     *
     * @return int tentativas na janela atual
     */
    public function hit(string $key, int $decaySeconds): int
    {
        return $this->withFile($key, function (array $state) use ($decaySeconds) {
            $now = time();

            if ($state['reset_at'] <= $now) {
                $state = ['count' => 0, 'reset_at' => $now + $decaySeconds];
            }

            $state['count']++;

            return $state;
        })['count'];
    }

    public function attempts(string $key): int
    {
        $state = $this->read($key);

        return $state['reset_at'] > time() ? $state['count'] : 0;
    }

    /**
     * Segundos até a janela atual terminar.
     */
    public function availableIn(string $key): int
    {
        return max(0, $this->read($key)['reset_at'] - time());
    }

    public function clear(string $key): void
    {
        $file = $this->file($key);

        if (is_file($file)) {
            unlink($file);
        }
    }

    private function file(string $key): string
    {
        // a chave pode conter qualquer coisa (username digitado): vira hash no nome do arquivo
        return $this->dir . '/' . hash('sha256', $key) . '.json';
    }

    /**
     * @return array{count: int, reset_at: int}
     */
    private function read(string $key): array
    {
        $file = $this->file($key);
        $raw = is_file($file) ? file_get_contents($file) : false;
        $state = $raw ? json_decode($raw, true) : null;

        return [
            'count' => (int) ($state['count'] ?? 0),
            'reset_at' => (int) ($state['reset_at'] ?? 0),
        ];
    }

    /**
     * Lê, altera e grava o estado com lock exclusivo (requisições simultâneas não se perdem).
     *
     * @param callable(array{count: int, reset_at: int}): array{count: int, reset_at: int} $change
     */
    private function withFile(string $key, callable $change): array
    {
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0775, true);
        }

        $handle = @fopen($this->file($key), 'c+');

        if (!$handle) {
            // sem como gravar: não bloqueia o usuário por falha de disco
            return ['count' => 0, 'reset_at' => 0];
        }

        try {
            flock($handle, LOCK_EX);

            $raw = stream_get_contents($handle);
            $state = $raw ? json_decode($raw, true) : null;
            $state = $change([
                'count' => (int) ($state['count'] ?? 0),
                'reset_at' => (int) ($state['reset_at'] ?? 0),
            ]);

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode($state));
            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }

        return $state;
    }
}
