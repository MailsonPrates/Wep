# Módulo DB — Documentação

Query builder sobre PDO (MySQL/MariaDB) com API encadeável, modo verboso (um único array), queries SQL
próprias com placeholders e transações. Todos os valores vão por *prepared statements*; nomes de
colunas e tabelas são validados e escapados.

> Exemplos de SQL desta documentação foram gerados executando o próprio módulo (modo `debug`).
> Os placeholders aparecem como `:p1`, `:p2`… exatamente como o módulo os produz.

---

## Sumário

1. [Visão geral](#1-visão-geral)
2. [Início rápido](#2-início-rápido)
3. [Configuração e conexão](#3-configuração-e-conexão)
4. [Formato de retorno](#4-formato-de-retorno)
5. [DB::table() e o Handler](#5-dbtable-e-o-handler)
6. [Leitura: get() / select()](#6-leitura-get--select)
7. [Condições: where, or, andOr](#7-condições-where-or-andor)
8. [Ordenação e paginação: orderBy, limit, page](#8-ordenação-e-paginação-orderby-limit-page)
9. [Joins: leftJoin](#9-joins-leftjoin)
10. [Formato do resultado: fetch e raw](#10-formato-do-resultado-fetch-e-raw)
11. [Escrita: insert / create](#11-escrita-insert--create)
12. [Escrita: update](#12-escrita-update)
13. [Escrita: delete](#13-escrita-delete)
14. [Modo verboso](#14-modo-verboso)
15. [SQL próprio: DB::query()](#15-sql-próprio-dbquery)
16. [Transações](#16-transações)
17. [DB::raw() e regras de identificadores](#17-dbraw-e-regras-de-identificadores)
18. [Debug](#18-debug)
19. [Segurança](#19-segurança)
20. [Mensagens de erro](#20-mensagens-de-erro)
21. [Limitações](#21-limitações)
22. [Receitas](#22-receitas)
23. [Referência rápida](#23-referência-rápida)
24. [Estrutura de arquivos e testes](#24-estrutura-de-arquivos-e-testes)

---

## 1. Visão geral

| Item | Detalhe |
|---|---|
| Banco | MySQL / MariaDB (via `pdo_mysql`) |
| PHP | 8.0 ou superior |
| Estilos de uso | **Encadeado** (`DB::table('t')->where(...)->get()`) e **verboso** (um array com todas as opções) |
| Entrada pública | Classe estática `Wep\DB` |
| Dependências externas ao módulo | `Wep\Response`, `Wep\Core`, `Wep\Singleton`, `Wep\Obj` |

Namespaces: `Wep\DB` (fachada), `Wep\DB\…` (Handler, Connection), `Wep\DB\Query\…` (internos).

---

## 2. Início rápido

```php
use Wep\DB;

// SELECT
$r = DB::table('users')
    ->where('status', 'on')
    ->orderBy('name')
    ->limit(10)
    ->get('id', 'name');

// INSERT
$r = DB::table('users')->insert(['name' => 'Ana', 'age' => 30]);

// UPDATE (exige where)
$r = DB::table('users')->where('id', 1)->update('status', 'off');

// DELETE (exige where)
$r = DB::table('users')->where('id', 1)->delete();

// SQL próprio
$r = DB::query('SELECT status, COUNT(*) AS n FROM users GROUP BY status');
```

Toda chamada devolve um `Response` (veja [seção 4](#4-formato-de-retorno)); erros de validação e do banco
**não lançam exceção** — chegam como `Response::error(...)`.

---

## 3. Configuração e conexão

### 3.1 Como as credenciais são lidas

A conexão é aberta **sob demanda**, na primeira consulta, e reaproveitada (`DB::$pdo`). As credenciais vêm de
`Core::config("env", <chave>)`. Chaves padrão:

| Dado | Chave padrão | Obrigatório | Valor padrão |
|---|---|---|---|
| Host | `db_host` | não | `localhost` |
| Banco | `db_name` | **sim** | — |
| Usuário | `db_username` | **sim** | — |
| Senha | `db_password` | **sim** (não aceita vazia) | — |
| Charset | `db_charset` | não | `utf8mb4` |

Faltando banco, usuário ou senha: `Exception("Dados do banco de dados inválidos")`.

### 3.2 `DB::setConfig(array $config)`

Chame **antes** da primeira consulta.

| Chave | Tipo | Padrão | O que faz |
|---|---|---|---|
| `host`, `name`, `username`, `password`, `charset` | string | `db_host`… | **Nomes das chaves** a ler em `Core::config("env", …)` (não são os valores) |
| `debug` | bool | `false` | Global: faz `DB::query()` **devolver a query em vez de executá-la** (não afeta `DB::table()`) |
| `null_as_empty` | bool | `true` | `true`: `null` é gravado/comparado como `""` (comportamento legado). `false`: `null` vira `NULL` de verdade |
| `expose_errors` | bool | `false` | `true`: devolve a mensagem crua do MySQL em `Response::error` e nas exceções de conexão |
| `multi_statements` | bool | `false` | `true`: permite várias instruções separadas por `;` em uma chamada |

```php
DB::setConfig([
    'host'     => 'DB_HOST',        // lerá Core::config('env', 'DB_HOST')
    'name'     => 'DB_DATABASE',
    'username' => 'DB_USER',
    'password' => 'DB_PASS',
    'null_as_empty' => false,       // recomendado, se as colunas aceitam NULL
]);
```

### 3.3 `DB::connect(array $config = [])`

Abre a conexão explicitamente (normalmente desnecessário). Aceita as mesmas chaves `host/name/username/password/charset`
(nomes de chaves de env) para sobrescrever só nessa chamada. Retorna `DB`. Se já houver conexão, não faz nada.

```php
DB::connect(['name' => 'OUTRO_BANCO']);
```

Para forçar uma nova conexão (ex.: trocar de banco): `DB::$pdo = null; DB::connect(...)`.

### 3.4 Validações e erros de conexão

| Entrada | Regra | Erro |
|---|---|---|
| host | `[A-Za-z0-9._-]+` | `InvalidArgumentException("Invalid database host")` |
| banco | `[A-Za-z0-9_$-]+` | `"Invalid database name"` |
| charset | `[A-Za-z0-9_]+` | `"Invalid database charset"` |
| Falha ao conectar | credenciais erradas, servidor fora | `Exception("Não foi possível conectar ao banco de dados")` (detalhe no `error_log`; com `expose_errors` a mensagem original) |

Essas validações impedem *DSN injection* (ex.: `db_name = "x;host=outro-servidor"`).

### 3.5 Opções de conexão aplicadas

- `ERRMODE_EXCEPTION`.
- Timeout de conexão de 10 s (`db_timeout`, só ao usar `Connection::connect()` diretamente).
- `MYSQL_ATTR_MULTI_STATEMENTS = false` (religável com `multi_statements`).
- Charset no DSN (sem `SET NAMES` extra).
- **Porta e socket Unix não são configuráveis** (usa a porta padrão do driver).

### 3.6 Acesso ao PDO

`DB::$pdo` e `$handler->pdo` são públicos, para casos especiais (ex.: `DB::$pdo->quote()`).

---

## 4. Formato de retorno

O módulo usa `Wep\Response::success($data, $meta)` e `Response::error($message)`, que devolvem um objeto:

- sucesso: `{ error: false, data: ..., ...meta }`. As chaves de `meta` (`last_id`, `affected_rows`, `stmt`...)
  ficam **no nível raiz** do objeto, por exemplo `$r->last_id`;
- erro: `{ error: true, message: "..." }`.

Nos testes, a leitura desse formato fica isolada em `succeeded()`, `rows()`, `meta()` e `errorOf()`,
em `tests/Support/Database.php`.

| Operação | `data` | chaves extras (`meta`) |
|---|---|---|
| `get()` / `select()` (modo `assoc`) | lista de linhas (arrays associativos) | vazio |
| `get()` com `fetch('obj')` | **uma** linha (objeto) ou `[]` | vazio |
| `insert` / `update` / `delete` | `[]` | `last_id` (string do `lastInsertId`), `affected_rows` (int) |
| Qualquer operação com `raw()` | idem acima | `stmt` (`PDOStatement`); em escrita também `pdo` |
| Erro | — | `Response::error($mensagem)` |

```php
$r = DB::table('users')->where('id', 1)->get('name');
if (!$r->success) { /* $r->error */ }
$name = $r->data[0]['name'] ?? null;

$r = DB::table('users')->insert(['name' => 'Ana']);
$id = $r->meta['last_id'];
```

`affected_rows` no MySQL conta apenas linhas **realmente alteradas** (atualizar para o mesmo valor retorna 0).
Em `INSERT … ON DUPLICATE KEY UPDATE`: 1 = inserido, 2 = atualizado, 0 = sem mudança.

---

## 5. DB::table() e o Handler

```php
DB::table(string $tableName, array $config = []): Handler
```

| Parâmetro | Descrição |
|---|---|
| `$tableName` | `tabela`, `schema.tabela`; em `get()` também aceita alias: `'users u'` ou `'users AS u'` |
| `$config['debug']` | `true`: monta a query e a devolve **sem abrir conexão nem executar** (veja [seção 18](#18-debug)) |

O `Handler` é um objeto **descartável**: acumula filtros/condições e, ao executar `get/insert/update/delete`,
**zera o próprio estado** (mesmo em caso de erro). Reutilizar o mesmo `Handler` é seguro, mas o recomendado é
criar um novo por consulta.

```php
$h = DB::table('users');
$h->where('id', 1)->get('id');   // usa o where
$h->get('id');                   // sem where: o estado foi zerado
```

> Exceção: no modo `debug`, o estado **não** é zerado.

Ordem de chamada: filtros, condições e joins podem vir em qualquer ordem; o método final
(`get`, `insert`, `update`, `delete`) executa.

---

## 6. Leitura: get() / select()

```php
->get(...$campos)      // campos como argumentos
->get(array $campos)   // campos em lista
->get(array $verboso)  // modo verboso (seção 14)
->select(...)          // alias de get()
```

Sem campos: `SELECT *`.

### 6.1 Formatos de campo aceitos

| Formato | Exemplo | SQL gerado |
|---|---|---|
| Coluna | `'name'` | `` `name` `` |
| Tabela.coluna | `'users.name'` | `` `users`.`name` `` |
| Todas | `'*'` / `'users.*'` | `*` / `` `users`.* `` |
| Alias | `'name AS n'` / `'name n'` | `` `name` AS `n` `` |
| DISTINCT | `'DISTINCT status'` | `` DISTINCT `status` `` |
| Agregação | `'COUNT(*) total'`, `'MAX(age)'`, `'SUM(o.total) AS t'` | ``COUNT(*) AS `total` ``, `` MAX(`age`) `` … |
| Agregação com DISTINCT | `'COUNT(DISTINCT status) AS s'` | `` COUNT(DISTINCT `status`) AS `s` `` |
| Expressão livre | `DB::raw('IFNULL(note,0) AS n')` | inserida como está |

Funções permitidas sem `DB::raw()`: `COUNT`, `SUM`, `AVG`, `MIN`, `MAX` (com coluna ou `*`). Qualquer outra
expressão (`IFNULL(...)`, `LOWER(...)`, subselect…) é **rejeitada** com a mensagem
`Invalid column or table name "…". Use DB::raw() for SQL expressions`.

```php
DB::table('users')->get();                                  // SELECT * FROM `users`
DB::table('users')->get('id', 'name');                      // SELECT `id`, `name` FROM `users`
DB::table('users')->get(['id', 'name']);                    // idem
DB::table('users')->get('COUNT(*) total', 'MAX(age)');      // SELECT COUNT(*) AS `total`, MAX(`age`) FROM `users`
DB::table('users u')->get('u.id');                          // SELECT `u`.`id` FROM `users` AS `u`
```

---

## 7. Condições: where, or, andOr

### 7.1 `where()` — formas aceitas

| Chamada | Significado | SQL |
|---|---|---|
| `where('age', 18)` | operador omitido = `=` | `` `age` = :p1 `` |
| `where('age', '>', 18)` | com operador | `` `age` > :p1 `` |
| `where('note', 'is null')` | operador sem valor | `` `note` IS NULL `` |
| `where('id', [1, 2])` | array sem operador = `IN` | `` `id` IN(:p1,:p2) `` |
| `where([['status','on'], ['age','>',20]])` | lista de condições (AND) | `` `status` = :p1 AND `age` > :p2 `` |
| `where(['status' => 'on', 'age' => 30])` | array associativo | `` `status` = :p1 AND `age` = :p2 `` |
| `where(['id' => [1, 2]])` | assoc. com array = `IN` | `` `id` IN(:p1,:p2) `` |
| `where(DB::raw('LOWER(name)'), 'ana')` | coluna como expressão | `` LOWER(name) = :p1 `` |

Chamadas sucessivas de `where()` se juntam com **AND**.

> **O operador omitido é decidido pela quantidade de argumentos, não pelo valor.** Por isso `0`, `'0'` e `''`
> são valores válidos: `where('stock', '>', 0)` e `where('stock', 0)` funcionam como esperado.

### 7.2 Operadores

Não diferenciam maiúsculas/minúsculas e ignoram espaços nas pontas (`'LIKE'`, `' >= '`, `'Not In'` valem).
Qualquer operador fora desta lista retorna erro `Invalid operator "…"`.

| Operador | Valor | SQL | Observações |
|---|---|---|---|
| `=` `!=` `<>` `>` `>=` `<` `<=` | escalar | `` `col` > :p1 `` | array → erro |
| `in` | array (escalar vira lista de 1) | `` `col` IN(:p1,:p2) `` | **array vazio → `1 = 0`** (nunca casa) |
| `not in` | idem | `` `col` NOT IN(:p1) `` | **array vazio → `1 = 1`** (sempre casa) |
| `like` | escalar | `` `col` LIKE :p1 `` | `%` e `_` **não são escapados** (veja [19.4](#194-like-e-curingas)) |
| `not like` | escalar | `` `col` NOT LIKE :p1 `` | |
| `between` | array com **exatamente 2** itens | `` `col` BETWEEN :p1 AND :p2 `` | senão erro `requires [start, end]` |
| `not between` | idem | `` `col` NOT BETWEEN :p1 AND :p2 `` | |
| `is null` | — | `` `col` IS NULL `` | |
| `is not null` | — | `` `col` IS NOT NULL `` | |
| `is not empty` | — | `` `col` <> '' `` | `NULL` também **não** passa |

> **`NULL` em comparação:** com `null_as_empty = true` (padrão), `where('note', null)` vira `` `note` = '' ``.
> Para testar nulos use sempre `'is null'` / `'is not null'`.

### 7.3 `or()`

| Chamada | SQL |
|---|---|
| `or('age', '>', 50)` / `or('age', 50)` | `` OR `age` > :p `` |
| várias `or()` simples | `OR a OR b` |
| `or([['age', 60], ['stock', 7]])` (**lista**) | `` OR (`age` = :p AND `stock` = :p) `` |

```php
DB::table('users')->where('status', 'off')->or('age', '>', 50)->get('id');
// WHERE `status` = :p1 OR `age` > :p2

DB::table('users')->where('status', 'on')->or([['age', 60], ['stock', 7]])->get('id');
// WHERE `status` = :p1 OR (`age` = :p2 AND `stock` = :p3)
```

### 7.4 `andOr()`

Agrupa as condições com **OR** dentro de parênteses e as liga ao restante com **AND**:

```php
DB::table('users')->where('status', 'on')->andOr([['age', 19], ['age', 41]])->get('id');
// WHERE `status` = :p1 AND (`age` = :p2 OR `age` = :p3)

DB::table('users')->where('a', 1)->andOr('b', '>', 2)->andOr('c', 3)->get('id');
// WHERE `a` = :p1 AND (`b` > :p2 OR `c` = :p3)
```

### 7.5 Como as condições são combinadas

1. Condições do mesmo tipo são agrupadas (`where`, `or`, `orAnd`, `andOr`).
2. O grupo `where` vem **sempre primeiro**; os demais seguem a ordem em que apareceram.
3. `or()` / `andOr()` **sem** `where()` retornam erro: `or()/andOr() require a where() clause`.

```php
DB::table('users')->or('age', 60)->where('status', 'on')->get('id');
// WHERE `status` = :p2 OR `age` = :p1     (where foi para a frente)
```

> ⚠️ **Precedência SQL.** `where(A)->where(B)->or(C)` gera `A AND B OR C`, que o SQL lê como `(A AND B) OR C`.
> Um `or()` pode, portanto, ignorar filtros de segurança (ex.: `tenant_id`). Para restringir, use `andOr()`
> ou monte a condição com um array. Isto vale especialmente para `update`/`delete`.

---

## 8. Ordenação e paginação: orderBy, limit, page

Chamar o mesmo filtro novamente **substitui** o anterior.

### 8.1 `orderBy()`

| Chamada | SQL |
|---|---|
| `orderBy('age')` | `` ORDER BY `age` ASC `` |
| `orderBy('age', 'DESC')` | `` ORDER BY `age` DESC `` |
| `orderBy(['status', 'age'])` | `` ORDER BY `status`, `age` ASC `` |
| `orderBy(['status', 'age'], 'DESC')` | `` ORDER BY `status`, `age` DESC `` |
| `orderBy('status', 'age', 'desc')` | `` ORDER BY `status`, `age` DESC `` |
| `orderBy('RAND()')` | `ORDER BY RAND() ASC` |
| `orderBy(DB::raw('LENGTH(name)'), 'DESC')` | `ORDER BY LENGTH(name) DESC` |
| `orderBy(DB::raw('status DESC, age'), 'DESC')` | `ORDER BY status DESC, age DESC` |

- A direção aceita `ASC`/`DESC` (qualquer caixa); outra coisa vira erro `Invalid order direction`.
- Com **várias colunas**, o SQL aplica a direção **somente à última** (`ORDER BY status, age DESC` ordena
  `status` ascendente e `age` descendente). Para todas descendentes, passe a lista em um `DB::raw()` **sem a direção da última coluna** (ela é sempre
  acrescentada ao final): `orderBy(DB::raw('status DESC, age'), 'DESC')` → `ORDER BY status DESC, age DESC`.
- Colunas são validadas (`id; DROP TABLE x` é rejeitado). Para expressões use `DB::raw()`; `RAND()` é aceito direto.

### 8.2 `limit()`

| Chamada | SQL |
|---|---|
| `limit(10)` | `LIMIT 10` |
| `limit(5, 10)` | `LIMIT 5, 10` (offset 5, 10 linhas) |
| `limit([5, 10])` | `LIMIT 5, 10` |

Valores precisam ser **inteiros ≥ 0**, no máximo 2; caso contrário `Invalid limit`.

### 8.3 `page()`

Pagina em conjunto com `limit()`. O **primeiro valor do `limit`** é o tamanho da página.

```php
DB::table('users')->limit(10)->page(3)->get('id');      // LIMIT 20, 10
DB::table('users')->limit(5, 10)->page(2)->get('id');   // LIMIT 5, 5   (só o 1º valor, 5, é usado)
DB::table('users')->page(3)->get('id');                 // sem limit: page é ignorado
```

- `page` é convertido para inteiro; valor `< 1` (negativo) vira página 1.
- `page(0)` / `page(null)` são **ignorados** (como se não houvesse `page`).

---

## 9. Joins: leftJoin

Somente **LEFT JOIN** é suportado. Pode ser encadeado várias vezes.

### 9.1 Formas aceitas

| Chamada | SQL do join |
|---|---|
| `leftJoin('orders', 'user_id', 'users.id')` | `` LEFT JOIN `orders` ON `orders`.`user_id` = `users`.`id` `` |
| `leftJoin('orders', 'orders.user_id', 'users.id')` | idem |
| `leftJoin('orders', ['orders.user_id','users.id'], ['orders.id','users.id'])` | `` ON (`orders`.`user_id` = `users`.`id` AND `orders`.`id` = `users`.`id`) `` |
| `leftJoin([['orders','orders.user_id','users.id'], ['items','items.id','orders.id']])` | dois `LEFT JOIN` |
| `leftJoin(['orders' => ['orders.user_id','users.id']])` | um join (forma associativa) |
| `leftJoin(['orders' => [['a','b'], ['c','d']]])` | um join com várias condições `ON` |
| `leftJoin('orders o', 'user_id', 'users.id')` | `` LEFT JOIN `orders` AS `o` ON `o`.`user_id` = `users`.`id` `` |

### 9.2 Regras

- 2º argumento (**origem**): coluna da tabela unida. Sem ponto, o módulo prefixa com o nome (ou o **alias**)
  da tabela: `'user_id'` → `` `orders`.`user_id` ``.
- 3º argumento (**destino**): **referência a coluna** (`users.id`), nunca um valor literal. Para comparar com
  valor fixo, use `where()`.
- Várias condições `ON` são ligadas por **AND**.
- Nomes de tabela e colunas são validados como identificadores (`'orders ON 1=1 --'` é rejeitado).
- Em `get()`, **qualifique os campos** (`'users.name'`, `'orders.total'`) para evitar ambiguidade. Condições
  `where` não são prefixadas automaticamente.

```php
DB::table('users')
    ->leftJoin('orders', 'user_id', 'users.id')
    ->where('orders.total', '>', 50)
    ->orderBy('users.id')
    ->get('users.name', 'orders.total');
```

---

## 10. Formato do resultado: fetch e raw

### 10.1 `fetch(string $modo)`

| Modo | Retorno em `data` |
|---|---|
| `assoc` (padrão) | lista de arrays associativos |
| `obj` | **só a primeira linha**, como objeto (`[]` se não houver) — comportamento legado |
| `num` | lista de arrays numéricos |
| `both` | arrays com índices numéricos e nomes |
| `column` | lista com valores da 1ª coluna |
| `key_pair` | `[col1 => col2, …]` |
| `named`, `class`, `unique`, `group` | equivalentes aos `PDO::FETCH_*` |

Modo desconhecido: `Invalid fetch mode` (a consulta não é executada).

```php
DB::table('users')->where('id', 1)->fetch('obj')->get('name');   // data->name
DB::table('users')->orderBy('id')->fetch('column')->get('name'); // ['Ana','Bruno',...]
```

### 10.2 `raw(bool $ativo = true)`

Devolve o `PDOStatement` em `meta['stmt']` (e `pdo` em escritas) além do resultado, para quem precisa
iterar manualmente.

```php
$r = DB::table('users')->raw()->get('id');
$stmt = $r->meta['stmt'];
```

---

## 11. Escrita: insert / create

`create()` é alias de `insert()`. **Uma linha por chamada** (não há insert em lote).

```php
DB::table('users')->insert(['name' => 'Ana', 'age' => 30]);
// INSERT INTO `users` (`name`, `age`) VALUES (:p1, :p2)
```

| Entrada | Comportamento |
|---|---|
| `['col' => valor, …]` | colunas devem ser **chaves string** válidas |
| `[]` | erro `Invalid params` |
| Valor `null` | `""` (ou `NULL` com `null_as_empty = false`) |
| Valor `bool` | `true` → `1`, `false` → `0` |
| Valor array / objeto sem `__toString` | erro `Invalid value for parameter "…"` |

Retorno: `meta['last_id']` e `meta['affected_rows']`.

### 11.1 `onDuplicateUpdate()` (upsert)

Gera `ON DUPLICATE KEY UPDATE` (exige chave única/primária na tabela).

```php
DB::table('items')->onDuplicateUpdate('qty', 'note')->insert(['sku' => 'A', 'qty' => 1, 'note' => 'x']);
// INSERT INTO `items` (`sku`, `qty`, `note`) VALUES (:p1, :p2, :p3)
//   ON DUPLICATE KEY UPDATE `qty` = :p4, `note` = :p5

DB::table('items')->onDuplicateUpdate(['qty', 'note'])->insert([...]);   // forma em array
```

Regras:

- Só atualiza colunas que **estão** nos campos do insert.
- Valor `null` ou `""` **não sobrescreve** o registro existente (a coluna é omitida do UPDATE).
- `0`, `"0"` e `false` **são** valores válidos e atualizam.
- Se nenhuma coluna sobrar, a cláusula é omitida (um duplicado causa erro de chave única → `Registro duplicado`).
- Os nomes passados a `onDuplicateUpdate` são validados como identificadores; o que não existe nos campos é ignorado.

### 11.2 Insert verboso

```php
DB::table('items')->insert([
    'fields'            => ['sku' => 'A', 'qty' => 2],
    'onDuplicateUpdate' => ['qty'],
]);
```

> No insert, a presença de uma chave `fields` preenchida define o formato verboso. Evite uma coluna chamada
> `fields`, pois ela seria confundida com esse formato.

---

## 12. Escrita: update

**Exige `where()`**. Sem condição: `Insecure update, no where clause found` (nada é executado).

| Chamada | SQL |
|---|---|
| `where('id',1)->update('status', 'x')` | `` UPDATE `users` SET `status` = :p1 WHERE `id` = :p2 `` |
| `where('id',1)->update(['status'=>'x','age'=>2])` | `` SET `status` = :p1, `age` = :p2 WHERE … `` |
| `update(['fields'=>['status'=>'x'], 'where'=>['id',1]])` | verboso |
| `update(['fields'=>[…], 'where'=>[['id',1],['age','>',1]]])` | verboso com lista (AND) |

- Sem campos (`update([])`): `Invalid params`.
- Nomes de coluna são validados e escapados.
- Retorno: `meta['affected_rows']`.
- O formato verboso é detectado quando o array tem a chave `where` preenchida. Aceita também `or` e `andOr`
  (`limit`/`orderBy` são ignorados em update).

> ⚠️ **Valores são sempre dados.** `update('views', DB::raw('views + 1'))` **não** soma: `DB::raw` como *valor*
> é gravado como o texto `"views + 1"`. Para incrementos use SQL próprio:
> `DB::query('UPDATE posts SET views = views + :n WHERE id = :id', ['n' => 1, 'id' => 5]);`

---

## 13. Escrita: delete

**Exige condição.** Sem ela: `Insecure delete, no where clause found`.

| Chamada | SQL |
|---|---|
| `where('id',1)->delete()` | `` DELETE FROM `users` WHERE `id` = :p1 `` |
| `delete('id', 1)` | idem (chave, valor) |
| `delete(['status' => 'off'])` | `` WHERE `status` = :p1 `` (assoc.) |
| `delete([['status','off'], ['age','>',50]])` | lista de condições |
| `delete(['where' => ['id', 1]])` | verboso, par simples |
| `delete(['where' => [['id',1], ['age','>',3]]])` | verboso, lista |
| `delete(['where' => ['status' => 'off']])` | verboso, assoc. |

`or()` sem `where()` é recusado (não há como atingir a tabela inteira por engano). Retorno:
`meta['affected_rows']`.

---

## 14. Modo verboso

Em vez de encadear, passe **um array** ao método final. Os dois estilos podem ser misturados na mesma chamada.

### 14.1 Chaves aceitas

| Chave | Em | Valor |
|---|---|---|
| `fields` | `get`, `insert`, `update` | lista de campos (get) / `col => valor` (insert, update) |
| `where` | `get`, `update`, `delete` | `['col', valor]`, `['col','op',valor]`, lista de condições ou assoc. |
| `or` | `get`, `update` | idem `or()` |
| `andOr` | `get`, `update` | idem `andOr()` |
| `orderBy` | `get` | `'col'`, `['col', 'DESC']`, `['a','b','DESC']` |
| `limit` | `get` | `10`, `[5, 10]` |
| `page` | `get` | número |
| `leftJoin` | `get` | qualquer forma da [seção 9](#9-joins-leftjoin) |
| `fetch` | `get` | modo de fetch |
| `raw` | `get` | bool |
| `debug` | `get` | bool |
| `onDuplicateUpdate` | `insert` | `['col', …]` |

```php
DB::table('users')->get([
    'fields'   => ['users.name', 'orders.total'],
    'leftJoin' => ['orders', 'user_id', 'users.id'],
    'where'    => ['orders.total', '>', 50],
    'orderBy'  => ['users.id', 'ASC'],
    'limit'    => 10,
    'page'     => 2,
]);
```

O `get()` entra em modo verboso quando o array recebido é **associativo**. `where` aceita todas as formas do
[`where()` encadeado](#71-where--formas-aceitas); um par simples (`['id', 1]`) é tratado como argumentos, e uma lista
de listas ou um array associativo como lote.

---

## 15. SQL próprio: DB::query()

```php
DB::query(string $sql, array $fields = [], array $configs = []): Response
```

Use para o que o builder não cobre (`GROUP BY`, `HAVING`, subqueries, `UNION`, incrementos, DDL…).

### 15.1 Parâmetros

| Parâmetro | Descrição |
|---|---|
| `$sql` | query completa. **Não é validada**: valores sempre por placeholder |
| `$fields` | `['nome' => valor]` (com ou sem `:`), ou lista `[v1, v2]` para `?` |
| `$configs['fetch']` | modo de fetch ([seção 10](#101-fetchstring-modo)) |
| `$configs['raw']` | devolve o `PDOStatement` em `meta` |

```php
DB::query('SELECT id FROM users WHERE status = :s AND age > :a', ['s' => 'on', 'a' => 20]);
DB::query('SELECT id FROM users WHERE name = ?', ['Ana']);
DB::query('SELECT id, name FROM users', [], ['fetch' => 'key_pair']);
DB::query('SELECT status, COUNT(*) AS n FROM users GROUP BY status');
DB::query('UPDATE posts SET views = views + :n WHERE id = :id', ['n' => 1, 'id' => 5]);
```

### 15.2 Comportamento

- Se a query tem placeholder nomeado e `$fields` está vazio: `Missing placeholder values`. Texto como
  `'10:30'` ou `x::int` **não** é confundido com placeholder.
- O mesmo placeholder pode se repetir (`:a ... :a`).
- Query vazia: `Invalid query string`.
- Query que **retorna linhas** (`SELECT`, `SHOW`, `WITH`, `CALL`…): `data` com as linhas. Que **não retorna**
  (`INSERT`, `UPDATE`…): `data = []` e `meta` com `last_id` e `affected_rows`. Isso é decidido pelo próprio
  statement, então espaços/quebras de linha no início não atrapalham.
- Várias instruções separadas por `;` são **bloqueadas** por padrão (`multi_statements`).
- Com `DB::setConfig(['debug' => true])`, devolve sem executar: `{ query_string, query_raw, fields }`.

---

## 16. Transações

```php
DB::beginTransaction();   // retorna DB
DB::commit();             // retorna bool (false se não havia transação)
DB::rollback();           // retorna DB
```

Como os erros chegam como `Response` (não como exceção), **verifique cada passo** e faça o rollback você mesmo:

```php
DB::beginTransaction();

$a = DB::table('accounts')->where('id', 1)->update('balance', 50);
$b = DB::table('accounts')->where('id', 2)->update('balance', 150);

if (!$a->success || !$b->success) {
    DB::rollback();
    return false;
}

DB::commit();
```

Observações:

- **Aninhamento não é suportado**: `beginTransaction()` dentro de outra transação é ignorado, e o `commit()`
  interno confirma a transação externa.
- Instruções DDL (`CREATE`, `ALTER`…) fazem *commit* implícito no MySQL.
- `commit()`/`rollback()` sem transação ativa são inofensivos.

---

## 17. DB::raw() e regras de identificadores

### 17.1 Regras para nomes (tabelas e colunas)

Nomes **não podem** ser enviados por bind, então são validados e escapados com crases.

| Aceito | Exemplo |
|---|---|
| nome | `name`, `created_at` (`[A-Za-z_][A-Za-z0-9_$]*`) |
| qualificado | `users.id`, `db.users.id` (até 3 partes) |
| curinga | `*`, `users.*` |

Qualquer outro formato (espaços, aspas, `;`, `--`, parênteses, nome começando por número…) é rejeitado com
`Invalid column or table name "…"`. Isso vale para: nome da tabela, campos do `get`, colunas de `where/or/andOr`,
`orderBy`, colunas do `insert`/`update`, `onDuplicateUpdate` e condições `ON` do join.

### 17.2 `DB::raw(string $sql)`

Insere o fragmento **sem validação nem escape**. Use só com texto escrito no código, **nunca com input de usuário**.

| Onde funciona | Exemplo |
|---|---|
| Campos do `get` | `get(DB::raw('IFNULL(note, 0) AS n'))` |
| Coluna do `where` | `where(DB::raw('LOWER(email)'), 'ana@x.com')` |
| `orderBy` | `orderBy(DB::raw('FIELD(id, 3, 1)'))` |

**Não** funciona como *valor* (`insert`, `update`, `where` com valor): o objeto é tratado como texto.

---

## 18. Debug

Mostra a query montada **sem executá-la**.

```php
$q = DB::table('users')->debug()->where('age', '>', 3)->get('id');
// ou: DB::table('users', ['debug' => true])->... (não abre conexão)
```

| Propriedade | Conteúdo |
|---|---|
| `string` | SQL com placeholders (`` SELECT `id` FROM `users` WHERE `age` > :p1 ``) |
| `placeholders` | `['p1' => 3]` |
| `raw` | SQL com valores no lugar (**somente para exibir/logar — nunca execute**) |
| `execute` | `['bind' => …, 'query' => …, 'fields' => …, 'config' => …]` |
| `filters`, `conditions`, `joins`, `fields_list`, `conditions_query` | só em `get()` |
| `duplicate_update_fields` | só em `insert()` |
| `condition_query`, `conditions` | só em `delete()` |

Com `DB::table('t', ['debug' => true])` a conexão **nunca** é aberta. Com `->debug()` ela é aberta (a conexão
já existe), mas nada é executado. No modo debug o estado do `Handler` não é zerado.

Para `DB::query()`: `DB::setConfig(['debug' => true])` (global).

---

## 19. Segurança

### 19.1 O que o módulo garante

| Vetor | Proteção |
|---|---|
| Valores (`where`, `insert`, `update`, `DB::query`) | sempre *prepared statements* (bind como string) |
| Nomes de colunas/tabelas | validação + crases (seção 17) |
| Operadores | lista fixa (seção 7.2); inválido → erro (não derruba o processo) |
| `limit` / `page` | convertidos para inteiros |
| Direção do `orderBy` | só `ASC`/`DESC` |
| `update`/`delete` sem `where` | recusados — inclusive só com `or()`/`andOr()` |
| Modo de `fetch` | lista fixa |
| Queries empilhadas (`;`) | desligadas por padrão |
| DSN | host/banco/charset validados |
| Mensagens de erro | genéricas para quem chama; detalhe no `error_log` |
| Credenciais | não ficam guardadas no objeto `Connection` |

### 19.2 O que continua sendo responsabilidade sua

- **`DB::raw()`** e o SQL de **`DB::query()`**: não são validados.
- **Autorização**: `where('id', $id)` não verifica se o usuário pode acessar aquele `id`. Lembre da
  [precedência do `or()`](#75-como-as-condições-são-combinadas).
- **Privilégios do usuário do banco**: use um usuário com o mínimo necessário.
- `DB::escape()` está **obsoleto** (escape manual é frágil); use parâmetros.

### 19.3 Tipos no bind

Todos os valores vão como **string** (como no original). É intencional: vincular inteiro contra uma coluna
`VARCHAR` faz o MySQL converter a coluna para número (`code = 0` casaria com qualquer texto e perderia o índice).

### 19.4 LIKE e curingas

O valor do `like` é bindado, mas `%` e `_` digitados pelo usuário funcionam como curingas. Para busca literal:

```php
$term = addcslashes($input, '%_\\');
DB::table('users')->where('name', 'like', "%{$term}%")->get('id');
```

---

## 20. Mensagens de erro

Erros de montagem e execução chegam como `Response::error($mensagem)`.

### 20.1 Validação (antes de tocar no banco)

| Mensagem | Causa |
|---|---|
| `Invalid column or table name "…". Use DB::raw() for SQL expressions` | identificador/expressão fora do padrão |
| `Invalid column or table name` | valor não-string ou vazio onde se esperava nome |
| `Invalid operator "…"` | operador fora da lista |
| `Invalid value for operator "…"` | array onde só cabe escalar |
| `Operator "between" requires [start, end]` | `between` sem exatamente 2 valores |
| `Invalid limit` | `limit` não inteiro, negativo ou com mais de 2 valores |
| `Invalid order direction` | direção diferente de ASC/DESC |
| `Invalid condition` / `Invalid condition type` | condição malformada |
| `or()/andOr() require a where() clause` | `or`/`andOr` sem `where` |
| `Invalid params` | `insert([])` ou `update` sem campos |
| `Insecure update, no where clause found` | `update` sem condição |
| `Insecure delete, no where clause found` | `delete` sem condição |

### 20.2 Execução

| Mensagem | Causa |
|---|---|
| `Invalid fetch mode` | modo de fetch desconhecido |
| `Invalid value for parameter "…"` | valor array/objeto no bind |
| `Erro de conexão com banco de dados` | sem PDO disponível |
| `Registro duplicado` | erro 1062 (chave única) |
| `Violação de integridade referencial` | erros 1451/1452 (chave estrangeira) |
| `Erro ao executar a consulta no banco de dados` | qualquer outro erro do banco |
| `Invalid query string` | `DB::query('')` |
| `Missing placeholder values` | `DB::query` com `:nome` e sem `$fields` |

Com `expose_errors = true`, os erros do banco devolvem a mensagem original do MySQL.

### 20.3 Exceções (`DB::connect`, `Connection`)

| Exceção | Mensagem |
|---|---|
| `Exception` | `Dados do banco de dados inválidos` |
| `InvalidArgumentException` | `Invalid database host` / `name` / `charset` |
| `Exception` | `Não foi possível conectar ao banco de dados` |

---

## 21. Limitações

| Não há | Alternativa |
|---|---|
| `GROUP BY`, `HAVING`, `UNION`, subqueries | `DB::query()` |
| `INNER`/`RIGHT`/`CROSS JOIN` | `DB::query()` |
| Insert em lote | laço de `insert()` dentro de transação, ou `DB::query()` |
| Expressões como valor (`views = views + 1`) | `DB::query()` |
| Funções em campos/where sem `DB::raw()` | `DB::raw()` |
| Alias de tabela em `insert/update/delete` | só em `get()` |
| Porta/socket Unix configuráveis | — |
| Reconexão automática (`MySQL server has gone away`) | reabra: `DB::$pdo = null; DB::connect();` |
| Transações aninhadas / savepoints | — |
| `fetch('obj')` devolve só a 1ª linha | use `assoc` e converta |

---

## 22. Receitas

### 22.1 Paginação com total

```php
function paginate(string $table, array $filters, int $page, int $perPage): array
{
    $count = DB::table($table)->where($filters)->get('COUNT(*) AS total');
    $items = DB::table($table)->where($filters)->orderBy('id')->limit($perPage)->page($page)->get();

    $total = $count->data[0]['total'];

    return ['total' => $total, 'pages' => (int) ceil($total / $perPage), 'items' => $items->data];
}
```

> Se `$filters` for vazio, `where([])` não adiciona condição — a consulta lista tudo.

### 22.2 Filtros dinâmicos

```php
$q = DB::table('users');

if (!empty($in['status']))  $q->where('status', $in['status']);
if (!empty($in['min_age'])) $q->where('age', '>=', (int) $in['min_age']);
if (!empty($in['q']))       $q->where('name', 'like', '%' . addcslashes($in['q'], '%_\\') . '%');

$r = $q->orderBy('id')->get('id', 'name');
```

### 22.3 Primeira linha / existência

```php
$user = DB::table('users')->where('email', $email)->fetch('obj')->limit(1)->get('id', 'name');
// $user->data->name   (ou [] se não existir)

$exists = count(DB::table('users')->where('id', 3)->limit(1)->get('id')->data) === 1;
```

### 22.4 Upsert

```php
DB::table('items')->onDuplicateUpdate('qty')->insert(['sku' => 'A', 'qty' => 5]);
```

### 22.5 Transação com verificação

Veja a [seção 16](#16-transações).

### 22.6 Agregações e `GROUP BY`

```php
DB::query('SELECT status, COUNT(*) AS n FROM users GROUP BY status ORDER BY status');
```

### 22.7 Incremento atômico

```php
DB::query('UPDATE users SET stock = stock + :n WHERE id = :id', ['n' => 10, 'id' => 1]);
```

### 22.8 Inspecionar uma query antes de rodar

```php
$q = DB::table('users')->debug()->where('status', 'on')->orderBy('age', 'DESC')->limit(10)->get('id');
echo $q->raw;
```

---

## 23. Referência rápida

### 23.1 `Wep\DB` (estático)

| Método | Descrição |
|---|---|
| `table($tabela, $config = [])` | cria um `Handler` |
| `query($sql, $fields = [], $configs = [])` | SQL próprio |
| `raw($sql)` | fragmento SQL confiável |
| `beginTransaction()` / `commit()` / `rollback()` | transações |
| `setConfig($config)` | configurações globais |
| `connect($config = [])` | abre a conexão |
| `escape($valor)` | **obsoleto** |
| `DB::$pdo` | PDO atual (público) |

### 23.2 `Handler` (retorno de `DB::table()`)

| Método | Tipo | Descrição |
|---|---|---|
| `get(...)` / `select(...)` | final | lê linhas |
| `insert($fields)` / `create($fields)` | final | insere uma linha |
| `update($chave, $valor = null)` | final | atualiza (exige condição) |
| `delete($chave = null, $valor = null)` | final | remove (exige condição) |
| `where(...)` | encadeável | condição AND |
| `or(...)` | encadeável | condição OR / `OR (a AND b)` |
| `andOr(...)` | encadeável | `AND (a OR b)` |
| `orderBy(...)` | encadeável | ordenação |
| `limit(...)` | encadeável | limite |
| `page($n)` | encadeável | página (usa o `limit`) |
| `leftJoin(...)` | encadeável | LEFT JOIN |
| `onDuplicateUpdate(...)` | encadeável | colunas do upsert |
| `fetch($modo)` | encadeável | formato do resultado |
| `raw($bool = true)` | encadeável | devolve o `PDOStatement` |
| `debug($bool = true)` | encadeável | devolve a query sem executar |
| `$pdo` | propriedade | PDO usado |

### 23.3 Operadores de `where`

`=` `!=` `<>` `>` `>=` `<` `<=` · `in` `not in` · `like` `not like` · `between` `not between` ·
`is null` `is not null` `is not empty`

### 23.4 Modos de `fetch`

`assoc` · `obj` · `num` · `both` · `column` · `key_pair` · `named` · `class` · `unique` · `group`

### 23.5 `setConfig`

`host` `name` `username` `password` `charset` · `debug` · `null_as_empty` · `expose_errors` · `multi_statements`

---

## 24. Estrutura de arquivos e testes

```
DB.php                         Fachada estática (Wep\DB)
DB/
  Connection.php               Abre o PDO (validação de DSN, opções)
  Handler.php                  API encadeável; monta e executa as consultas
  Query/
    Executor.php               Prepared statements, fetch, tratamento de erros
    Helpers.php                Trait: reset de estado e modo verboso
    Formatter.php              Debug (valores interpolados) e detecção de placeholders
    Raw.php                    Fragmento SQL confiável (DB::raw)
    Builders/
      Identifier.php           Validação/escape de nomes
      Placeholders.php         Placeholders sequenciais (:p1, :p2…)
      Condition.php            Agrupa e monta WHERE/OR/AND
      Operators.php            Operadores permitidos
    Methods/
      Select.php Insert.php Update.php Delete.php   Montam cada SQL
      Conditional.php          where / or / andOr
      Filters.php              orderBy / limit / page
      Joins.php                leftJoin
      Others.php Aliases.php   fetch / raw / onDuplicateUpdate · get→select, insert→create
```

### Testes (Pest)

```bash
composer install
./vendor/bin/pest                       # unit + feature (SQLite em memória)

DB_TEST_HOST=127.0.0.1 DB_TEST_NAME=testdb DB_TEST_USER=user DB_TEST_PASS=pass ./vendor/bin/pest
```

Sem as variáveis `DB_TEST_*`, os testes de `tests/Integration` (MySQL real) são pulados. Use um banco
descartável: as tabelas de teste são recriadas a cada execução.

Para as mudanças em relação à versão anterior do módulo (comportamentos alterados, bugs corrigidos), veja `CHANGELOG.md`.
