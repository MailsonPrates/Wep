<?php

namespace App\Core\Env;

use App\Core\Env\AbstractProcessor;
use App\Core\Env\BooleanProcessor;
use App\Core\Env\QuoteProcessor;
use App\Core\Response;
use Exception;

/**
 * @version v1.0.1
 * - Adicionado metodo set para atualizar valores
 */

/**
 * @src https://github.com/devcoder-xyz/php-dotenv
 */
class Env
{
    /**
     * The directory where the .env file can be located.
     *
     * @var string
     */
    protected $path;

    /**
     * Configure the options on which the parsed will act
     *
     * @var string[]
     */
    protected $processors = [];

    public function __construct(string $path, array $processors = null)
    {
        if (!file_exists($path)) {
            throw new \InvalidArgumentException(sprintf('%s does not exist', $path));
        }

        $this->path = $path;

        $this->setProcessors($processors);
    }

    private function setProcessors(array $processors = null) : Env
    {
        /**
         * Fill with default processors
         */
        if ($processors === null) {
            $this->processors = [
                BooleanProcessor::class,
                QuotedProcessor::class
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
     * Processes the $path of the instances and parses the values into $_SERVER and $_ENV, 
     * also returns all the data that has been read.
     * Skips empty and commented lines.
     */
    public function load() : void
    {
        if (!is_readable($this->path)) {
            throw new \RuntimeException(sprintf('%s file is not readable', $this->path));
        }

        $lines = file($this->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {

            if (strpos(trim($line), '#') === 0) {
                continue;
            }

            list($name, $value) = explode('=', $line, 2);
            $name = trim($name);
            
            /*
             * When has namespace skip not same group
             */
            // todo: code
            
            $value = $this->processValue($value);

            if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
                putenv(sprintf('%s=%s', $name, $value));
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }
        }
    }

    /**
     * Atualiza valores de uma variavel
     * 
     * @param string $key
     * @param string $value
     */
    public function set($key, $value)
    {
        try {

            /**
             * Lê o arquivo
             */
            // Ler o conteúdo do arquivo original
            if (!file_exists($this->path)) {
                throw new Exception('Arquivo não encontrado:');
            }

            $fileHandle = fopen($this->path, 'rb');
            if (!$fileHandle) {
                throw new Exception('Erro ao abrir o arquivo para leitura');
            }

            if (!flock($fileHandle, LOCK_SH)) {
                fclose($fileHandle);
                throw new Exception('Não foi possível bloquear o arquivo para leitura.');
            }

            $content = fread($fileHandle, filesize($this->path));
            flock($fileHandle, LOCK_UN);
            fclose($fileHandle);

            if ($content === false) {
                throw new Exception('Erro ao ler o conteúdo do arquivo.');
            }

            /**
             * Atualiza conteudo
             */

            $contentList = explode("\r\n", $content);

            foreach( $contentList as &$item ){
                
                $isSetLine = str_contains($item, "=");
     
                 if ( !$isSetLine ) continue;
     
                 $itemParts = explode("=", $item);
                 $itemKey = $itemParts[0];
     
                 if ( $itemKey != $key ) continue;
     
                 $item = "$key=$value";
     
                 break;
            }
     
            $newContent = join("\r\n", $contentList);

            /**
             * Salva arquivo
             */
             // Salvar no arquivo temporário
             $tempFilePath = $this->path . '.tmp';
             $tempFileHandle = fopen($tempFilePath, 'wb');
             if (!$tempFileHandle) {
                 throw new Exception('Erro ao criar o arquivo temporário.');
             }
 
             if (!flock($tempFileHandle, LOCK_EX)) {
                 fclose($tempFileHandle);
                 unlink($tempFilePath);
                 throw new Exception('Não foi possível bloquear o arquivo temporário.');
             }
 
             if (fwrite($tempFileHandle, $newContent) === false) {
                 flock($tempFileHandle, LOCK_UN);
                 fclose($tempFileHandle);
                 unlink($tempFilePath);
                 throw new Exception('Erro ao escrever no arquivo temporário.');
             }
 
             fflush($tempFileHandle);
             flock($tempFileHandle, LOCK_UN);
             fclose($tempFileHandle);
 
             // Substituir o arquivo original
             if (!rename($tempFilePath, $this->path)) {
                 unlink($tempFilePath);
                 throw new Exception('Erro ao substituir o arquivo original.');
             }

             /**
              * Recarregar env
              */
              $this->load();

              return Response::success();

        } catch (\Throwable $th) {
            return Response::error($th->getMessage() . ' ' . $this->path);
        }
    }

    /**
     * Process the value with the configured processors
     *
     * @param string $value The value to process
     * @return string|bool
     */
    private function processValue(string $value)
    {
        /**
         * First trim spaces and quotes if configured
         */
        $trimmedValue = trim($value);

        foreach ($this->processors as $processor) {
            /** @var AbstractProcessor $processorInstance */
            $processorInstance = new $processor($trimmedValue);

            if ($processorInstance->canBeProcessed()) {
                return $processorInstance->execute();
            }
        }

        /**
         * Does not match any processor options, return as is
         */
        return $trimmedValue;
    }
}