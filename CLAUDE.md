# wep/framework (Core)

Micro framework PHP (>= 8.2) com front em React. Namespace `Wep\` (PSR-4 em `src/`); alias
`App\Core\X` → `Wep\X` em `src/compat.php` (deprecated, mantido na 2.x). O template que usa este
pacote fica em `../wep-template` (pacote `wep/project`, ver o CLAUDE.md de lá).

## Comandos

```
php vendor/bin/pest                       # suíte (precisa de gd e pdo_sqlite no php.ini)
php vendor/bin/pest tests/Unit/Http       # um diretório
```
Testes de integração MySQL (pulados sem as variáveis; o banco precisa existir):
`DB_TEST_HOST=127.0.0.1 DB_TEST_NAME=<banco> DB_TEST_USER=root DB_TEST_PASS= php vendor/bin/pest`
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

## Estilo de código (PHP e JS)

- Nada de atribuição dentro de condição. Em vez de `if ( $error = self::check($x) ) return ...;`, escreva:
  ```php
  $error = self::check($x);

  if ( $error ) return $response->json($error);
  ```
- Quebre linhas longas: chamadas e arrays com vários itens ficam um item por linha
  (`$m->action([` / `"a" => 1,` / `"b" => 2,` / `]);`). Sem exagero: chamadas curtas ficam numa linha.
- Front: o React Compiler está ativo no build. Escreva componentes simples, sem `useMemo`/`useCallback`/
  `React.memo` para performance (só quando a identidade estável for necessária por outro motivo).

## Ambiente (Windows)

- git 2.35 (sem `merge-tree --write-tree`).
- O Windows Defender bloqueia ler arquivos com `system($_GET`: não use isso em fixtures.
- Prefira as ferramentas de arquivo a heredocs grandes no shell (o shell estraga `\` e blocos longos).
