<?php

use Wep\ImageCache;

/** PNG com transparência em assets/images/<relativo>. */
function cacheTestImage(string $relative, int $width = 400, int $height = 200): string
{
    testApp();

    $file = DIR_ASSETS . '/images' . $relative;
    if (!is_dir(dirname($file))) mkdir(dirname($file), 0777, true);

    $image = imagecreatetruecolor($width, $height);
    imagesavealpha($image, true);
    imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
    imagefilledrectangle($image, 10, 10, $width - 10, $height - 10, imagecolorallocate($image, 20, 120, 200));
    imagepng($image, $file);

    return $file;
}

beforeEach(function () {
    if (!function_exists('imagewebp')) $this->markTestSkipped('requer GD com WebP');
});

afterEach(function () {
    removeDir(DIR_ASSETS . '/images/cache');
    removeDir(DIR_ASSETS . '/images/teste-cache');
});

describe('ImageCache', function () {

    it('gera a versão .webp no cache e mantém o original', function () {
        $original = cacheTestImage('/teste-cache/foto.png');
        $before = md5_file($original);

        $url = ImageCache::url('/teste-cache/foto.png', 36, 36, ImageCache::COVER);

        expect($url)->toMatch('#^/assets/images/cache/teste-cache/foto-36x36c\.webp\?v=\d+$#');

        $file = DIR_ASSETS . '/images/cache/teste-cache/foto-36x36c.webp';
        [$w, $h, $type] = getimagesize($file);

        expect([$w, $h, $type])->toBe([36, 36, IMAGETYPE_WEBP])
            ->and(md5_file($original))->toBe($before);
    });

    it('contain: cabe no quadro sem ampliar e sem cortar', function () {
        cacheTestImage('/teste-cache/logo.png', 900, 300);

        ImageCache::url('/teste-cache/logo.png', 0, 120);
        [$w, $h] = getimagesize(DIR_ASSETS . '/images/cache/teste-cache/logo-0x120.webp');

        ImageCache::url('/teste-cache/logo.png', 2000, 2000);
        [$bigW, $bigH] = getimagesize(DIR_ASSETS . '/images/cache/teste-cache/logo-2000x2000.webp');

        expect([$w, $h])->toBe([360, 120])->and([$bigW, $bigH])->toBe([900, 300]);
    });

    it('reaproveita o cache e regera quando o original muda', function () {
        $original = cacheTestImage('/teste-cache/a.png');
        $file = ImageCache::path('/teste-cache/a.png', 50, 50);

        touch($file, time() + 10);
        expect(ImageCache::path('/teste-cache/a.png', 50, 50))->toBe($file)
            ->and(filemtime($file))->toBe(time() + 10);

        touch($original, time() + 20);
        ImageCache::path('/teste-cache/a.png', 50, 50);
        clearstatcache();

        expect(filemtime($file))->toBeLessThan(time() + 10);
    });

    it('recusa caminhos fora de assets/images, o próprio cache e o que não é imagem', function () {
        cacheTestImage('/teste-cache/b.png');
        file_put_contents(DIR_ASSETS . '/images/teste-cache/texto.png', 'não é imagem');
        ImageCache::path('/teste-cache/b.png', 10, 10);

        expect(ImageCache::url('/../../config/.env'))->toBeNull()
            ->and(ImageCache::url('/cache/teste-cache/b-10x10.webp'))->toBeNull()
            ->and(ImageCache::url('/teste-cache/nao-existe.png'))->toBeNull()
            ->and(ImageCache::url('/teste-cache/texto.png'))->toBeNull()
            ->and(ImageCache::url(''))->toBeNull();
    });

    it('forget apaga só as versões daquele original', function () {
        cacheTestImage('/teste-cache/c.png');
        cacheTestImage('/teste-cache/c-outro.png');
        ImageCache::path('/teste-cache/c.png', 10, 10);
        ImageCache::path('/teste-cache/c.png', 20, 20, ImageCache::COVER);
        ImageCache::path('/teste-cache/c-outro.png', 10, 10);

        ImageCache::forget('/teste-cache/c.png');

        expect(glob(DIR_ASSETS . '/images/cache/teste-cache/*'))->toHaveCount(1)
            ->and(basename(glob(DIR_ASSETS . '/images/cache/teste-cache/*')[0]))->toBe('c-outro-10x10.webp');
    });
});
