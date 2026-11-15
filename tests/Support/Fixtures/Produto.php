<?php

/*
 * Módulo de teste "Produto" (App\Modules\Produto), com Model e Controller reais.
 * A config fica em <testApp>/src/Modules/Produto/config/module.php (crudApp()).
 */

namespace App\Modules\Produto {

    class Produto
    {
        use \Wep\Model;
    }

    class ProdutoApi
    {
        use \Wep\Controller;

        /** Expõe o handler do CRUD automático para os testes. */
        public function callDefault(string $method, $request)
        {
            return $this->handleDefaultMethods($method, $request, new \Wep\Response(), Produto::class);
        }
    }
}
