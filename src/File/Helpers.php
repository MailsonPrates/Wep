<?php

namespace App\Core\File;

trait Helpers
{
    public static function put(string $filename, mixed $content, int $flags = 0)
    {
        $exploded = explode('/', $filename);

        array_pop($exploded);

        $dir_path_only = implode('/', $exploded);

        if (!file_exists($dir_path_only)) {
            mkdir($dir_path_only,0775,true);
        }

        if ( !isset($content) || !$content ) return true;

        return file_put_contents($filename, $content, $flags);   
    }

    public static function isEmpty($pathToFile='')
    {
        clearstatcache();
        $size = filesize($pathToFile);
        return is_int($size) && $size > 0 ? false : $size;
    }
}