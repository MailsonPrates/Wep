<?php

namespace Wep\DB\Query;

/**
 * Fragmento SQL "de confiança", inserido na query SEM validação nem escape.
 *
 * Use apenas com strings escritas no código (nunca com input de usuário):
 *
 *   DB::table('users')->get(DB::raw('IFNULL(nick, name) AS display'));
 *   DB::table('users')->orderBy(DB::raw('RAND()'))->get();
 */
final class Raw
{
    /** @var string */
    private $sql;

    public function __construct(string $sql)
    {
        $this->sql = $sql;
    }

    public function __toString(): string
    {
        return $this->sql;
    }
}
