# Changelog

Todas as mudanças relevantes do `wep/framework`. Quebras de compatibilidade ficam sempre na
seção **Mudanças de comportamento** de cada entrada.

## [Não lançado]

### Namespace (Módulo 1)

- O namespace do Core passou de `App\Core\` para **`Wep\`** (por exemplo, `Wep\DB`, `Wep\Router\Route`
  e `Wep\Http\iMiddleware`). Assim ele não colide mais com o `App\` das aplicações.
- **Compatível:** `src/compat.php` cria sob demanda o alias `App\Core\X` → `Wep\X`. `use App\Core\Model`,
  `implements App\Core\Http\iMiddleware` e os `route-maps.build.php` já gerados continuam funcionando.
  O alias está marcado como deprecated e será removido na 2.0.
- O mapeamento manual `"App\\Core\\": "vendor/wep/framework/src/"` no `composer.json` das aplicações
  deixa de ser necessário e pode ser removido. Mantê-lo também funciona, porque o loader de compatibilidade
  roda antes do Composer.
- Código gerado (`php app update` e `php app create module`) passa a usar `Wep\`.

### Foundation (Módulo 1)

#### Novidades

- **Tratamento central de erros** (`Wep\Foundation\ErrorHandler`), registrado no `App::start()`:
  - exceções não capturadas e erros fatais são registrados em `storage/log/app-AAAA-MM-DD.log`;
  - a resposta é JSON em requisições de API (`/api/`, `Accept: application/json` ou `X-Requested-With`)
    e uma página HTML simples nos outros casos;
  - com `ENV=DEV`, mostra mensagem, arquivo:linha e stack trace. Fora de DEV a resposta é sempre genérica
    e o `display_errors` é desligado;
  - o status HTTP vem do código da exceção quando for 4xx/5xx (exceções do router), senão é 500;
  - warnings, notices e deprecations só vão para o log, sem interromper a requisição. Em DEV o PHP
    continua exibindo como antes;
  - para desligar e usar um handler próprio: `'error_handler' => false` no `config/app.php`.
- **Cache do `.env` em produção:** fora de DEV, o `php app update` compila o `.env` em
  `storage/builds/env.build.php` (array PHP servido pelo OPcache), e o arquivo deixa de ser interpretado
  a cada requisição. Se o `.env` for alterado depois do build, o cache é ignorado até o próximo update.
  Em DEV o cache é removido.
- `config/app.php` aceita `'timezone' => 'America/Sao_Paulo'`.
- `App::path('config', '.env')` (antes era um `@todo` vazio), `App::paths()` (objeto `Wep\Foundation\Paths`)
  e `App::env('chave', $padrao)`.
- `Env::set()` acrescenta a variável quando ela não existe (antes ignorava em silêncio).

#### Mudanças de comportamento

1. **`App::config()` e `App::env()` respeitam `false`, `0` e `"0"`.** Antes o `?:` fazia esses valores
   caírem no fallback (`DEBUG=false` com fallback `true` devolvia `true`). Agora só `null` e `""` contam
   como "sem valor". O mesmo vale para `App::data()`.
2. **Valores `true`/`false` do `.env` chegam como bool** em `App::config('env.x')`. Antes passavam por
   `getenv()` e viravam `"1"`/`""`. O `getenv()` continua igual.
3. `App::config('env.x')` só reconhece `env.` como **prefixo** (antes qualquer chave contendo `env.`).
4. **`App::start()` sem `dir.root` lança `InvalidArgumentException`.** Antes imprimia um JSON e devolvia
   `null`, e o `run()` seguinte quebrava com fatal.
5. **`App::assets()` não depende mais do caminho físico da raiz.** Antes o `explode("/public", ...)`
   quebrava quando o root continha `/public` (ex.: `/home/user/public_html/app` no cPanel).
   `App::assets($path, true)` usa a data de modificação do arquivo (antes `time()`, que impedia o cache
   do navegador) e usa `&v=` quando o caminho já tem query string.
6. **`Env::set()` recusa valores com quebra de linha** (permitia injetar variáveis no `.env`) e preserva o
   estilo de quebra de linha do arquivo (antes só reconhecia `\r\n`).
7. `Env` ignora linhas sem `=` e nomes inválidos (antes gerava warning), aceita prefixo `export` e BOM.
8. **`Singleton`:** uma instância por subclasse. Antes, no PHP 8.1+, todas as subclasses compartilhavam a
   mesma instância. O construtor passou a ser `protected` e `unserialize()` lança `LogicException`.
9. As constantes globais (`DIR_ROOT`, `ENV`, `APP_URL`...) continuam definidas, agora só se ainda não
   existirem (um segundo `start()` não gera warning). **Deprecated**: prefira `App::path()`/`App::paths()`.
10. Removido o trait interno `Wep\Engine\Boot`, absorvido pelo `Core`.
11. `BuilderHooks` não depende mais da classe `App\App` do projeto.

### Repositório (Módulo 0)

- `vendor/` e `composer.lock` deixaram de ser versionados (já constavam no `.gitignore`).
- `composer.json` declara os requisitos de plataforma: `php >= 8.2`, `ext-curl`, `ext-iconv`,
  `ext-json`, `ext-mbstring`, `ext-pdo`, `ext-pdo_mysql`. Removido o campo `version` (a versão vem da tag git).
- Pest atualizado para `^2.36 || ^3.0`; script `composer test`.
- Testes do DB movidos de `src/DB/tests` para `tests/{Unit,Feature,Integration}/DB`, agora rodando
  contra as classes reais do Core (sem stubs). Documentação movida para `docs/DB.md`.
- `.gitattributes`: normalização de quebras de linha (LF) e `export-ignore` de `tests/`, `docs/` e
  arquivos de dev; o pacote instalado pelo Composer fica menor.
- Removidos arquivos mortos: `test`, `tests/DBTest.php` (substituído pela nova suíte),
  `tests/BuildVendorTest.php` (todo comentado), `package.json`/`tests/front` (o front saiu do Core) e
  `src/DB/Query/Builders/Verbose.php` (vazio).

#### Requisito novo para rodar os testes

- A extensão `pdo_sqlite` precisa estar habilitada (`extension=pdo_sqlite` no `php.ini`).

### DB


Mantida a API pública (`DB::table()->where()->get()`, `insert/update/delete`, `DB::query`,
transações, `setConfig`, modo verbose, `debug`, `raw`, `fetch`). O que mudou está abaixo;
**leia a seção "Mudanças de comportamento" antes de subir.**

#### Segurança

| Problema no original | Correção |
|---|---|
| Nomes de coluna/tabela concatenados na query (SQL injection via `orderBy`, `where`, `get`, `leftJoin`, `update`, `insert`, nome da tabela). 11 de 13 vetores testados passavam. | Novo `Builders/Identifier`: só aceita `col`, `tabela.col`, `*`, `col AS alias`, `DISTINCT`, `COUNT/SUM/AVG/MIN/MAX` e `RAND()` no order. Tudo é escapado com crase. Qualquer outra coisa vira `Response::error`. |
| `limit()` / `page()` concatenados sem validar. | Forçados para inteiros >= 0. |
| `update()` com só `or()` passava pelo guard "no where clause" e gerava `SET a = :x OR id = :y`, **atualizando a tabela inteira**. | `or()`/`andOr()` sem `where()` agora é erro. |
| Operador inválido executava `exit()`, matando o processo. | Vira `Response::error`. |
| DSN montado por concatenação (`db_name = "x;host=outro"` trocava o servidor). | `host`, `dbname` e `charset` validados por regex. |
| Várias queries por chamada (`;`) habilitadas: qualquer injection virava "stacked query". | `MYSQL_ATTR_MULTI_STATEMENTS` desligado (religue com `setConfig(['multi_statements' => true])`). |
| Mensagem crua do MySQL (tabelas, colunas, usuário@host) devolvida a quem chamou. | Detalhe vai para `error_log`; quem chama recebe mensagem genérica (`Registro duplicado`, etc.). |
| `Connection` guardava a senha em propriedade (aparecia em `print_r`/`var_dump`/logs de objeto). | Credenciais não ficam mais no objeto. |
| `fetch('qualquer')` passava direto para `constant()`. | Whitelist de modos. |

#### Bugs corrigidos

- `where('stock', '>', 0)` / `'!='` com `''`: valor "falsy" era confundido com operador omitido (virava `stock = '>'`). Agora decidido pela quantidade de argumentos.
- `where(['status' => 'on'])` e `where('id', [1,2])` (antes erro/TypeError) funcionam; array vira `IN`.
- `DB::table($t, ['debug' => true])` sobrescrevia `DB::$pdo` com um objeto vazio e quebrava todas as queries seguintes. Debug não abre mais conexão.
- `DB::query("SELECT '10:30'")` era rejeitada como "Missing placeholder values".
- `DB::query` com `?` posicional; detecção de tipo de query (`\n SELECT`, `WITH`, `SHOW`) agora vem de `columnCount()`.
- `onDuplicateUpdate`: `0` era descartado; podia gerar `ON DUPLICATE KEY UPDATE ` vazio (SQL inválido).
- `IN ()` vazio gerava SQL inválido (agora `1 = 0` / `1 = 1`).
- Tabela com nome reservado (`order`) quebrava no join (agora com crase).
- `leftJoin(['tabela' => [[a,b],[c,d]]])` descartava a 1ª condição `ON`.
- `delete(['where' => ['id', 1]])` gerava condição inválida; agora aceita as mesmas formas do `update` verboso.
- `uniqid()` como placeholder podia repetir e corromper o bind silenciosamente; trocado por `:p1, :p2...`.
- `Condition` usava propriedade estática compartilhada (não reentrante) e variável `$aliases` inexistente.
- `bool false` era gravado como `''` (erro em coluna `TINYINT` no modo estrito).
- Estado do `Handler` agora é resetado mesmo quando a montagem falha (`try/finally`).

#### Performance

- `uniqid()` por placeholder (faz `usleep`): `IN` com 1000 valores ~2x mais rápido na montagem.
- Removido o `SET NAMES` no connect (o charset já vai no DSN): -1 round-trip por conexão.
- `bindValue` no lugar de `bindParam` por referência em objeto; `isAssoc` sem alocar `range()`.
- Debug não abre conexão; `parseQueryRaw` só roda em debug.
- Queries de mesmo formato agora geram SQL idêntico (`:p1...`), o que ajuda cache de statement/log.

Nas queries simples a diferença some: o tempo é dominado pelo round-trip ao banco.


#### Mudanças de comportamento (revise)

1. **Expressões SQL em campos/where/order** (`IFNULL(a,b)`, `LOWER(col)`, `DATE(x)`) agora exigem `DB::raw('...')`. Barradas com mensagem explicando.
2. **Mensagens de erro do banco genéricas.** Se algum código depende do texto cru, use `DB::setConfig(['expose_errors' => true])`.
3. **Multi-statements desligado.** Migrations/scripts com `;` em um `DB::query` precisam de `multi_statements => true`.
4. **`null_as_empty` continua `true`** (NULL gravado como `""`, como no original). Recomendo desligar (`setConfig(['null_as_empty' => false])`) depois de checar as colunas `NOT NULL`.
5. `onDuplicateUpdate`: `0`/`"0"` agora atualizam; `null`/`""` continuam sem sobrescrever; sem colunas válidas, a cláusula é omitida.
6. `or()`/`andOr()` sem `where()`, `between` sem 2 valores e operador inválido retornam `Response::error`.
7. Charset padrão `utf8mb4` (antes `utf8`); timeout de conexão 10s (`db_timeout`).
8. `DB::query` que não retorna linhas passa a responder `last_id` e `affected_rows` (antes tentava `fetchAll`).
9. `page(0)` ou negativa vira página 1.
10. `Executor::execute()` aceita `?PDO` (necessário para o modo debug).
11. Placeholders no SQL/debug agora são `:p1, :p2...`; `query_raw` passa a quotar os valores.
12. Arquivos normalizados para LF (o original misturava CRLF e LF).

#### Novidades

- `DB::raw($sql)` para expressões confiáveis. `setConfig`: `null_as_empty`, `expose_errors`, `multi_statements`.
- `where(['col' => valor])` (assoc), `Response` com `affected_rows`, alias de tabela (`'orders o'`).
- Novas classes: `Query/Raw`, `Query/Formatter`, `Query/Builders/Identifier`, `Query/Builders/Placeholders`.

#### Mantido de propósito (não alterei)

- Valores continuam bindados como string (bindar int contra `VARCHAR` faz o MySQL converter a coluna: `code = 0` casaria com qualquer texto e perderia o índice).
- `fetch('obj')` continua devolvendo só a primeira linha.
- `orderBy('a','b','DESC')` continua gerando `ORDER BY a, b DESC` (DESC só em `b`).
- Precedência de `or()` (`WHERE a AND b OR c`) e só `LEFT JOIN`.
- **Transações aninhadas**: `beginTransaction()` dentro de outra é no-op e o `commit()` interno fecha a externa. É um risco real, mas é decisão de design (savepoints), então não mexi.
- `DB::$pdo` público. (`Builders/Verbose.php`, que estava vazio, foi removido no Módulo 0.)

