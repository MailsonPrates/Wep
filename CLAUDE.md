# wep/framework (Core)

Micro framework PHP (>= 8.2) com front em React. Namespace `Wep\` (PSR-4 em `src/`); alias
`App\Core\X` → `Wep\X` em `src/compat.php` (deprecated, mantido na 2.x). O template que usa este
pacote fica em `../wep-template` (pacote `wep/project`, ver o CLAUDE.md de lá).

## Comandos

```
php -d extension=pdo_sqlite -d extension=gd vendor/bin/pest          # suíte (o php.ini local não carrega essas extensões)
php -d extension=pdo_sqlite vendor/bin/pest tests/Unit/Http           # um diretório
```
Testes de integração MySQL (pulados sem as variáveis; o banco precisa existir):
`DB_TEST_HOST=127.0.0.1 DB_TEST_NAME=<banco> DB_TEST_USER=root DB_TEST_PASS= php -d extension=pdo_sqlite vendor/bin/pest`
(MySQL do XAMPP: `C:/xampp/mysql/bin/mysql -uroot`). Para validar no app real use o sandbox do
template: `npm run e2e` em `../wep-template` (usa este Core por path repo, sem cópia).

## Mapa

| Área | Onde |
|---|---|
| Boot, config, paths, constantes | `Core.php`, `Foundation/{Config,Paths,ErrorHandler}.php`, `Env/` |
| Caminho da requisição (roteador, CSRF, ErrorHandler) | `Http/RequestPath.php` |
| Roteador | `Router/` (`Route`, `RouteCollection`, `Http/RouterHttpRequest`, `Middlewares/`) |
| CSRF, headers, CSP | `Http/{OriginGuard,SecurityHeaders,Csp}.php` |
| CRUD automático (o front nunca monta query) | `Controller.php`, `Model.php`, `Api/ClientPayload.php` |
| Banco | `DB.php`, `DB/` (docs em `docs/DB.md`) |
| Sessão, tokens, rate limit | `Session.php`, `Security/` |
| HTML/escape, templates de página | `View/` (`Html::text/escape/json`) |
| `php app update` (build) e `install` | `Engine/{Update,Install,Commands}.php`, `Engine/Builder/` |
| Cache, arquivos, upload | `Cache.php`, `File.php` (`putAtomic`, `ensureDir`), `Upload.php` |
| Cliente HTTP e módulos vendor | `Http/HttpRequest.php`, `Vendor*.php`, `Engine/Builder/Vendor/` |

## Regras

- **Compatibilidade:** quebrar só quando há risco de segurança, e sempre registrar em `CHANGELOG.md`
  na seção "Mudanças de comportamento" da versão. Toda mudança relevante entra no CHANGELOG.
- Toda correção tem teste Pest (`tests/Unit` ou `tests/Feature`; helpers em `tests/Support/`:
  `fakeRequest`, `dispatch`, `testApp`, `tempDir`, `crudDatabase`...).
- Arquivos gerados pelo build são dados (`var_export`) gravados com `File::putAtomic`, nunca código
  montado por concatenação de texto.
- O front nunca monta queries: só `id`, `fields` e filtros declarados em `'api' => ['filters' => ...]`.
- Commits e CHANGELOG em português. Versão pela tag (o `composer.json` não tem `version`).
- Branches: trabalho na `develop`; release = merge `--no-ff` na `main` + tag anotada + push (só quando o
  usuário pedir). O Packagist atualiza sozinho pelas tags.

## Ambiente (Windows)

- O `php` do terminal (`C:\php`) não tem `gd` nem `pdo_sqlite`: use `-d extension=...`.
- git 2.35 (sem `merge-tree --write-tree`).
- O Windows Defender bloqueia ler arquivos com `system($_GET`: não use isso em fixtures.
- Prefira as ferramentas de arquivo a heredocs grandes no shell (o shell estraga `\` e blocos longos).
