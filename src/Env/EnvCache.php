<?php

namespace Wep\Env;

/**
 * Cache compilado do .env (storage/builds/env.build.php).
 *
 * Gerado pelo `php app update` quando ENV não é DEV: o .env vira um array PHP, servido pelo
 * OPcache, e deixa de ser lido e interpretado a cada requisição.
 *
 * Se o .env for alterado depois do build (mtime mais novo), o cache é ignorado automaticamente
 * até o próximo `php app update`, então nunca se serve valor velho.
 *
 * Atenção: o arquivo contém os mesmos segredos do .env. Ele fica em storage/, que não deve ser
 * acessível pela web (o DocumentRoot é public/).
 */
final class EnvCache
{
    public function __construct(private string $file)
    {
    }

    public function file(): string
    {
        return $this->file;
    }

    /**
     * @return array<string, mixed>|null null quando não há cache válido para o .env informado
     */
    public function read(string $envFile): ?array
    {
        if (!is_file($this->file)) {
            return null;
        }

        // ">=": com resolução de segundos, uma edição no mesmo segundo do build relê o .env
        if (is_file($envFile) && filemtime($envFile) >= filemtime($this->file)) {
            return null;
        }

        $values = include $this->file;

        return is_array($values) ? $values : null;
    }

    /**
     * @param array<string, mixed> $values
     */
    public function write(array $values): bool
    {
        $dir = dirname($this->file);

        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            return false;
        }

        $content = "<?php\n\n// Gerado por `php app update` a partir do .env. Não edite: altere o .env e rode o update.\n\n"
            . 'return ' . var_export($values, true) . ";\n";

        $temp = $this->file . '.tmp';

        if (file_put_contents($temp, $content, LOCK_EX) === false || !rename($temp, $this->file)) {
            @unlink($temp);
            return false;
        }

        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($this->file, true);
        }

        return true;
    }

    public function clear(): void
    {
        if (is_file($this->file)) {
            @unlink($this->file);
        }
    }
}
