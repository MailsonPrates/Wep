<?php

namespace Wep;

/**
 * Cache em arquivos (storage/cache), com valores em JSON.
 *
 * Cada chave vira um arquivo "cache.<sha1 da chave>.json" com a validade dentro dele. Antes a chave
 * entrava num glob(): "senha_*" lia a chave de outra pessoa, delete('*') apagava tudo, "a/b"
 * colidia com "b", e a validade nunca era conferida no get().
 *
 * @example
 * Cache::set('menu', $menus, false);   // sem validade
 * Cache::set('cotacao', $valor, 600);  // 10 minutos
 * $menus = Cache::get('menu') ?: [];   // false quando não há valor válido
 */
class Cache
{
    /** Validade padrão (segundos) quando set() não informa uma. */
    public static $expire = 3600;

    /**
     * Valor guardado, ou $default (false) quando não existe, expirou ou o arquivo é inválido.
     */
    public static function get(string $key, mixed $default = false): mixed
    {
        $entry = self::read(self::path($key));

        if ($entry === null) return $default;

        if ($entry['e'] !== null && $entry['e'] < time()) {
            self::delete($key);
            return $default;
        }

        return $entry['v'];
    }

    /**
     * @deprecated use get()
     */
    public static function _get(string $key): mixed
    {
        return self::get($key);
    }

    public static function has(string $key): bool
    {
        $missing = new \stdClass;

        return self::get($key, $missing) !== $missing;
    }

    /**
     * @param int|false|null $expire segundos; false = sem validade; null = Cache::$expire
     */
    public static function set($key, $value, $expire = null): bool
    {
        $expire = $expire ?? self::$expire;

        $json = json_encode([
            'k' => (string) $key,
            'e' => $expire === false ? null : time() + (int) $expire,
            'v' => $value,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

        if ($json === false) return false;

        // atômico: quem lê nunca pega o arquivo pela metade (antes: delete + fopen('w'))
        return File::putAtomic(self::path((string) $key), $json);
    }

    public static function delete($key): void
    {
        $file = self::path((string) $key);

        if (is_file($file)) @unlink($file);
    }

    /**
     * Apaga todo o cache (inclusive arquivos no formato antigo "cache.<chave>.<validade>").
     */
    public static function flush(): void
    {
        foreach (self::files() as $file) @unlink($file);
    }

    /**
     * Remove os itens expirados.
     *
     * @param int|null $expire também muda a validade padrão (compatibilidade)
     */
    public static function refresh($expire = null): void
    {
        if ($expire !== null) self::$expire = $expire;

        $now = time();

        foreach (self::files() as $file) {
            if (!str_ends_with($file, '.json')) {
                @unlink($file); // formato antigo: não é mais lido
                continue;
            }

            $entry = self::read($file);

            if ($entry === null || ($entry['e'] !== null && $entry['e'] < $now)) @unlink($file);
        }
    }

    private static function path(string $key): string
    {
        return self::dir() . '/cache.' . sha1($key) . '.json';
    }

    private static function dir(): string
    {
        return rtrim(DIR_CACHE, '/\\');
    }

    private static function files(): array
    {
        $dir = self::dir();

        if (!is_dir($dir)) return [];

        $files = [];

        foreach (scandir($dir) ?: [] as $name) {
            if (str_starts_with($name, 'cache.') && is_file("$dir/$name")) $files[] = "$dir/$name";
        }

        return $files;
    }

    /**
     * @return array{k: string, e: ?int, v: mixed}|null
     */
    private static function read(string $file): ?array
    {
        if (!is_file($file)) return null;

        $content = @file_get_contents($file);

        if ($content === false || $content === '') return null;

        $entry = json_decode($content, true);

        if (!is_array($entry) || !array_key_exists('v', $entry) || !array_key_exists('e', $entry)) return null;

        return $entry;
    }
}
