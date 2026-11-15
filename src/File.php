<?php

namespace Wep;

/**
 * Leitura e escrita de arquivos.
 *
 * @example
 * $file = new File(DIR_BUILDS . '/mapa.json');
 * $file->write(['a' => 1]);   // arrays/objetos viram JSON
 * $dados = $file->read(true); // false se o arquivo não existe
 */
class File
{
    /** Permissão das pastas criadas (antes 0777: qualquer usuário do servidor podia gravar). */
    public const DIR_MODE = 0775;

    public $file = null;
    public $filename = null;

    /** @deprecated use Upload::$types */
    public static $types = ['jpg', 'jpeg', 'gif', 'png', 'webp'];

    /** @deprecated o tipo agora vem do conteúdo do arquivo (Upload::$mime_types) */
    public static $mime_type = ['image/jpeg', 'image/pjpeg', 'image/png', 'image/x-png', 'image/gif', 'image/webp'];

    /** @deprecated use Upload::$max_size */
    public static $max_size = 5242880;

    /**
     * Só aponta para o arquivo. (Antes criava o arquivo e as pastas mesmo quando só ia ler.)
     */
    public function __construct($path = null)
    {
        $this->filename = $path;
        $this->file = $path !== null && is_file($path) ? $path : null;
    }

    /**
     * Conteúdo do arquivo (decodificado se $json), ou false se não existe.
     */
    public function read($json = false)
    {
        $file = $this->filename;

        if ($file === null || !is_file($file)) return false;

        $handle = @fopen($file, 'r');

        if (!$handle) return false;

        flock($handle, LOCK_SH);
        $data = stream_get_contents($handle);
        flock($handle, LOCK_UN);
        fclose($handle);

        if ($data === false) return false;

        return $json ? json_decode($data, true) : $data;
    }

    /**
     * Grava de forma atômica. Arrays e objetos viram JSON; o resto é gravado como texto.
     * (Antes um array sem o 2º parâmetro dava TypeError.)
     *
     * @param mixed     $content
     * @param bool|null $json mantido por compatibilidade: o formato é decidido pelo tipo do conteúdo
     */
    public function write($content = "", $json = null): bool
    {
        $data = is_array($content) || is_object($content)
            ? json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : (string) $content;

        if ($data === false || !self::putAtomic($this->filename, $data)) return false;

        $this->file = $this->filename;

        return true;
    }

    public function delete()
    {
        if ($this->filename !== null && is_file($this->filename)) @unlink($this->filename);

        $this->file = null;
    }

    public function append($content = "")
    {
        return self::put($this->filename, $content, FILE_APPEND | LOCK_EX);
    }

    /**
     * Apaga o conteúdo da pasta (e a pasta, se $deleteRoot), inclusive arquivos ocultos.
     * (Antes usava glob: arquivos com "." no início ficavam e o rmdir falhava.)
     */
    public static function deleteAll(string $dirPath, $deleteRoot = false)
    {
        if (!is_dir($dirPath)) return;

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dirPath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        $deleteRoot && @rmdir($dirPath);
    }

    /**
     * Mesmo que file_put_contents, mas criando as pastas que faltam.
     */
    public static function put($fullPath, $contents, $flags = 0)
    {
        if (!self::ensureDir(dirname((string) $fullPath))) return false;

        return file_put_contents($fullPath, $contents, $flags);
    }

    /**
     * Grava num temporário e troca de uma vez (rename): quem lê o arquivo ao mesmo tempo
     * nunca pega o conteúdo pela metade.
     */
    public static function putAtomic(string $file, string $content): bool
    {
        if (!self::ensureDir(dirname($file))) return false;

        $temp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';

        if (@file_put_contents($temp, $content, LOCK_EX) === false) {
            @unlink($temp);
            return false;
        }

        if (!@rename($temp, $file)) {
            // Windows: rename não substitui um arquivo aberto por outro processo; tenta remover antes
            @unlink($file);

            if (!@rename($temp, $file)) {
                @unlink($temp);
                return false;
            }
        }

        if (function_exists('opcache_invalidate') && str_ends_with($file, '.php')) {
            @opcache_invalidate($file, true);
        }

        return true;
    }

    /**
     * Cria a pasta (e as de cima) se não existir.
     */
    public static function ensureDir(string $dir, int $mode = self::DIR_MODE): bool
    {
        if ($dir === '' || is_dir($dir)) return true;

        // outra requisição pode ter criado a pasta no meio do caminho
        return @mkdir($dir, $mode, true) || is_dir($dir);
    }

    /**
     * true se o arquivo não existe ou está vazio.
     * (Antes devolvia 0 para vazio e false para arquivo com conteúdo: os dois "falsos".)
     */
    public static function isEmpty($filename = ""): bool
    {
        clearstatcache(true, $filename);

        return !is_file($filename) || filesize($filename) === 0;
    }

    /**
     * Upload de imagem. Ver Upload::image().
     *
     * @return string caminho completo do arquivo salvo
     */
    public static function upload($props = [])
    {
        return Upload::image($props + ['max_size' => self::$max_size]);
    }
}
