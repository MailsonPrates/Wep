<?php

use Wep\Cache;
use Wep\File;
use Wep\Upload;

// PNG 1x1 válido
const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

function uploadedFile(string $name, string $content, string $type = 'image/jpeg', int $error = UPLOAD_ERR_OK): array
{
    $tmp = tempDir('upload') . '/php' . bin2hex(random_bytes(3)) . '.tmp';
    file_put_contents($tmp, $content);

    return ['name' => $name, 'type' => $type, 'size' => strlen($content), 'error' => $error, 'tmp_name' => $tmp];
}

describe('Cache', function () {

    beforeEach(function () {
        testApp();
        Cache::flush();
    });

    it('guarda e lê valores (JSON), com false quando não existe', function () {
        Cache::set('menu', ['a' => 1, 'b' => [2, 3]], false);

        expect(Cache::get('menu'))->toBe(['a' => 1, 'b' => [2, 3]])
            ->and(Cache::get('nao_existe'))->toBeFalse()
            ->and(Cache::get('nao_existe', []))->toBe([])
            ->and(Cache::has('menu'))->toBeTrue()
            ->and(Cache::has('nao_existe'))->toBeFalse();
    });

    it('respeita a validade (antes o get ignorava e o valor valia para sempre)', function () {
        Cache::set('curto', 'x', -1);
        Cache::set('longo', 'y', 3600);

        expect(Cache::get('curto'))->toBeFalse()
            ->and(Cache::get('longo'))->toBe('y');
    });

    it('chave não é padrão de glob nem caminho (antes "senha_*" lia a chave de outro)', function () {
        Cache::set('senha_reset_maria', 'segredo', false);
        Cache::set('a/b', 'ab', false);

        expect(Cache::get('senha_reset_*'))->toBeFalse()
            ->and(Cache::get('*'))->toBeFalse()
            ->and(Cache::get('b'))->toBeFalse()
            ->and(Cache::get('a/b'))->toBe('ab');

        Cache::delete('*');

        expect(Cache::get('senha_reset_maria'))->toBe('segredo');
    });

    it('delete, flush e refresh', function () {
        Cache::set('a', 1, false);
        Cache::set('b', 2, -1);
        file_put_contents(DIR_CACHE . '/cache.antigo.123', '"x"'); // formato antigo

        Cache::refresh();

        expect(glob(DIR_CACHE . '/cache.*'))->toHaveCount(1);

        Cache::delete('a');
        expect(Cache::get('a'))->toBeFalse();

        Cache::set('c', 3);
        Cache::flush();
        expect(glob(DIR_CACHE . '/cache.*'))->toBe([]);
    });

    it('cria a pasta se não existir e não deixa temporários', function () {
        removeDir(DIR_CACHE);

        expect(Cache::set('k', 'v'))->toBeTrue()
            ->and(Cache::get('k'))->toBe('v')
            ->and(glob(DIR_CACHE . '/*.tmp'))->toBe([]);
    });
});

describe('File', function () {

    it('ler não cria o arquivo (antes criava arquivo e pastas)', function () {
        $path = tempDir('file') . '/sub/x.json';

        expect((new File($path))->read())->toBeFalse()
            ->and(file_exists($path))->toBeFalse();
    });

    it('write: arrays viram JSON, texto fica como está, e cria as pastas', function () {
        $dir = tempDir('file');
        $json = new File("$dir/a/mapa.json");
        $text = new File("$dir/b/texto.txt");

        expect($json->write(['a' => 1]))->toBeTrue()
            ->and($json->read(true))->toBe(['a' => 1])
            ->and($text->write('olá', true))->toBeTrue()
            ->and($text->read())->toBe('olá')
            ->and(glob("$dir/a/*.tmp"))->toBe([]);
    });

    it('isEmpty() devolve bool (antes 0 para vazio e false para cheio)', function () {
        $dir = tempDir('file');
        file_put_contents("$dir/vazio", '');
        file_put_contents("$dir/cheio", 'x');

        expect(File::isEmpty("$dir/vazio"))->toBeTrue()
            ->and(File::isEmpty("$dir/cheio"))->toBeFalse()
            ->and(File::isEmpty("$dir/nao-existe"))->toBeTrue();
    });

    it('deleteAll remove também arquivos ocultos', function () {
        $dir = tempDir('file');
        mkdir("$dir/x/y", 0777, true);
        file_put_contents("$dir/x/.oculto", '1');
        file_put_contents("$dir/x/y/z.txt", '1');

        File::deleteAll("$dir/x", true);

        expect(is_dir("$dir/x"))->toBeFalse();
    });

    it('pastas criadas sem permissão de escrita para todos (antes 0777)', function () {
        $dir = tempDir('file') . '/nova/sub';
        File::put("$dir/a.txt", 'x');

        expect(file_get_contents("$dir/a.txt"))->toBe('x');

        if (DIRECTORY_SEPARATOR === '/') expect(fileperms($dir) & 0002)->toBe(0);
    });
});

describe('Upload', function () {

    beforeEach(function () {
        testApp();
        Upload::$mover = fn ($from, $to) => rename($from, $to);
    });

    afterEach(function () {
        Upload::$mover = null;
        removeDir(DIR_ASSETS . '/images');
    });

    it('"foto.php.jpg" com PHP dentro é recusado (antes era salvo como .php em pasta pública)', function () {
        // payload inofensivo: antivírus bloqueiam a leitura de arquivos com "system($_GET...)"
        $file = uploadedFile('foto.php.jpg', '<?php echo "executou"; ?>');

        expect(fn () => Upload::image(['file' => $file]))->toThrow(Exception::class, 'Tipo de arquivo inválido')
            ->and(fn () => File::upload(['file' => $file]))->toThrow(Exception::class, 'Tipo de arquivo inválido');
    });

    it('a extensão vem do conteúdo, não do nome nem do type do cliente', function () {
        $path = Upload::image(['file' => uploadedFile('foto.php.jpg', base64_decode(PNG_1X1), 'image/jpeg'), 'prefix' => 'u1']);

        expect($path)->toEndWith('.png')
            ->and(basename($path))->toStartWith('u1-')
            ->and(dirname($path))->toBe(DIR_ASSETS . '/images')
            ->and(Upload::relative($path))->toBe('/' . basename($path));
    });

    it('imagem com PHP anexado no fim continua sendo salva como imagem (.png), nunca .php', function () {
        $path = File::upload(['file' => uploadedFile('x.php', base64_decode(PNG_1X1) . '<?php echo 1; ?>')]);

        expect(pathinfo($path, PATHINFO_EXTENSION))->toBe('png');
    });

    it('subpasta: aceita "/users", recusa ".." e caracteres estranhos', function () {
        $path = Upload::image(['file' => uploadedFile('a.png', base64_decode(PNG_1X1)), 'directory' => '/users/fotos']);

        expect(dirname($path))->toBe(DIR_ASSETS . '/images/users/fotos');

        foreach (['/../../../fora', '/users/../..', 'C:/x', "/a\0b"] as $dir) {
            expect(fn () => Upload::image(['file' => uploadedFile('a.png', base64_decode(PNG_1X1)), 'directory' => $dir]))
                ->toThrow(Exception::class, 'Diretório de upload inválido');
        }
    });

    it('tamanho real, erros do PHP e falha ao mover viram exceção', function () {
        $png = base64_decode(PNG_1X1);

        expect(fn () => Upload::image(['file' => uploadedFile('a.png', $png), 'max_size' => 10]))
            ->toThrow(Exception::class, 'muito grande')
            ->and(fn () => Upload::image(['file' => uploadedFile('a.png', $png, 'image/png', UPLOAD_ERR_INI_SIZE)]))
            ->toThrow(Exception::class, 'muito grande')
            ->and(fn () => Upload::image(['file' => uploadedFile('a.png', $png, 'image/png', UPLOAD_ERR_PARTIAL)]))
            ->toThrow(Exception::class, 'erro ao fazer upload')
            ->and(fn () => Upload::image([]))
            ->toThrow(Exception::class, 'Arquivo não enviado');

        Upload::$mover = fn () => false;

        expect(fn () => Upload::image(['file' => uploadedFile('a.png', $png)]))
            ->toThrow(Exception::class, 'erro ao salvar');
    });

    it('sem "prefix" não gera warning', function () {
        $path = Upload::image(['file' => uploadedFile('a.png', base64_decode(PNG_1X1))]);

        expect(basename($path))->toMatch('/^[a-f0-9]{24}\.png$/');
    });
});
