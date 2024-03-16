<?php

namespace App\Core;

class Str
{
       /**
     * @param string $string
     * 
     * @return string
     */
    public static function normalize($string)
    {
        // remove whitespace, leaving only a single space between words. 
        $string = preg_replace('/\s+/', ' ', $string);
        // flick diacritics off of their letters
        $string = preg_replace('~&([a-z]{1,2})(?:acute|cedil|circ|grave|lig|orn|ring|slash|th|tilde|uml|caron);~i', '$1', htmlentities($string, ENT_COMPAT, 'UTF-8'));  
        // lower case
        $string = strtolower($string);
        return $string;
    }

    /**
     * @param string $string
     * @param string $inicio
     * @param string $fim
     * 
     * @return string
     */
    public static function between($string, $inicio, $fim="")
    {
        $inicio_pos = strpos($string, $inicio);
        if ($inicio_pos === false) {
            return ''; // Se não encontrar o início, retorna uma string vazia
        }
        $inicio_pos += strlen($inicio);
        $fim_pos = strpos($string, $fim, $inicio_pos);
        if ($fim_pos === false) {
            return ''; // Se não encontrar o fim, retorna uma string vazia
        }
        return substr($string, $inicio_pos, $fim_pos - $inicio_pos);
    }

    public static function camelToKebabCase(string $string):string
    {
         // Transforma a primeira letra em minúscula
        $input = lcfirst($string);
        
        // Usa uma expressão regular para encontrar letras maiúsculas e as substitui por hífens e letras minúsculas
        $output = preg_replace('/([a-z])([A-Z])/', '$1-$2', $input);
        
        // Converte toda a string para minúsculas
        $output = strtolower($output);
        
        return $output;
    }
}