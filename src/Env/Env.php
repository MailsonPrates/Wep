<?php

namespace App\Core\Env;

use App\Core\Env\AbstractProcessor;
use App\Core\Env\BooleanProcessor;
use App\Core\Env\QuoteProcessor;

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
     * @todo add opção para se nao existir, criar.
     * 
     * @param string $key
     * @param string $value
     */
    public function set($key, $value)
    {

        if (!is_readable($this->path)) return (object)[
            "error" => true,
            "code" => "file_not_readable",
            "message" => sprintf('%s file is not readable', $this->path)
        ];

        $content = file_get_contents($this->path);
        $contentList = explode("\r\n", $content);

        foreach( $contentList as &$item ){
           
            $isSetLine = str_contains($item, "=");

            if ( !$isSetLine ) continue;

            $itemParts = explode("=", $item);
            $itemKey = $itemParts[0];

           // echo "$itemKey = $key <br>";

            if ( $itemKey != $key ) continue;

            $item = "$key=$value";

            break;
        }

        $newContent = join("\r\n", $contentList);

        file_put_contents($this->path, $newContent);
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