<?php

namespace App\Core\Command;

/**
 * @todo implementar metodos
 * - error
 * - success
 * - warning
 */
class Console
{
    /**
     * @example
     * App::console("error", "message");
     */
    public static function log($props=[], $message=null, $new_line=true)
    {

        $modes = [
            "success" => [
                "title" => "SUCESSO",
                "colors" => self::getColor('white', 'green')
            ],
            "error" => [
                "title" => "ERRO",
                "colors" => self::getColor('white', 'red')
            ],
            "alert" => [
                "title" => "ALERTA",
                "colors" => self::getColor('white', 'yellow')
            ],
            "custom" => [
                "title" => "",
                "colors" => self::getColor('black', 'light')
            ]
        ];

        if ( is_string($props) ){
            $mode = $modes[$props] ?? null;

            if ( !$mode && str_contains($props, ":") ){
                $parts = explode(":", $props);
                $title = $parts[1];
                $mode = $modes["custom"];

                $mode["title"] = $title;
            }

            echo self::setModeResponse($mode, $message);
        }

    }

    private static function getColor($font="white", $bg=null)
    {
        $fonts = [
            "white" => "1;37",
            "black" => "0;30"
        ];

        $bgs = [
            "green" => "42",
            "red" => "41",
            "blue" => "44",
            "yellow" => "43" ,
            "light" => "47"
        ];

        return $fonts[$font]. ";" . $bgs[$bg];
    }

    private static function setModeResponse($mode, $message)
    {
        echo "\e[".$mode['colors']."m ".$mode['title']." \e[0m ".$message . "\n";
    }
}