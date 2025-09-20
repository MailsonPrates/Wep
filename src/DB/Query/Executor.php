<?php

namespace App\Core\DB\Query;

use App\Core\Response;

class Executor
{
    public static function execute(\PDO $pdo, string $query, array $fields=[], $config=[])
    {
        if ( !$pdo ) return Response::error("Erro de conexão com banco de dados");

        $debug = [
            'bind' => [],
            'query' => $query,
            'fields' => $fields,
            'config' => $config
        ];

        $config["raw"] = $config["raw"] ?? false;
        $config['debug'] = $config['debug'] ?? false;

        try {
			
			$stmt = $pdo->prepare($query);
            
            if ( count($fields) > 0 ){
                
                $fields = (object) $fields;
                
                foreach( $fields as $key => &$value ){
                    $value = $value === NULL ? "" : $value;
                    $stmt->bindParam(":$key", $value);
                    $debug['bind'][":$key"] = $value;
                }
            }

            if ( $config['debug'] ){
                return $debug;
            }

			$result = $stmt->execute();

            $query_method = explode(" ", strtolower($query))[0];
            $is_select_query = $query_method == "select";
            $is_update_query = $query_method == "update";
            $is_custom_query = $config['custom_query'] ?? false;

            $error_on_update = $result && ($is_update_query && $stmt->rowCount() < 0);
			
			if ( !$result || $error_on_update ) return Response::error();

            if ( $is_custom_query ){
                $is_select_query = str_contains($query, "Select");
            }
            
            if ( $is_select_query ){

                /**
                 * @todo refatorar
                 */
                $fetch_mode_string = $config["fetch"] ?? null;
                $fetch_mode_string = strtoupper($fetch_mode_string ?: "assoc");

                $fetch_mode = constant("PDO::FETCH_".$fetch_mode_string);

                $fetch_methods = [
                    "OBJ" => "fetch",
                    "ASSOC" => "fetchAll"
                ];

                $fetch = $fetch_methods[$fetch_mode_string] ?? "fetchAll";
                
                $data = $stmt->{$fetch}($fetch_mode);

                if ( $config["raw"] ) return Response::success($data, [
                    "stmt" => $stmt
                ]);

                return Response::success($data);
            }

            if ( $config["raw"] ) return Response::success([], [
                "stmt" => $stmt
            ]);

            return Response::success();

        } catch (\PDOException $e){
            return Response::error($e->getMessage());
        }
    }
}