<?php

namespace Wep\Engine\Builder;

/**
 * Escrita dos arquivos gerados pelo `php app update` (storage/builds).
 *
 * - atômica: grava num temporário e troca com rename(). Uma requisição durante o deploy nunca
 *   inclui um arquivo pela metade;
 * - arquivos PHP só com dados (var_export): nada de código montado por concatenação, então um
 *   apóstrofo num path ou numa constante não gera erro de sintaxe;
 * - quebras de linha "\n" (antes "\r" sozinho, que editores e o git viam como uma linha só).
 */
final class BuildFile
{
    /**
     * Grava um arquivo PHP que devolve $data.
     */
    public static function php(string $file, mixed $data): bool
    {
        return self::write($file, "<?php\n" . Warning::get() . "\nreturn " . var_export($data, true) . ";\n");
    }

    /**
     * Grava um arquivo qualquer (JS, JSON...) de forma atômica.
     */
    public static function write(string $file, string $content): bool
    {
        $dir = dirname($file);

        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            return false;
        }

        $temp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';

        if (file_put_contents($temp, $content, LOCK_EX) === false) {
            @unlink($temp);
            return false;
        }

        if (!@rename($temp, $file)) {
            // Windows: rename não substitui arquivo em uso por outro processo; tenta remover antes
            @unlink($file);

            if (!@rename($temp, $file)) {
                @unlink($temp);
                return false;
            }
        }

        if (function_exists('opcache_invalidate') && str_ends_with($file, '.php')) {
            @opcache_invalidate($file, true);
        }

        return true;
    }

    /**
     * Valor como literal JS (string/número/array), para montar arquivos JS sem concatenar texto cru.
     */
    public static function js(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
    }

    /**
     * Nome válido de identificador JS/PHP a partir de um texto (ex.: nome de componente).
     */
    public static function identifier(string $text, string $fallback = 'Item'): string
    {
        $id = preg_replace('/[^A-Za-z0-9_]/', '', $text);

        return $id === '' || ctype_digit($id[0]) ? $fallback . $id : $id;
    }
}
