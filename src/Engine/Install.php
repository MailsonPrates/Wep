<?php

namespace Wep\Engine;

use Wep\Command\Console;
use Wep\Core;
use Wep\DB;
use Wep\DB\SqlScript;
use Wep\Response;

/**
 * `php app install`: instalação pelo terminal (substitui a página web /install, que era pública,
 * gravava o .env com input do usuário e podia ser executada de novo num site em produção).
 *
 * Etapas:
 *  1. recusa se a aplicação já foi instalada (storage/install.lock), salvo `--force`;
 *  2. grava ENV, APP_URL e os dados do banco no .env (Env::set: sem injeção de linhas);
 *  3. testa a conexão com o banco;
 *  4. executa database/*.sql (em ordem de nome), uma instrução por vez;
 *  5. roda o build (`php app update`);
 *  6. roda o instalador da aplicação (config 'installer'), ex.: cria o usuário admin;
 *  7. grava storage/install.lock.
 */
class Install
{
    public const QUESTIONS = [
        'env' => ['label' => 'Ambiente (DEV ou PROD)', 'default' => 'DEV'],
        'app_url' => ['label' => 'URL da aplicação (ex.: https://app.exemplo.com)'],
        'db_host' => ['label' => 'Host do banco de dados', 'default' => 'localhost'],
        'db_name' => ['label' => 'Nome do banco de dados'],
        'db_username' => ['label' => 'Usuário do banco de dados'],
        'db_password' => ['label' => 'Senha do banco de dados', 'secret' => true],
    ];

    /**
     * Entrada do comando (interativa).
     */
    public static function handle($props = [])
    {
        $force = in_array('--force', (array) ($props['options'] ?? []), true);

        if (!$force && is_file(self::lockFile())) {
            return self::alreadyInstalled();
        }

        $installer = self::appInstaller();
        $questions = self::QUESTIONS + ($installer ? $installer->questions() : []);
        $answers = [];

        // valores já presentes no .env viram o padrão das perguntas (exceto a senha)
        foreach (['env' => 'ENV', 'app_url' => 'APP_URL', 'db_host' => 'DB_HOST', 'db_name' => 'DB_NAME', 'db_username' => 'DB_USERNAME'] as $key => $envKey) {
            $current = Core::env($envKey);

            if (is_string($current) && $current !== '') {
                $questions[$key]['default'] = $current;
            }
        }

        foreach ($questions as $key => $question) {
            $answers[$key] = !empty($question['secret'])
                ? Console::secret($question['label'])
                : Console::ask($question['label'], (string) ($question['default'] ?? ''));
        }

        return self::run($answers, $installer, $force);
    }

    /**
     * Instalação com as respostas já coletadas (usado também nos testes).
     *
     * @param array<string, string> $answers
     */
    public static function run(array $answers, ?Installer $installer = null, bool $force = false)
    {
        if (!$force && is_file(self::lockFile())) {
            return self::alreadyInstalled();
        }

        $invalid = self::validate($answers);

        if ($invalid) {
            return Response::error($invalid);
        }

        // 2. .env
        $env = [
            'ENV' => strtoupper($answers['env']),
            'APP_URL' => rtrim($answers['app_url'], '/'),
            'DB_HOST' => $answers['db_host'],
            'DB_NAME' => $answers['db_name'],
            'DB_USERNAME' => $answers['db_username'],
            'DB_PASSWORD' => $answers['db_password'] ?? '',
        ];

        foreach ($env as $key => $value) {
            $set = Core::$env->set($key, $value);

            if ($set->error) {
                return Response::error("Erro ao gravar $key no .env: " . ($set->message ?? ''));
            }
        }

        // 3. banco
        try {
            DB::$pdo = null;
            DB::connect();
        } catch (\Throwable $e) {
            return Response::error('Não foi possível conectar ao banco de dados: ' . $e->getMessage());
        }

        // 4. schema
        foreach (self::schemaFiles() as $file) {
            foreach (SqlScript::split((string) file_get_contents($file)) as $index => $statement) {
                try {
                    DB::$pdo->exec($statement);
                } catch (\PDOException $e) {
                    error_log('[install] ' . basename($file) . ' #' . ($index + 1) . ': ' . $e->getMessage());

                    return Response::error(
                        'Erro no schema ' . basename($file) . ', instrução ' . ($index + 1) . ': '
                        . ($e->errorInfo[2] ?? 'falha ao executar')
                    );
                }
            }
        }

        // 5. build
        $update = Update::handle();

        if (!empty($update->error)) {
            return $update;
        }

        // 6. instalador da aplicação
        if ($installer) {
            $result = $installer->handle($answers);

            if (!empty($result->error)) {
                return $result;
            }
        }

        // 7. trava
        file_put_contents(self::lockFile(), json_encode(['installed_at' => date('c')]) . PHP_EOL);

        return Response::success([], ['message' => 'Aplicação instalada']);
    }

    public static function lockFile(): string
    {
        return Core::paths()->storage('install.lock');
    }

    /**
     * @return string[]
     */
    public static function schemaFiles(): array
    {
        $files = glob(Core::paths()->root('database') . '/*.sql') ?: [];
        sort($files);

        return $files;
    }

    private static function appInstaller(): ?Installer
    {
        $class = Core::config('installer');

        if (!$class) {
            return null;
        }

        if (!is_string($class) || !class_exists($class) || !is_subclass_of($class, Installer::class)) {
            throw new \RuntimeException("'installer' deve ser uma classe que implementa " . Installer::class);
        }

        return new $class();
    }

    private static function validate(array $answers): ?string
    {
        foreach (array_keys(self::QUESTIONS) as $key) {
            if ($key !== 'db_password' && trim((string) ($answers[$key] ?? '')) === '') {
                return 'Resposta obrigatória: ' . self::QUESTIONS[$key]['label'];
            }
        }

        if (!in_array(strtoupper($answers['env']), ['DEV', 'PROD'], true)) {
            return 'Ambiente deve ser DEV ou PROD';
        }

        if (!filter_var($answers['app_url'], FILTER_VALIDATE_URL) || !preg_match('~^https?://~i', $answers['app_url'])) {
            return 'URL da aplicação inválida';
        }

        return null;
    }

    private static function alreadyInstalled()
    {
        return Response::error('A aplicação já foi instalada (storage/install.lock). Para reinstalar: php app install --force');
    }
}
