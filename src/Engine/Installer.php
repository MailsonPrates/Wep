<?php

namespace Wep\Engine;

/**
 * Etapa da instalação específica da aplicação (ex.: criar o grupo e o usuário admin).
 * Registrada em config/app.php: 'installer' => \App\Common\Installer::class
 *
 * Roda no `php app install`, depois do .env, do schema (database/*.sql) e do build.
 */
interface Installer
{
    /**
     * Perguntas extras para o terminal.
     *
     * @return array<string, array{label: string, secret?: bool, default?: string}>
     */
    public function questions(): array;

    /**
     * @param array<string, string> $answers respostas (as do Core e as de questions())
     * @return object Response (error/message)
     */
    public function handle(array $answers);
}
