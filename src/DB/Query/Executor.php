<?php

namespace Core\DB\Query;

use Core\Response;

class Executor
{
    public static function execute(\PDO $pdo, string $query, array $fields=[], array $config=[])
    {
        if ( !$pdo ) return Response::error("Erro de conexão com banco de dados");
       
        try {
			
			$stmt = $pdo->prepare($query);
            
            if ( count($fields) > 0 ){
                
                $fields = (object) $fields;
                
                foreach( $fields as $key => &$value ){
                    $value = $value === NULL ? "" : $value;
                    $stmt->bindParam(":$key", $value);
                }
            }
            
			$result = $stmt->execute();
			
			/*if ( !$result || $isUpdate && $stmt->rowCount() < 0 ){
				return (object) [
					"error" => true
				];
			}
            
            if ( $isSelect ){
                $mode_const = strtoupper($mode ?: "assoc");
                $fetch_mode = constant("PDO::FETCH_$mode_const");

                $fetch_methods = [
                    "OBJ" => "fetch",
                    "ASSOC" => "fetchAll"
                ];

                $fetch = $fetch_methods[$mode_const] ?? "fetchAll";
                
                $data = $stmt->{$fetch}($fetch_mode);
                return (object) [
                    "error" => false, 
                    "data" => $data
                ];
            }
            */

            return Response::success();
			

        } catch (\PDOException $e){
            return Response::error($e->getMessage());
        }
    }
}