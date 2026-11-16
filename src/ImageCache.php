<?php

namespace Wep;

/**
 * Versões em cache de imagens enviadas (public/assets/images), em WebP.
 *
 * O original nunca é alterado: a versão usada no site é gerada em assets/images/cache/, com o mesmo
 * caminho relativo do original, o tamanho no nome e a extensão .webp:
 *
 *   ImageCache::url('/profile/1-ab12.png', 36, 36)
 *   → /assets/images/cache/profile/1-ab12-36x36.webp?v=1731700000
 *
 * A versão é regerada quando o original muda. Sem suporte a WebP no GD, gera no formato do original.
 */
final class ImageCache
{
    /** Ajuste ao tamanho: cortar para preencher (avatares) ou caber inteiro (logos). */
    public const COVER = 'cover';
    public const CONTAIN = 'contain';

    private const FORMATS = ['webp', 'png', 'jpg'];

    /**
     * URL pública da versão em cache, ou null se o original não existir ou não for uma imagem.
     *
     * @param string $relative caminho relativo a assets/images (ex.: "/profile/x.png")
     * @param int    $width    0 = livre (mantém a proporção pela altura)
     * @param int    $height   0 = livre (mantém a proporção pela largura)
     * @param string $fit      ImageCache::COVER ou ImageCache::CONTAIN
     * @param string $format   "webp" (padrão), "png" ou "jpg"
     */
    public static function url(
        string $relative,
        int $width = 0,
        int $height = 0,
        string $fit = self::CONTAIN,
        string $format = 'webp'
    ): ?string {
        $file = self::path($relative, $width, $height, $fit, $format);

        if ( $file === null ) return null;

        $public = self::imagesDir();

        return '/assets/images' . substr(str_replace('\\', '/', $file), strlen($public)) . '?v=' . filemtime($file);
    }

    /**
     * Caminho do arquivo em cache (gerado se faltar ou estiver desatualizado), ou null.
     */
    public static function path(
        string $relative,
        int $width = 0,
        int $height = 0,
        string $fit = self::CONTAIN,
        string $format = 'webp'
    ): ?string {
        $original = self::original($relative);

        if ( $original === null ) return null;

        $format = in_array($format, self::FORMATS, true) ? $format : 'webp';

        if ( $format === 'webp' && !function_exists('imagewebp') ) {
            $format = strtolower(pathinfo($original, PATHINFO_EXTENSION)) === 'png' ? 'png' : 'jpg';
        }

        $info = pathinfo(substr($original, strlen(self::imagesDir())));
        $size = ($width || $height) ? "-{$width}x{$height}" . ($fit === self::COVER ? 'c' : '') : '';
        $target = self::imagesDir() . '/cache' . rtrim($info['dirname'], '/') . '/' . $info['filename'] . $size . '.' . $format;

        if ( is_file($target) && filemtime($target) >= filemtime($original) ) return $target;

        return self::render($original, $target, $width, $height, $fit, $format) ? $target : null;
    }

    /** Remove as versões em cache de um original (ex.: ao apagar ou trocar a imagem). */
    public static function forget(string $relative): void
    {
        $relative = '/' . ltrim(str_replace('\\', '/', $relative), '/');
        $info = pathinfo($relative);
        $dir = self::imagesDir() . '/cache' . rtrim($info['dirname'], '/');

        foreach ( glob($dir . '/' . self::globEscape($info['filename']) . '*') ?: [] as $file ){
            $name = pathinfo($file, PATHINFO_FILENAME);

            // só "nome" e "nome-LxA[c]": não apaga "nome-outro.png" de outro original
            if ( $name === $info['filename'] || preg_match('/^' . preg_quote($info['filename'], '/') . '-\d+x\d+c?$/', $name) ){
                @unlink($file);
            }
        }
    }

    /** Original dentro de assets/images (e fora do cache), ou null. */
    private static function original(string $relative): ?string
    {
        $relative = str_replace('\\', '/', $relative);

        if ( $relative === '' || str_contains($relative, "\0") ) return null;

        $base = self::imagesDir();
        $real = realpath($base . '/' . ltrim($relative, '/'));

        if ( $real === false || !is_file($real) ) return null;

        $real = str_replace('\\', '/', $real);

        // dentro de assets/images (prefixo com "/": "images_outra/" não passa) e não é do cache
        if ( !str_starts_with($real, $base . '/') || str_starts_with($real, $base . '/cache/') ) return null;

        return $real;
    }

    private static function render(string $original, string $target, int $width, int $height, string $fit, string $format): bool
    {
        if ( !function_exists('imagecreatefromstring') ) return false;

        set_error_handler(static fn () => true);

        try {
            $source = imagecreatefromstring((string) file_get_contents($original));
        } finally {
            restore_error_handler();
        }

        if ( $source === false ) return false;

        $srcW = imagesx($source);
        $srcH = imagesy($source);
        [$dstW, $dstH, $cropX, $cropY, $cropW, $cropH] = self::geometry($srcW, $srcH, $width, $height, $fit);

        $image = imagecreatetruecolor($dstW, $dstH);

        if ( $format === 'jpg' ){
            imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        } else {
            imagealphablending($image, false);
            imagesavealpha($image, true);
            imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        }

        imagecopyresampled($image, $source, 0, 0, $cropX, $cropY, $dstW, $dstH, $cropW, $cropH);

        if ( !File::ensureDir(dirname($target)) ) return false;

        $temp = $target . '.' . bin2hex(random_bytes(4)) . '.tmp';

        $ok = match ($format) {
            'webp' => imagewebp($image, $temp, 85),
            'png' => imagepng($image, $temp, 6),
            default => imagejpeg($image, $temp, 85),
        };

        unset($image, $source);

        if ( !$ok || !@rename($temp, $target) ){
            @unlink($temp);
            return false;
        }

        return true;
    }

    /**
     * @return array{0:int,1:int,2:int,3:int,4:int,5:int} [largura, altura, corteX, corteY, corteL, corteA]
     */
    private static function geometry(int $srcW, int $srcH, int $width, int $height, string $fit): array
    {
        if ( !$width && !$height ) return [$srcW, $srcH, 0, 0, $srcW, $srcH];

        // um lado livre: proporção do original, sem ampliar
        if ( !$width || !$height ){
            $scale = min(1, $width ? $width / $srcW : $height / $srcH);
            return [max(1, (int) round($srcW * $scale)), max(1, (int) round($srcH * $scale)), 0, 0, $srcW, $srcH];
        }

        if ( $fit === self::COVER ){
            // preenche o quadro cortando o excesso no centro
            $ratio = max($width / $srcW, $height / $srcH);
            $cropW = (int) round($width / $ratio);
            $cropH = (int) round($height / $ratio);

            return [$width, $height, (int) (($srcW - $cropW) / 2), (int) (($srcH - $cropH) / 2), $cropW, $cropH];
        }

        // cabe inteiro, sem ampliar
        $scale = min(1, $width / $srcW, $height / $srcH);

        return [max(1, (int) round($srcW * $scale)), max(1, (int) round($srcH * $scale)), 0, 0, $srcW, $srcH];
    }

    private static function imagesDir(): string
    {
        $dir = rtrim(DIR_ASSETS, '/\\') . '/images';

        // realpath: mesma forma (barras e caixa do drive no Windows) que os caminhos comparados
        return rtrim(str_replace('\\', '/', realpath($dir) ?: $dir), '/');
    }

    private static function globEscape(string $text): string
    {
        return preg_replace('/([*?\[\]])/', '[$1]', $text);
    }
}
