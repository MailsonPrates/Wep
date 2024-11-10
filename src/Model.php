<?php

namespace App\Core;

trait Model
{
    public static $table = null;

    protected static function run($method, $props)
    {
        if ( !self::$table ){

            $module_namespace_parts = explode('\\', static::class);
            $module_name = end($module_namespace_parts);
            $module_config = Core::config('module', $module_name);
            $module_config_db = $module_config->db ?? [];

            $module_table_name = $module_config->table ?? $module_config_db['table'] ?? null;

            if ( !$module_table_name ) throw new \Exception("Nome da tabela não definida nas configurações do módulo: $module_name");

            //$config = $module_config ?? [];
            $module_config_db['name'] = $module_table_name;

			self::$table = DB::table($module_table_name, $module_config_db);
		}

        return self::$table->{$method}($props);
    }

    public static function get($props=[])
    {
        return self::run('get', $props);
    }

    public static function create($props=[])
    {
        return self::run('create', $props);
    }

    public static function update($props=[])
    {
        return self::run('update', $props);
    }

    public static function delete($props=[])
    {
        return self::run('delete', $props);
    }
}