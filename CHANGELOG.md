# Changelog

Todas as mudanças relevantes do `wep/framework`. Quebras de compatibilidade ficam sempre na
seção **Mudanças de comportamento** de cada entrada.

## [Não lançado]

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

