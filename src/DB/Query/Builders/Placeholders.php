<?php

namespace Wep\DB\Query\Builders;

/**
 * Coleta os valores de uma query e devolve placeholders sequenciais (:p1, :p2...).
 *
 * Substitui o uniqid() usado antes: uniqid() é lento (faz usleep) e pode gerar
 * nomes repetidos em chamadas consecutivas, o que corrompe silenciosamente o
 * bind de listas grandes (IN com muitos valores). Além disso, queries com o
 * mesmo formato passam a gerar exatamente o mesmo SQL.
 */
final class Placeholders
{
    /** @var array */
    private $values = [];

    /** @var int */
    private $count = 0;

    /**
     * Registra um valor e retorna o placeholder (com ":") para usar na query.
     */
    public function add($value): string
    {
        $name = 'p' . (++$this->count);
        $this->values[$name] = $value;

        return ':' . $name;
    }

    /**
     * @return array nome (sem ":") => valor
     */
    public function all(): array
    {
        return $this->values;
    }
}
