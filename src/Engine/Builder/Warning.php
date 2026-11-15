<?php

namespace Wep\Engine\Builder;

class Warning
{
    public static function get()
    {
        return "\n/**\n"
            . " * -------------------- CUIDADO -----------------------\n"
            . " *   Arquivo gerado automaticamente. NÃO o modifique\n"
            . " *   ou o funcionamento da aplicação será comprometido\n"
            . " *   Build: " . date("d/m/Y H:i:s") . "\n"
            . " * ----------------------------------------------------\n"
            . " */\n";
    }
}
