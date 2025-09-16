<?php

namespace App\Core\Router\Http;

use App\Core\Response;

class RouterHttpResponse
{
    private $headers = [
        
    ];

    /**
     * @param string $key
     * @param string $value
     * @param bool $replace
     */
	public function addHeader($key, $value=null, $replace=true) 
    {
		$this->headers[] = [$key, $value, $replace];

        return $this;
	}

    /**
	 * @param string $url
	 * @param int $status
	 *
	*/
	public function redirect($url, $status = 302) 
    {
		header('Location: ' . str_replace(array('&amp;', "\n", "\r"), array('&', '', ''), $url), true, $status);
		exit();
	}

    /**
     * @param array|string|int $content
     * @param array $extraData
     * @param bool|int $status true|false|http status code
     * 
     * @example
     * $response->json([]);
     * $response->json('', true|false);
     * $response->json('', ['code' => 200], true|false);
     * $response->json([], 404);
     * 
     */
    public function json($content=[], $extraData=null, $status=null)
    {
        $has_extra_data = is_array($extraData);
        $status = $has_extra_data ? $status : $extraData;
        $extra_data = $has_extra_data ? $extraData : null; 

        $is_default_response = is_bool($status);
        $status_code = is_int($status) ? $status : 200;

        if ( $has_extra_data && isset($extra_data['status_code']) ){
            $status_code = $extra_data['status_code'];
        }

        $this->addHeader('Content-Type', 'application/json');
        http_response_code($status_code); 
        $this->sendHeaders();

        if ( $is_default_response ) {

            if ( $has_extra_data ) return Response::json($content, $extra_data, $status);

            return Response::json($content, $status);
        }

        return Response::json($content);
    }

    public function sendHeaders()
    {
        if ( headers_sent() ) return;

        foreach ($this->headers as $item) {
            $key = $item[0];
            $value = $item[1];
            $replace = $item[2] ?? true;

            $header = "$key: $value";
            
            header($header, $replace);
        }
    }
    
}