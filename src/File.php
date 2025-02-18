<?php

namespace App\Core;

class File
{
    public $file = null;
    public $filename = null;
    
    public function __construct($path=null)
    {
		if ( $path && !file_exists($path) ){
			$this->put($path, "");
		}
     
		$files = glob($path);
		$this->file = $files[0] ?? null;
        $this->filename = $path;
    }
    
    public function read($json=false) 
    {
		$file = $this->file;
		
		if ($file) {
			
			$handle = @fopen($file, 'r');

			if ($handle) {
				flock($handle, LOCK_SH);

				$size = 0;

				$size = @filesize($file);

				if ($size > 0) {
					$data = fread($handle, $size);
				} else {
					$data = '';
				}

				flock($handle, LOCK_UN);

				fclose($handle);

				return $json ? json_decode($data, true) : $data;
			}
		}

		return false;
	}

	public function write($content="", $json=null) 
    {
        if ( !is_null($json) ){
            $json = is_array($content) || is_object($content) ? true : false;
        }
        
		$file = $this->filename;

		$handle = fopen($file, 'w');

		flock($handle, LOCK_EX);

		fwrite($handle, $json ? json_encode($content) : $content);

		fflush($handle);

		flock($handle, LOCK_UN);

		fclose($handle);
	}

	public function delete() 
    {
		if (!@unlink($this->filename)) {
		    clearstatcache(false, $this->filename);
		}
	}

	public static function deleteAll(string $dirPath, $deleteRoot=false) 
    {
		if ( !is_dir($dirPath) ) return;

		if ( substr($dirPath, strlen($dirPath) - 1, 1) != '/') {
			$dirPath .= '/';
		}

		$files = glob($dirPath . '*', GLOB_MARK);

		foreach ($files as $file) {
			if ( is_dir($file) ) {
				self::deleteAll($file, true);

			} else {
				unlink($file);
			}
		}

		$deleteRoot && rmdir($dirPath);
	}
	
	public function append($content="")
	{
	    $this->put($this->filename, $content, FILE_APPEND | LOCK_EX);
	}

	/**
     * Mesmo que file_put_contents, mas criando 
     * diretórios caso nao existam
     */
    public function put($fullPath, $contents, $flags = 0)
    {
        $parts = explode( '/', $fullPath );
        array_pop( $parts );
        $dir = implode( '/', $parts );
       
        if( !is_dir( $dir ) )
            mkdir( $dir, 0777, true );
       
        return file_put_contents( $fullPath, $contents, $flags );
    }

    public function isEmpty(): bool|int
    {
        clearstatcache();
        $size = filesize($this->filename);
        return is_int($size) && $size > 0 ? false : $size;
    }
}