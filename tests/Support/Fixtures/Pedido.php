<?php

/*
 * Módulo de teste "Pedido" para o build (php app update), com hooks.
 * A config fica em <testApp>/src/Modules/Pedido/config/module.php (builderApp()).
 */

namespace App\Modules\Pedido\Hooks {

    /** each_route #1: marca a rota */
    class MarcaA
    {
        public function handle($route, $data)
        {
            $route['custom']['a'] = true;
            return $route;
        }
    }

    /** each_route #2: precisa rodar também (antes só o primeiro rodava) e enxerga a marca do #1 */
    class MarcaB
    {
        public function handle($route, $data)
        {
            $route['custom']['b'] = !empty($route['custom']['a']);
            return $route;
        }
    }

    /** each_route que não devolve nada: mantém a rota */
    class NaoDevolve
    {
        public function handle($route, $data)
        {
        }
    }

    /** after_build que falha (ex.: banco indisponível) */
    class Falha
    {
        public function handle()
        {
            throw new \RuntimeException('banco indisponível');
        }
    }
}

namespace App\Modules\Pedido {

    class PedidoApi
    {
        use \Wep\Controller;
    }
}
