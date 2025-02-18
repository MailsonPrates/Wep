<?php

namespace App\Core;

use Exception;

class File
{
    public $file = null;
    public $filename = null;

	public static $types = [
        'jpg',
        'jpeg',
        'gif',
        'png',
        'webp'
    ];

    public static $mime_type = [
        'image/jpeg',
        'image/pjpeg',
        'image/png',
        'image/x-png',
        'image/gif',
        'image/webp'
    ];

    public static $max_size = 5242880;
    
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
    public static function put($fullPath, $contents, $flags = 0)
    {
        $parts = explode( '/', $fullPath );
        array_pop( $parts );
        $dir = implode( '/', $parts );
       
        if( !is_dir( $dir ) )
            mkdir( $dir, 0777, true );
       
        return file_put_contents( $fullPath, $contents, $flags );
    }

    public static function isEmpty($filename=""): bool|int
    {
        clearstatcache();
        $size = filesize($filename);
        return is_int($size) && $size > 0 ? false : $size;
    }

    /**
     * @param array $props
     * @param string $props directory
     * @param array $props file
     * @param string|int $props prefix
     * 
     */
    public static function upload($props=[])
    {
        /**
         * Handle Directory
         */
        $dir_image = DIR_ASSETS . '/images';

        $directory = isset($props['directory']) 
            ? rtrim($dir_image . $props['directory'], '/')
            : $dir_image;

		// Check directory and create
		if ( !is_dir($directory) && !mkdir($directory, 0777, true) ) {
            throw new Exception("Erro ao criar o diretório");
		}

        /**
         * Handle File
         */
        $file = $props['file'] ?? $_FILES['file'] ?? [];

        if ( empty($file) ) throw new Exception("Arquivo não enviado");

        $filename = $file['name'];

        // Check file extension types
        if ( !in_array(Str::substr(strrchr($filename, '.'), 1), self::$types) ) {
            throw new Exception("Tipo de arquivo não permitido");
        }

        // Create new filename
        $file_id = uniqid($props['prefix'] ? $props['prefix'] . '-' : "") . uniqid();
        $filename_ext = explode(".", $filename)[1];
        $filename = $file_id . '.' . $filename_ext;

        // Check file mime types
        if ( !in_array($file['type'], self::$mime_type) ) {
            throw new Exception("Tipo de arquivo inválido");
        }

        // Check file size
        if ( $file['size'] > self::$max_size ) {
            throw new Exception("O tamanho do arquivo é muito grande");
        }

        // Return any upload error
        if ( $file['error'] != UPLOAD_ERR_OK ) {
            throw new Exception("Houve um erro ao fazer upload do arquivo: ". $file['error']);
        }

        move_uploaded_file($file['tmp_name'], $directory . '/' . $filename);

        $uploaded_file = $directory . '/' . $filename;

        return $uploaded_file;
    }
    
}