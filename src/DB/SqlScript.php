<?php

namespace Wep\DB;

/**
 * Divide um script SQL em instruções (para executar uma a uma, sem multi-statements).
 * Respeita strings ('...', "...", `...`) e comentários (-- , #, /* *\/).
 */
final class SqlScript
{
    /**
     * @return string[]
     */
    public static function split(string $sql): array
    {
        $statements = [];
        $current = '';
        $length = strlen($sql);
        $quote = null;

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $sql[$i + 1] ?? '';

            if ($quote !== null) {
                $current .= $char;

                if ($char === '\\' && $quote !== '`') {
                    $current .= $next;
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                $current .= $char;
                continue;
            }

            // -- comentário (exige espaço depois, como no MySQL) e # comentário
            if (($char === '-' && $next === '-' && ctype_space($sql[$i + 2] ?? ' ')) || $char === '#') {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $length : $end;
                $current .= "\n";
                continue;
            }

            if ($char === '/' && $next === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $i = $end === false ? $length : $end + 1;
                continue;
            }

            if ($char === ';') {
                self::push($statements, $current);
                $current = '';
                continue;
            }

            $current .= $char;
        }

        self::push($statements, $current);

        return $statements;
    }

    private static function push(array &$statements, string $statement): void
    {
        $statement = trim($statement);

        if ($statement !== '' && strcasecmp($statement, 'COMMIT') !== 0) {
            $statements[] = $statement;
        }
    }
}
