<?php

namespace App\Core\DB\Query\Builders;

use App\Core\Obj;
use InvalidArgumentException;

class Condition
{
    /**
     * Como cada tipo de condição vira SQL: [prefixo, separador entre itens, sufixo]
     */
    private const TYPES = [
        'where' => ['WHERE ',  ' AND ', ''],
        'or'    => ['OR ',     ' OR ',  ''],
        'orAnd' => ['OR (',    ' AND ', ')'],
        'andOr' => ['AND (',   ' OR ',  ')'],
    ];

    /**
     * Monta o trecho WHERE/OR/AND da query.
     * Condições do mesmo tipo são agrupadas; o grupo WHERE vem sempre primeiro
     * e os demais seguem a ordem em que apareceram.
     *
     * @param array $conditions
     * @param Placeholders|null $bag bag compartilhado com o restante da query (ex.: SET do UPDATE)
     *
     * @return object {string, placeholders}
     * @throws InvalidArgumentException
     */
    public static function buildQuery($conditions = [], ?Placeholders $bag = null)
    {
        $bag = $bag ?? new Placeholders();
        $groups = [];

        foreach ($conditions as $item) {
            $item = (object) $item;
            $type = $item->type ?? null;

            if (!isset(self::TYPES[$type])) {
                throw new InvalidArgumentException("Invalid condition type");
            }

            $groups[$type][] = Operators::build($item, $bag);
        }

        // or()/andOr() sem where() geram SQL inválido ou, pior, mudam o sentido do
        // UPDATE (ex.: "SET a = :x OR id = :y" atualiza a tabela inteira).
        if ($groups && !isset($groups['where'])) {
            throw new InvalidArgumentException("or()/andOr() require a where() clause");
        }

        $ordered = isset($groups['where']) ? ['where' => $groups['where']] + $groups : $groups;
        $sql = [];

        foreach ($ordered as $type => $items) {
            [$prefix, $glue, $suffix] = self::TYPES[$type];
            $sql[] = $prefix . implode($glue, $items) . $suffix;
        }

        return Obj::set([
            'string' => implode(" ", $sql),
            'placeholders' => $bag->all()
        ]);
    }
}
