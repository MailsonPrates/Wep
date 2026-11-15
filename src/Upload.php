<?php

namespace Wep;

use Exception;

/**
 * Upload de imagens para public/assets/images.
 *
 * O tipo do arquivo vem do CONTEÚDO (fileinfo + getimagesize) e define a extensão salva. O nome e
 * o tipo enviados pelo navegador são ignorados: os dois são controlados pelo cliente. (Antes
 * "foto.php.jpg" com type "image/jpeg" era salvo como ".php" numa pasta pública: execução de código.)
 *
 * @example
 * $path = Upload::image(['directory' => '/users', 'prefix' => $userId]);
 * $path = Upload::image(['file' => $_FILES['avatar'], 'max_size' => 2 * 1024 * 1024]);
 */
class Upload
{
    /** Extensões aceitas. */
    public static $types = ['jpg', 'jpeg', 'gif', 'png', 'webp'];

    /** Tipo real do conteúdo => extensão salva. */
    public static $mime_types = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    public static $max_size = 5242880;

    /**
     * Move o arquivo enviado. Os testes trocam por um rename() (move_uploaded_file só aceita
     * arquivos vindos de um POST real).
     *
     * @var callable|null
     */
    public static $mover = null;

    /**
     * @param array $props
     *  - file: item de $_FILES (padrão: $_FILES['file'])
     *  - directory: subpasta dentro de assets/images (ex.: "/users")
     *  - prefix: início do nome do arquivo
     *  - max_size: tamanho máximo em bytes
     *
     * @return string caminho completo do arquivo salvo
     */
    public static function image(array $props = []): string
    {
        $file = $props['file'] ?? $_FILES['file'] ?? null;

        if (empty($file) || !is_array($file) || !isset($file['tmp_name'])) throw new Exception("Arquivo não enviado");

        $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;

        if ($error === UPLOAD_ERR_NO_FILE) throw new Exception("Arquivo não enviado");

        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new Exception("O tamanho do arquivo é muito grande");
        }

        if ($error !== UPLOAD_ERR_OK) throw new Exception("Houve um erro ao fazer upload do arquivo: " . (int) $error);

        $tmp = (string) $file['tmp_name'];

        if ($tmp === '' || !is_file($tmp)) throw new Exception("Arquivo não enviado");

        // tamanho real (o "size" também vem do cliente)
        $max = (int) ($props['max_size'] ?? self::$max_size);

        if (filesize($tmp) > $max) throw new Exception("O tamanho do arquivo é muito grande");

        $extension = self::detectExtension($tmp);

        if ($extension === null) throw new Exception("Tipo de arquivo inválido");

        $allowed = array_map('strtolower', self::$types);

        if (!in_array($extension, $allowed, true) && !($extension === 'jpg' && in_array('jpeg', $allowed, true))) {
            throw new Exception("Tipo de arquivo não permitido");
        }

        $directory = self::directory($props['directory'] ?? '');

        if (!File::ensureDir($directory)) throw new Exception("Erro ao criar o diretório");

        $prefix = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($props['prefix'] ?? ''));
        $name = ($prefix !== '' ? "$prefix-" : '') . bin2hex(random_bytes(12)) . '.' . $extension;
        $target = $directory . '/' . $name;

        $mover = self::$mover ?? 'move_uploaded_file';

        if (!$mover($tmp, $target)) throw new Exception("Houve um erro ao salvar o arquivo");

        @chmod($target, 0644);

        return $target;
    }

    /**
     * Caminho relativo a public/assets/images (ex.: "/users/abc.png"), como o front usa.
     */
    public static function relative(string $path): string
    {
        $base = str_replace('\\', '/', self::baseDir());
        $path = str_replace('\\', '/', $path);

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }

    /**
     * Extensão a partir do conteúdo, ou null se não for uma imagem aceita.
     */
    public static function detectExtension(string $file): ?string
    {
        // lido uma vez só (o tamanho já foi limitado) e analisado em memória
        $bytes = @file_get_contents($file);

        if ($bytes === false || $bytes === '') return null;

        $info = @getimagesizefromstring($bytes);

        if ($info === false) return null;

        $mime = $info['mime'] ?? '';

        // o fileinfo confere a assinatura de novo, por outro caminho que o getimagesize
        if (class_exists(\finfo::class)) {
            $detected = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

            if ($detected !== false && $detected !== $mime) return null;
        }

        return self::$mime_types[$mime] ?? null;
    }

    private static function baseDir(): string
    {
        return rtrim(DIR_ASSETS, '/\\') . '/images';
    }

    /**
     * Subpasta dentro de assets/images. "..", caminhos absolutos e caracteres estranhos são recusados.
     */
    private static function directory(string $sub): string
    {
        $sub = trim(str_replace('\\', '/', $sub), '/');

        if ($sub === '') return self::baseDir();

        foreach (explode('/', $sub) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || !preg_match('/^[A-Za-z0-9_-]+$/', $segment)) {
                throw new Exception("Diretório de upload inválido");
            }
        }

        return self::baseDir() . '/' . $sub;
    }
}
