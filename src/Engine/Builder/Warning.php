<?php

namespace App\Core\Engine\Builder;

class Warning
{
    public static function get()
    {
return  '
/**
 * -------------------- CUIDADO -----------------------
 *   Arquivo gerado automaticamente. NÃO o modifique 
 *   ou o funcionamento da aplicação será comprometido
 *   Build: '.date("d/m/Y h:i:s").'
 * ----------------------------------------------------
 */    
';
    }
}