<?php

namespace Wep\DB\Query;

/**
 * Utilitários de texto da query, usados apenas para debug/validação.
 */
final class Formatter
{
    private const NAMED_PLACEHOLDER = '/(?<![:\w]):([A-Za-z_]\w*)/';

    /**
     * Verifica se a query tem placeholders nomeados (:nome).
     * Ignora "::" e horários em string ('10:30'), que antes eram
     * confundidos com placeholder.
     */
    public static function hasNamedPlaceholders(string $query): bool
    {
        return (bool) preg_match(self::NAMED_PLACEHOLDER, $query);
    }

    /**
     * Monta a query com os valores já no lugar dos placeholders.
     *
     * ATENÇÃO: o resultado serve APENAS para debug/log. Nunca execute essa string.
     */
    public static function interpolate(string $query, array $params = []): string
    {
        if (empty($params)) {
            return $query;
        }

        $named = [];
        $positional = [];

        foreach ($params as $key => $value) {
            if (is_int($key)) {
                $positional[] = $value;
            } else {
                $named[ltrim($key, ':')] = $value;
            }
        }

        if ($named) {
            $query = preg_replace_callback(
                self::NAMED_PLACEHOLDER,
                function ($m) use ($named) {
                    return array_key_exists($m[1], $named) ? self::literal($named[$m[1]]) : $m[0];
                },
                $query
            );
        }

        if ($positional) {
            $i = 0;
            $query = preg_replace_callback('/\?/', function ($m) use ($positional, &$i) {
                return array_key_exists($i, $positional) ? self::literal($positional[$i++]) : $m[0];
            }, $query);
        }

        return $query;
    }

    private static function literal($value): string
    {
        if ($value === null) return 'NULL';
        if (is_bool($value)) return $value ? '1' : '0';
        if (is_int($value) || is_float($value)) return (string) $value;
        if (is_array($value)) return "'[array]'";

        return "'" . strtr((string) $value, ["\\" => "\\\\", "'" => "\\'"]) . "'";
    }
}
