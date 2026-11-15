<?php

namespace Wep\Env;

use Wep\Response;

/**
 * Leitor de arquivos .env.
 *
 * Formato aceito por linha: NOME=valor, com prefixo "export " opcional. Linhas vazias, comentários (#)
 * e linhas inválidas são ignoradas. Valores "true"/"false" viram bool e aspas externas são removidas.
 *
 * Variáveis que já existem no ambiente real do servidor ($_SERVER/$_ENV) têm prioridade e não são
 * sobrescritas pelo arquivo.
 *
 * @version 2.0.0
 * @src baseado em https://github.com/devcoder-xyz/php-dotenv
 */
class Env
{
    private const NAME = '/^[A-Za-z_][A-Za-z0-9_.]*$/';

    /** @var string */
    protected $path;

    /** @var class-string<AbstractProcessor>[] */
    protected $processors = [];

    /** @var array<string, mixed> valores efetivos (arquivo ou ambiente real) */
    private array $values = [];

    /** @var array<string, true> chaves definidas por esta instância (podem ser recarregadas) */
    private array $owned = [];

    public function __construct(string $path, ?array $processors = null)
    {
        if (!file_exists($path)) {
            throw new \InvalidArgumentException(sprintf('%s does not exist', $path));
        }

        $this->path = $path;

        $this->setProcessors($processors);
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * Lê o arquivo e publica os valores (getenv, $_ENV e $_SERVER).
     */
    public function load(): void
    {
        $this->apply($this->parse());
    }

    /**
     * Lê o arquivo e devolve os valores, sem publicar nada.
     *
     * @return array<string, mixed>
     */
    public function parse(): array
    {
        if (!is_readable($this->path)) {
            throw new \RuntimeException(sprintf('%s file is not readable', $this->path));
        }

        $values = [];
        $lines = file($this->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        foreach ($lines as $index => $line) {
            if ($index === 0) {
                $line = preg_replace('/^\xEF\xBB\xBF/', '', $line); // BOM
            }

            $line = trim($line);

            if ($line === '' || $line[0] === '#') {
                continue;
            }

            if (str_starts_with($line, 'export ')) {
                $line = ltrim(substr($line, 7));
            }

            $separator = strpos($line, '=');

            if ($separator === false) {
                continue;
            }

            $name = trim(substr($line, 0, $separator));

            if (!preg_match(self::NAME, $name)) {
                continue;
            }

            $values[$name] = $this->processValue(substr($line, $separator + 1));
        }

        return $values;
    }

    /**
     * Publica valores já lidos (do arquivo ou do cache de build).
     *
     * @param array<string, mixed> $values
     */
    public function apply(array $values): void
    {
        foreach ($values as $name => $value) {
            $name = (string) $name;

            $isExternal = !isset($this->owned[$name])
                && (array_key_exists($name, $_SERVER) || array_key_exists($name, $_ENV));

            if ($isExternal) {
                $this->values[$name] = $_ENV[$name] ?? $_SERVER[$name];
                continue;
            }

            $this->owned[$name] = true;
            $this->values[$name] = $value;

            putenv(sprintf('%s=%s', $name, $value));
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->values) ? $this->values[$key] : $default;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->values;
    }

    /**
     * Atualiza (ou acrescenta) uma variável no arquivo e recarrega os valores.
     *
     * @param string $key
     * @param string|int|float|bool $value
     */
    public function set($key, $value)
    {
        try {
            $key = (string) $key;

            if (!preg_match(self::NAME, $key)) {
                throw new \InvalidArgumentException("Nome de variável inválido: $key");
            }

            $value = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;

            // uma quebra de linha no valor criaria novas variáveis no arquivo
            if (preg_match('/[\r\n\0]/', $value)) {
                throw new \InvalidArgumentException("Valor inválido para $key: quebras de linha não são permitidas");
            }

            $content = $this->readFile();
            $eol = str_contains($content, "\r\n") ? "\r\n" : "\n";
            $lines = $content === '' ? [] : preg_split('/\r\n|\n|\r/', $content);
            $pattern = '/^\s*(export\s+)?' . preg_quote($key, '/') . '\s*=/';
            $found = false;

            foreach ($lines as &$line) {
                if (preg_match($pattern, $line)) {
                    $line = "$key=$value";
                    $found = true;
                    break;
                }
            }
            unset($line);

            if (!$found) {
                $last = end($lines);

                // mantém a linha vazia final, se houver
                if ($last === '') {
                    array_splice($lines, -1, 0, ["$key=$value"]);
                } else {
                    $lines[] = "$key=$value";
                }
            }

            $this->writeFile(implode($eol, $lines));
            $this->load();

            return Response::success();

        } catch (\Throwable $th) {
            return Response::error($th->getMessage());
        }
    }

    private function setProcessors(?array $processors = null): Env
    {
        if ($processors === null) {
            $this->processors = [
                BooleanProcessor::class,
                QuotedProcessor::class,
            ];

            return $this;
        }

        foreach ($processors as $processor) {
            if (is_subclass_of($processor, AbstractProcessor::class)) {
                $this->processors[] = $processor;
            }
        }

        return $this;
    }

    /**
     * @return string|bool
     */
    private function processValue(string $value)
    {
        $trimmedValue = trim($value);

        foreach ($this->processors as $processor) {
            /** @var AbstractProcessor $processorInstance */
            $processorInstance = new $processor($trimmedValue);

            if ($processorInstance->canBeProcessed()) {
                return $processorInstance->execute();
            }
        }

        return $trimmedValue;
    }

    private function readFile(): string
    {
        $handle = fopen($this->path, 'rb');

        if (!$handle) {
            throw new \RuntimeException('Erro ao abrir o arquivo .env para leitura');
        }

        try {
            if (!flock($handle, LOCK_SH)) {
                throw new \RuntimeException('Não foi possível bloquear o arquivo .env para leitura');
            }

            $content = stream_get_contents($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }

        if ($content === false) {
            throw new \RuntimeException('Erro ao ler o arquivo .env');
        }

        return $content;
    }

    /**
     * Escreve num arquivo temporário e substitui o original (a troca é atômica).
     */
    private function writeFile(string $content): void
    {
        $temp = $this->path . '.tmp';

        if (file_put_contents($temp, $content, LOCK_EX) === false) {
            @unlink($temp);
            throw new \RuntimeException('Erro ao escrever o arquivo temporário do .env');
        }

        if (!rename($temp, $this->path)) {
            @unlink($temp);
            throw new \RuntimeException('Erro ao substituir o arquivo .env');
        }
    }
}
