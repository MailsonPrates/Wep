<?php

namespace Wep\DB\Query\Builders;

use Wep\DB\Query\Raw;
use InvalidArgumentException;

/**
 * Valida e escapa nomes de tabelas/colunas.
 *
 * Valores de usuário sempre vão por bind (placeholders), mas NOMES de colunas e
 * tabelas não podem ser "bindados". Antes eles eram concatenados direto na
 * query (SQL injection via orderBy, where, get, join, update...). Agora só
 * passam formatos conhecidos; qualquer outra coisa lança InvalidArgumentException.
 * Para expressões SQL arbitrárias use DB::raw().
 */
final class Identifier
{
    private const PART = '[A-Za-z_][A-Za-z0-9_$]*';

    private const AGGREGATES = 'COUNT|SUM|AVG|MIN|MAX';

    /**
     * coluna | tabela.coluna | schema.tabela.coluna | * | tabela.*
     *
     * @param string|Raw $name
     */
    public static function quote($name): string
    {
        if ($name instanceof Raw) {
            return (string) $name;
        }

        if (!is_string($name) || trim($name) === '') {
            throw new InvalidArgumentException('Invalid column or table name');
        }

        $name = trim($name);

        if ($name === '*') {
            return '*';
        }

        $parts = explode('.', $name);
        $last = count($parts) - 1;

        if ($last > 2) {
            throw self::invalid($name);
        }

        $quoted = [];

        foreach ($parts as $i => $part) {
            if ($part === '*' && $i === $last && $i > 0) {
                $quoted[] = '*';
                continue;
            }

            if (!preg_match('/^' . self::PART . '$/', $part)) {
                throw self::invalid($name);
            }

            $quoted[] = '`' . $part . '`';
        }

        return implode('.', $quoted);
    }

    /**
     * Nome de tabela com alias opcional: "users", "users u", "users AS u".
     *
     * @param string|Raw $name
     */
    public static function table($name): string
    {
        if ($name instanceof Raw) {
            return (string) $name;
        }

        if (!is_string($name)) {
            throw new InvalidArgumentException('Invalid column or table name');
        }

        if (preg_match('/^(\S+)\s+(?:AS\s+)?(' . self::PART . ')$/i', trim($name), $m)) {
            return self::quote($m[1]) . ' AS `' . $m[2] . '`';
        }

        return self::quote($name);
    }

    /**
     * Item da lista de campos do SELECT.
     * Aceita: coluna, tabela.coluna, *, "col AS alias", "DISTINCT col" e
     * agregações simples (COUNT(*), SUM(col), COUNT(DISTINCT col) AS total...).
     *
     * @param string|Raw $expr
     */
    public static function select($expr): string
    {
        if ($expr instanceof Raw) {
            return (string) $expr;
        }

        if (!is_string($expr) || trim($expr) === '') {
            throw new InvalidArgumentException('Invalid column or table name');
        }

        $expr = trim($expr);
        $original = $expr;
        $prefix = '';
        $suffix = '';

        if (preg_match('/^DISTINCT\s+(.+)$/is', $expr, $m)) {
            $prefix = 'DISTINCT ';
            $expr = trim($m[1]);
        }

        if (preg_match('/^(.+?)\s+(?:AS\s+)?(' . self::PART . ')$/is', $expr, $m)) {
            $expr = trim($m[1]);
            $suffix = ' AS `' . $m[2] . '`';
        }

        $aggregate = '/^(' . self::AGGREGATES . ')\s*\(\s*(DISTINCT\s+)?(\*|[A-Za-z0-9_$.`]+)\s*\)$/i';

        try {
            if (preg_match($aggregate, $expr, $m)) {
                $distinct = !empty($m[2]) ? 'DISTINCT ' : '';
                $sql = strtoupper($m[1]) . '(' . $distinct . self::quote($m[3]) . ')';
            } else {
                $sql = self::quote($expr);
            }
        } catch (InvalidArgumentException $e) {
            throw self::invalid($original);
        }

        return $prefix . $sql . $suffix;
    }

    /**
     * Item do ORDER BY: coluna ou RAND().
     *
     * @param string|Raw $col
     */
    public static function order($col): string
    {
        if (is_string($col) && preg_match('/^RAND\(\s*\)$/i', trim($col))) {
            return 'RAND()';
        }

        return self::quote($col);
    }

    private static function invalid(string $name): InvalidArgumentException
    {
        $safe = preg_replace('/[^\w.*(), $-]/', '?', $name);
        $safe = substr($safe, 0, 50);

        return new InvalidArgumentException(
            'Invalid column or table name "' . $safe . '". Use DB::raw() for SQL expressions'
        );
    }
}
