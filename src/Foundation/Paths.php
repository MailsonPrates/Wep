<?php

namespace Wep\Foundation;

/**
 * Caminhos da aplicação, todos derivados do diretório raiz.
 *
 * Cada método aceita segmentos opcionais, que são concatenados com "/":
 *
 * @example
 * $paths->root();                    // /var/www/app
 * $paths->config('.env');            // /var/www/app/config/.env
 * $paths->storage('log', 'app.log'); // /var/www/app/storage/log/app.log
 */
final class Paths
{
    private string $root;

    public function __construct(string $root)
    {
        if ($root === '') {
            throw new \InvalidArgumentException('O diretório raiz da aplicação não pode ser vazio');
        }

        $this->root = rtrim($root, '/\\');
    }

    public function root(string ...$segments): string
    {
        return self::join($this->root, $segments);
    }

    public function config(string ...$segments): string
    {
        return self::join($this->root . '/config', $segments);
    }

    public function public(string ...$segments): string
    {
        return self::join($this->root . '/public', $segments);
    }

    public function assets(string ...$segments): string
    {
        return self::join($this->public('assets'), $segments);
    }

    public function storage(string ...$segments): string
    {
        return self::join($this->root . '/storage', $segments);
    }

    public function builds(string ...$segments): string
    {
        return self::join($this->storage('builds'), $segments);
    }

    public function log(string ...$segments): string
    {
        return self::join($this->storage('log'), $segments);
    }

    public function cache(string ...$segments): string
    {
        return self::join($this->storage('cache'), $segments);
    }

    public function app(string ...$segments): string
    {
        return self::join($this->root . '/src', $segments);
    }

    public function modules(string ...$segments): string
    {
        return self::join($this->app('Modules'), $segments);
    }

    public function common(string ...$segments): string
    {
        return self::join($this->app('Common'), $segments);
    }

    /**
     * Define as constantes globais usadas até a 1.0.19 (DIR_ROOT, DIR_CONFIG, ROUTE_MAPS_FILENAME...).
     * Cada constante só é definida se ainda não existir (um segundo start() não gera warnings).
     *
     * @deprecated as constantes continuam disponíveis por compatibilidade; prefira App::path()/App::paths().
     */
    public function defineLegacyConstants(): void
    {
        $constants = [
            // Root
            'DIR_ROOT' => $this->root(),
            'DIR_CONFIG' => $this->config(),
            'DIR_PUBLIC' => $this->public(),
            'DIR_ASSETS' => $this->assets(),
            'DIR_STORAGE' => $this->storage(),
            'DIR_BUILDS' => $this->builds(),
            'DIR_LOG' => $this->log(),
            'DIR_CACHE' => $this->cache(),

            // Build
            'DIR_BUILD_MODULE_APIS' => $this->builds('apis'),
            'ROUTE_MAPS_FILENAME' => $this->builds('route-maps.php'),
            'ROUTE_MAPS_BUILD_FILENAME_BACK' => $this->builds('route-maps.build.php'),
            'ROUTE_MAPS_BUILD_FILENAME_FRONT' => $this->builds('route-maps.build.js'),
            'CONFIGS_BUILD_FILENAME_FRONT' => $this->builds('configs.build.json'),
            'VENDOR_ENDPOINTS_MAP_FILENAME' => $this->builds('vendor-endpoints.build.php'),
            'APP_CONSTANTS_FILENAME' => $this->builds('constants.build.php'),
            'APP_HOT_RELOAD_FILENAME' => $this->log('hot-reload.txt'),

            // App
            'DIR_APP' => $this->app(),
            'DIR_MODULES' => $this->modules(),
            'DIR_COMMON' => $this->common(),
            'DIR_TOOLS' => $this->common('Tools'),
            'DIR_TEMPLATES' => $this->common('Templates'),
            'DIR_MIDDLEWARES' => $this->common('Middlewares'),

            // Namespace
            'APP_NAMESPACE' => 'App\\',
            'APP_MODULES_NAMESPACE' => 'App\\Modules\\',
            'APP_TEMPLATES_NAMESPACE' => 'App\\Common\\Templates\\',
        ];

        foreach ($constants as $name => $value) {
            if (!defined($name)) {
                define($name, $value);
            }
        }
    }

    /**
     * @param string[] $segments
     */
    private static function join(string $base, array $segments): string
    {
        $segments = array_filter(array_map(fn ($s) => trim($s, '/\\'), $segments), fn ($s) => $s !== '');

        return $segments ? $base . '/' . implode('/', $segments) : $base;
    }
}
