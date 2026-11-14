<?php

namespace Wep\DB\Query\Builders;

use InvalidArgumentException;

class Operators
{
    /**
     * Operadores permitidos e o método que monta cada um.
     * Qualquer operador fora dessa lista é rejeitado.
     */
    private const OPERATORS = [

        // operadores de lógica
        "="  => "logic",
        "!=" => "logic",
        "<>" => "logic",
        ">"  => "logic",
        ">=" => "logic",
        "<"  => "logic",
        "<=" => "logic",

        // operadores de valor
        "in"          => "in",
        "not in"      => "in",
        "like"        => "like",
        "not like"    => "like",
        "between"     => "between",
        "not between" => "between",

        // operadores de tipo
        "is null"      => "nullCheck",
        "is not null"  => "nullCheck",
        "is not empty" => "nullCheck",
    ];

    /**
     * Monta o trecho SQL de uma condição, registrando os valores em $bag.
     *
     * @param object $props
     * @param string $props->col
     * @param string $props->operator
     * @param mixed  $props->value
     * @param Placeholders $bag
     *
     * @return string
     * @throws InvalidArgumentException
     */
    public static function build($props, Placeholders $bag): string
    {
        $operator = $props->operator ?? null;
        $method = is_string($operator) ? (self::OPERATORS[$operator] ?? null) : null;

        if (!$method) {
            $safe = is_string($operator) ? substr(preg_replace('/[^\w <>=!]/', '?', $operator), 0, 20) : gettype($operator);
            throw new InvalidArgumentException("Invalid operator \"$safe\"");
        }

        $col = Identifier::quote($props->col);

        switch ($method) {
            case 'logic':     return self::logic($col, $props, $bag);
            case 'in':        return self::in($col, $props, $bag);
            case 'like':      return self::like($col, $props, $bag);
            case 'between':   return self::between($col, $props, $bag);
            default:          return self::nullCheck($col, $props);
        }
    }

    private static function logic(string $col, $props, Placeholders $bag): string
    {
        self::assertScalar($props->value, $props->operator);

        return "$col $props->operator " . $bag->add($props->value);
    }

    private static function in(string $col, $props, Placeholders $bag): string
    {
        $has_not = $props->operator === "not in";
        $list = is_array($props->value) ? array_values($props->value) : [$props->value];

        // IN() vazio é erro de sintaxe no MySQL: "IN vazio" nunca casa, "NOT IN vazio" sempre casa
        if (empty($list)) {
            return $has_not ? "1 = 1" : "1 = 0";
        }

        $marks = [];

        foreach ($list as $value) {
            self::assertScalar($value, $props->operator);
            $marks[] = $bag->add($value);
        }

        return $col . ($has_not ? " NOT IN" : " IN") . "(" . implode(",", $marks) . ")";
    }

    private static function like(string $col, $props, Placeholders $bag): string
    {
        self::assertScalar($props->value, $props->operator);

        $type = $props->operator === "not like" ? "NOT LIKE" : "LIKE";

        return "$col $type " . $bag->add($props->value);
    }

    private static function between(string $col, $props, Placeholders $bag): string
    {
        $values = $props->value;

        if (!is_array($values) || count($values) !== 2) {
            throw new InvalidArgumentException("Operator \"$props->operator\" requires [start, end]");
        }

        $values = array_values($values);
        self::assertScalar($values[0], $props->operator);
        self::assertScalar($values[1], $props->operator);

        $type = $props->operator === "not between" ? "NOT BETWEEN" : "BETWEEN";

        return "$col $type " . $bag->add($values[0]) . " AND " . $bag->add($values[1]);
    }

    private static function nullCheck(string $col, $props): string
    {
        $types = [
            "is null"      => "IS NULL",
            "is not null"  => "IS NOT NULL",
            "is not empty" => "<> ''",
        ];

        return "$col " . $types[$props->operator];
    }

    private static function assertScalar($value, string $operator): void
    {
        if (is_array($value) || (is_object($value) && !method_exists($value, '__toString'))) {
            throw new InvalidArgumentException("Invalid value for operator \"$operator\"");
        }
    }
}
