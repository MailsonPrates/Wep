<?php

use Core\DB;

use function PHPUnit\Framework\isEmpty;

$user = DB::table("user", [
    "debug" => true
]);

$select = [
    [
        "title" => "SELECT: todas as colunas",
        "expect" => $user->get(),
        "result" => "SELECT * FROM user"
    ],
    [
        "title" => "SELECT: uma coluna",
        "expect" => $user->get("nome"),
        "result" => "SELECT nome FROM user"
    ],
    [
        "title" => "SELECT: multiplas colunas por array",
        "expect" => $user->get(["nome", "idade"]),
        "result" => "SELECT nome, idade FROM user"
    ],
    [
        "title" => "SELECT: multiplas colunas por argumentos",
        "expect" => $user->get("nome", "idade"),
        "result" => "SELECT nome, idade FROM user"
    ]
];

$select_where = [
    [
        "title" => "SELECT WHERE: com condição igual",
        "expect" => $user->where("id", "=", 123)->get(),
        "result" => "SELECT * FROM user WHERE id = 123"
    ],
    [
        "title" => "SELECT WHERE: com condição omitida",
        "expect" => $user->where("id", 123)->get(),
        "result" => "SELECT * FROM user WHERE id = 123"
    ],
    [
        "title" => "SELECT WHERE: com multiplas condições",
        "expect" => $user->where([
            ["id", "=", 123],
            ["idade", ">", 18]
        ])->get(),
        "result" => "SELECT * FROM user WHERE id = 123 AND idade > 18"
    ],
    [
        "title" => "SELECT WHERE: com OR",
        "expect" => $user->where("id", "=", 123)->or("name", "=", "João")->get(),
        "result" => 'SELECT * FROM user WHERE id = 123 OR name = João'
    ],
    [
        "title" => "SELECT WHERE: com opções de OR",
        "expect" => $user->where("id", 123)->or("name", "=", "João")->or("name", "=", "Pedro")->get(),
        "result" => 'SELECT * FROM user WHERE id = 123 OR name = João OR name = Pedro'
    ],
    [
        "title" => "SELECT WHERE: com multiplas condições de OR",
        "expect" => $user->where("id", 123)->or([
            ["name", "=", "João"],
            ["name", "=", "Pedro"]
        ])->get(),
        "result" => 'SELECT * FROM user WHERE id = 123 OR (name = João AND name = Pedro)'
    ],
        [
            "title" => "SELECT WHERE: com mix de AND e OR's",
            "expect" => $user
            ->where("id", "=", 123)
            ->andOr([
                ["name", "=", "João"],
                ["name", "=", "Pedro"]
            ])
            ->or("idade", "<", 43)
            ->or("idade", ">", 30)
            ->get(),
            "result" => 'SELECT * FROM user WHERE id = 123 AND (name = João OR name = Pedro) OR idade < 43 OR idade > 30'
        ],
];

$selec_where_operadores_logico = [
    [
        "title" => "SELECT WHERE: com operador lógico:  !=",
        "expect" => $user->where("name", "!=", "Jonh")->get(),
        "result" => "SELECT * FROM user WHERE name != Jonh"
    ],
    [
        "title" => "SELECT WHERE: com operador lógico:  <=",
        "expect" => $user->where("idade", "<=", 20)->get(),
        "result" => "SELECT * FROM user WHERE idade <= 20"
    ],
    [
        "title" => "SELECT WHERE: com operador lógico:  >=",
        "expect" => $user->where("idade", ">=", 20)->get(),
        "result" => "SELECT * FROM user WHERE idade >= 20"
    ],
];

$select_where_operadores_valor = [
    [
        "title" => "SELECT WHERE: com operador de valor:  IN",
        "expect" => $user->where("id", "in", [12, 15])->get(),
        "result" => "SELECT * FROM user WHERE id IN(12,15)"
    ],
    [
        "title" => "SELECT WHERE: com operador de valor:  NOT IN",
        "expect" => $user->where("id", "not in", [12, 15])->get(),
        "result" => "SELECT * FROM user WHERE id NOT IN(12,15)"
    ],
    [
        "title" => "SELECT WHERE: com operador de valor:  LIKE",
        "expect" => $user->where("name", "like", "%Mateus%")->get(),
        "result" => "SELECT * FROM user WHERE name LIKE %Mateus%"
    ],
    [
        "title" => "SELECT WHERE: com operador de valor:  NOT LIKE",
        "expect" => $user->where("name", "not like", "%Mateus%")->get(),
        "result" => "SELECT * FROM user WHERE name NOT LIKE %Mateus%"
    ],
    [
        "title" => "SELECT WHERE: com operador de valor:  BETWEEN",
        "expect" => $user->where("id", "between", [12, 15])->get(),
        "result" => "SELECT * FROM user WHERE id BETWEEN 12 AND 15"
    ],
    [
        "title" => "SELECT WHERE: com operador de valor: NOT BETWEEN",
        "expect" => $user->where("id", "not between", [12, 15])->get(),
        "result" => "SELECT * FROM user WHERE id NOT BETWEEN 12 AND 15"
    ]
];

$select_where_operadores_type = [
    [
        "title" => "SELECT WHERE: com operador de Tipo:  IS NOT NULL",
        "expect" => $user->where("id", "is not null")->get(),
        "result" => "SELECT * FROM user WHERE id IS NOT NULL"
    ],
    [
        "title" => "SELECT WHERE: com operador de Tipo:  IS NULL",
        "expect" => $user->where("id", "is null")->get(),
        "result" => "SELECT * FROM user WHERE id IS NULL"
    ],
    [
        "title" => "SELECT WHERE: com operador de Tipo:  IS NOT EMPTY",
        "expect" => $user->where("id", "is not empty")->get(),
        "result" => "SELECT * FROM user WHERE id <>''"
    ],
]; 

$select_filters = [
    [
        "title" => "SELECT com filtros: ORDER BY - order default",
        "expect" => $user->where("id", 123)->orderBy("nome")->get(),
        "result" => "SELECT * FROM user WHERE id = 123 ORDER BY nome ASC"
    ],
    [
        "title" => "SELECT com filtros: ORDER BY - outra order",
        "expect" => $user->where("id", 123)->orderBy("nome", "desc")->get(),
        "result" => "SELECT * FROM user WHERE id = 123 ORDER BY nome DESC"
    ],
    [
        "title" => "SELECT com filtros: ORDER BY - múltiplas colunas direto",
        "expect" => $user->where("id", 123)->orderBy("nome", "sobrenome", "desc")->get(),
        "result" => "SELECT * FROM user WHERE id = 123 ORDER BY nome, sobrenome DESC"
    ],
    [
        "title" => "SELECT com filtros: ORDER BY - múltiplas colunas por array",
        "expect" => $user->where("id", 123)->orderBy(["nome", "sobrenome"])->get(),
        "result" => "SELECT * FROM user WHERE id = 123 ORDER BY nome, sobrenome ASC"
    ],
    [
        "title" => "SELECT com filtros: ORDER BY - múltiplas colunas por array e outra order",
        "expect" => $user->where("id", 123)->orderBy(["nome", "sobrenome"], "DESC")->get(),
        "result" => "SELECT * FROM user WHERE id = 123 ORDER BY nome, sobrenome DESC"
    ],
    [
        "title" => "SELECT com filtros: LIMIT - 1 valor",
        "expect" => $user->where("id", 123)->limit(1)->get(),
        "result" => "SELECT * FROM user WHERE id = 123 LIMIT 1"
    ],
    [
        "title" => "SELECT com filtros: LIMIT - range",
        "expect" => $user->where("id", 123)->limit(0, 20)->get(),
        "result" => "SELECT * FROM user WHERE id = 123 LIMIT 0, 20"
    ],
    [
        "title" => "SELECT com filtros: LIMIT - range com array",
        "expect" => $user->where("id", 123)->limit([0, 20])->get(),
        "result" => "SELECT * FROM user WHERE id = 123 LIMIT 0, 20"
    ],
    [
        "title" => "SELECT com filtros: PAGE 1",
        "expect" => $user->where("id", 123)->limit(20)->page(1)->get(),
        "result" => "SELECT * FROM user WHERE id = 123 LIMIT 0, 20"
    ],
    [
        "title" => "SELECT com filtros: PAGE 2",
        "expect" => $user->where("id", 123)->limit(20)->page(2)->get(),
        "result" => "SELECT * FROM user WHERE id = 123 LIMIT 20, 20"
    ],
    [
        "title" => "SELECT com filtros: PAGE 3",
        "expect" => $user->where("id", 123)->limit(20)->page(3)->get(),
        "result" => "SELECT * FROM user WHERE id = 123 LIMIT 40, 20"
    ],
];

$select_left_join = [
    [
        "title" => "SELECT com LEFT JOIN: simples",
        "expect" => $user->leftJoin("orders", "order.user_id", "user.id")->get(),
        "result" => "SELECT * FROM user LEFT JOIN orders ON order.user_id = user.id"
    ],
    [
        "title" => "SELECT com LEFT JOIN: múltipla condições ON",
        "expect" => $user->leftJoin("orders", ["order.user_id", "user.id"],["product.id", "order.product_id"])->get(),
        "result" => "SELECT * FROM user LEFT JOIN orders ON (order.user_id = user.id AND product.id = order.product_id)"
    ],
    
];

$inserts = [
    [
        "title" => "INSERT",
        "expect" => $user->insert(["name" => "Mailson", "sobrenome" => "Lima"]),
        "result" => "INSERT INTO user (name, sobrenome) VALUES (Mailson, Lima)"
    ],
    [
        "title" => "INSERT: aliase CREATE",
        "expect" => $user->create(["name" => "Mailson", "sobrenome" => "Lima"]),
        "result" => "INSERT INTO user (name, sobrenome) VALUES (Mailson, Lima)"
    ],
    [
        "title" => "INSERT: aliase CREATE com ON DUPLICATE UPDATE",
        "expect" => $user->onDuplicateUpdate(["sobrenome"])->create(["name" => "Mailson", "sobrenome" => "Lima"]),
        "result" => "INSERT INTO user (name, sobrenome) VALUES (Mailson, Lima) ON DUPLICATE KEY UPDATE sobrenome = Lima"
    ],
    [
        "title" => "INSERT: aliase CREATE com ON DUPLICATE UPDATE parametros direto",
        "expect" => $user->onDuplicateUpdate("sobrenome")->create(["name" => "Mailson", "sobrenome" => "Lima"]),
        "result" => "INSERT INTO user (name, sobrenome) VALUES (Mailson, Lima) ON DUPLICATE KEY UPDATE sobrenome = Lima"
    ],
];

$updates = [
    [
        "title" => "UPDATE: sem WHERE",
        "expect" => (object)[
            "raw" => $user->update("name", "Pedro")->message
        ],
        "result" => "Insecure update, no where clause found"
    ],
    [
        "title" => "UPDATE: um campo",
        "expect" => $user->where("id", 123)->update("name", "Pedro"),
        "result" => "UPDATE user SET name = Pedro WHERE id = 123"
    ],
    [
        "title" => "UPDATE: multiples campos",
        "expect" => $user->where("id", 123)->update(["name" => "Pedro", "idade" => 21]),
        "result" => "UPDATE user SET name = Pedro, idade = 21 WHERE id = 123"
    ],
];

$deletes = [
    [
        "title" => "DELETE: sem WHERE",
        "expect" => (object)[
            "raw" => $user->delete()->message
        ],
        "result" => "Insecure delete, no where clause found"
    ],
    [
        "title" => "DELETE: uma condição com where",
        "expect" => $user->where("id", 123)->delete(),
        "result" => "DELETE FROM user WHERE id = 123"
    ],
    [
        "title" => "DELETE: uma condição sem where",
        "expect" => $user->delete("id", 123),
        "result" => "DELETE FROM user WHERE id = 123"
    ],
    [
        "title" => "DELETE: multiplass condições com where",
        "expect" => $user->where([
            ["id", "=", 123],
            ["nome", "=", "Pedro"]
        ])->delete(),
        "result" => "DELETE FROM user WHERE id = 123 AND nome = Pedro"
    ],
    [
        "title" => "DELETE: multiplass condições sem were",
        "expect" => $user->delete([
            ["id", "=", 123],
            ["nome", "=", "Pedro"]
        ]),
        "result" => "DELETE FROM user WHERE id = 123 AND nome = Pedro"
    ],

];

DB::setConfig([
    "debug" => true
]);

$db_methods = [
    [
        "title" => "DB::query: SELECT",
        "expect" => (object)[
            "raw" => DB::query("Select * FROM user")->query_raw
        ],
        "result" => "Select * FROM user"
    ],
    [
        "title" => "DB::query: SELECT com condições WHERE com erro",
        "expect" => (object)[
            "raw" => DB::query("Select * FROM user WHERE id = :id")->message
        ],
        "result" => "Missing placeholder values"
    ],
    [
        "title" => "DB::query: SELECT com condições WHERE",
        "expect" => (object)[
            "raw" => DB::query("Select * FROM user WHERE id = :id", [
                "id" => 123
            ])->query_raw
        ],
        "result" => "Select * FROM user WHERE id = 123"
    ],
    [
        "title" => "DB::query: UPDATE com condições WHERE",
        "expect" => (object)[
            "raw" => DB::query("UPDATE user SET name = Pedro WHERE id = :id", [
                "id" => 123
            ])->query_raw
        ],
        "result" => "UPDATE user SET name = Pedro WHERE id = 123"
    ],
    [
        "title" => "DB::query: CREATE",
        "expect" => (object)[
            "raw" => DB::query("INSERT INTO user (name, sobrenome) VALUES (:name, :sobrenome)", [
                "name" => "Pedro",
                "sobrenome" => "Lima"
            ])->query_raw
        ],
        "result" => "INSERT INTO user (name, sobrenome) VALUES (Pedro, Lima)"
    ]
];


$cases = array_merge(
    $select,
    $select_where,
    $selec_where_operadores_logico,
    $select_where_operadores_valor,
    $select_where_operadores_type,
    $select_filters,
    $select_left_join,
    $inserts,
    $updates,
    $deletes,
    $db_methods
);

foreach( $cases as $item ){

    $item = (object)$item;
    $expect = $item->expect->raw;
    $result = $item->result;

    test($item->title, function() use ($expect, $result){
        expect($expect)->toBe($result);
    });

}