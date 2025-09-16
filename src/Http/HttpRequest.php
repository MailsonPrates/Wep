<?php

namespace App\Core\Http;

use App\Core\Http\Methods;
use App\Core\Response;

class HttpRequest {

	use Methods;

	private $debug = [];

	private $url;

	private $url_parameterized = null;

	private $headers = [
		'Content-Type: application/json'
	];

	private $query = [];

	// Variables used for the request.
	public $userAgent = 'Mozilla/5.0 (compatible; PHP library)';
	public $connectTimeout = 10;
	public $timeout = 15;

	// Variables used for cookie support.
	private $cookiesEnabled = FALSE;
	private $cookiePath;

	// Enable or disable SSL/TLS.
	private $ssl = TRUE;

	private $requestType;
	private $fields;
	private $userpwd;
	private $latency;
	private $responseBody;
	private $responseHeader;
	private $httpCode;
	private $error;

	private $stop = false;

	private $attempts = 0;

	private $maxAttempts = 8;

	private $customResponse = [];

	private $params = [];

	public function __construct($props=null) 
	{
		$is_url = is_string($props);

		if ( $is_url ){
			$this->url = $props;
			return;
		}

		$is_verbose_options = is_array($props);

		if ( $is_verbose_options ){
			$this->setOptions($props);
		}
	}

	public function getUrl()
	{
		return $this->url;
	}

	public function setOptions($options=[])
	{
		if ( isset($options['url']) ){
			$this->setUrl($options['url']);
		}

		if ( isset($options['params']) ){
			$this->setParams($options['params']);
		}

		if ( isset($options['headers']) ){
			$this->setHeaders($options['headers']);
		}

		if ( isset($options['query']) ){
			$this->setQuery($options['query']);
		}

		if ( isset($options['max_attempts']) ){
			$this->setMaxAttempts($options['max_attempts']);
		}

		if ( isset($options['basicAuth']) ){
			$user = $options['basicAuth'][0] ?? '';
			$pass = $options['basicAuth'][1] ?? '';
			$this->setBasicAuthCredentials($user, $pass);
		}

		if ( isset($options['ssl']) ){

			$options['ssl']
				? $this->enableSSL()
				: $this->disableCookies();
		}

		if ( isset($options['setTimeout']) ){
			$this->setTimeout($options['setTimeout']);
		}

		if ( isset($options['setConnectTimeout']) ){
			$this->setConnectTimeout($options['setConnectTimeout']);
		}

		if ( isset($options['type']) ){
			$this->setRequestType($options['type']);
		}

		if ( isset($options['fields']) ){
			$this->setPostFields($options['fields']);
		}
	}

	public function getOptions($prop=null)
	{
		if ( $prop && $this->{$prop} ) return $this->{$prop} ?? null;

		return [
			'url' => $this->url,
			'url_parameterized' => $this->url_parameterized,
			'query' => $this->query,
			'params' => $this->params,
			'headers' => $this->headers,
			'fields' => $this->fields,
			'connectTimeout' => $this->connectTimeout,
			'timeout' => $this->timeout,
			'ssl' => $this->ssl,
			'userpwd' => $this->userpwd,
			'maxAttempts' => $this->maxAttempts,
			'requestType' => $this->requestType,
			'attempts' => $this->attempts,
			'stop' => $this->stop
		];
	}

	/**
	 * Set the address for the request.
	 *
	 * @param string $url
	 *   The URI or IP address to request.
	 */
	public function setUrl($url) 
	{
		$this->url = $url;
		return $this;
	}

	public function setQuery($key, $value=null)
	{
		$is_bulk = !$value;
		$query = $is_bulk ? $key : [$key => $value];

		$this->query = array_merge($this->query, $query);

		$this->setUrlQuery();

		return $this;
	}

	public function setParams($key, $value=null)
	{
		$is_bulk = !$value;
		$params = $is_bulk ? $key : [$key => $value];

		//$this->debug[] = [$this->params, $params];

		$this->params = array_merge($this->params, $params);

		//$this->debug[] = $this->params;

		$this->setUrlParams();

		return $this;
	}

	public function setMaxAttempts($value=3)
	{
		$this->maxAttempts = $value;
		return $this;
	}

	public function getAttempts()
	{
		return $this->attempts;
	}

	public function getParams()
	{
		return $this->params;
	}

	/**
	 * 
	 * @param string|array $header
	 * 
	 * @example
	 * ->setHeader('Content-Type: application/json');
	 * ->setHeader([
	 * 	'Content-Type: application/json',
	 *  'Authorization: ...'
	 * ]);
	 */
	public function setHeaders($headers=null)
	{
		if ( !$headers ) return $this;

		if ( is_array($headers) ){
			$this->headers = array_merge($this->headers, $headers);
			return $this;
		}

		$this->headers[] = $headers;

		return $this;
	}

	public function replaceHeaders($headers=[])
	{
		$this->headers = $headers;
		return $this;
	}

	/**
	 * Set the username and password for HTTP basic authentication.
	 *
	 * @param string $username
	 * @param string $password
	 */
	public function setBasicAuthCredentials($username, $password) 
	{
		$this->userpwd = $username . ':' . $password;

		return $this;
	}

	/**
	 * Enable cookies.
	 *
	 * @param string $cookie_path
	 *   Absolute path to a txt file where cookie information will be stored.
	 * 
	 * @todo adicionar ao setVerboseOptions
	 */
	public function enableCookies($cookie_path) 
	{
		$this->cookiesEnabled = TRUE;
		$this->cookiePath = $cookie_path;
		return $this;
	}

	/**
	 * Disable cookies.
	 * @todo adicionar ao setVerboseOptions
	 */
	public function disableCookies() 
	{
		$this->cookiesEnabled = FALSE;
		$this->cookiePath = '';
		return $this;
	}

	/**
	 * Enable SSL.
	 */
	public function enableSSL() 
	{
		$this->ssl = TRUE;

		return $this;
	}

	/**
	 * Disable SSL.
	 */
	public function disableSSL() 
	{
		$this->ssl = TRUE;
		return $this;
	}

	/**
	 * Set timeout.
	 *
	 * @param int $timeout
	 *   Timeout value in seconds.
	 */
	public function setTimeout($timeout = 15) 
	{
		$this->timeout = $timeout;
		return $this;
	}

	/**
	 * Get timeout.
	 *
	 * @return int
	 *   Timeout value in seconds.
	 */
	public function getTimeout() 
	{
		return $this->timeout;
	}

	/**
	 * Set connect timeout.
	 *
	 * @param int $connect_timeout
	 *   Timeout value in seconds.
	 */
	public function setConnectTimeout($connectTimeout = 10) 
	{
		$this->connectTimeout = $connectTimeout;
		return $this;
	}

	/**
	 * Get connect timeout.
	 *
	 * @return int
	 *   Timeout value in seconds.
	 */
	public function getConnectTimeout() 
	{
		return $this->connectTimeout;
	}

	/**
	 * Set a request type (by default, cURL will send a GET request).
	 *
	 * @param string $type
	 *   GET, POST, DELETE, PUT, etc. Any standard request type will work.
	 */
	public function setRequestType($type) 
	{
		$this->requestType = strtoupper($type);
		return $this;
	}

	/**
	 * Set the POST fields (only used if $this->requestType is 'POST').
	 *
	 * @param array $fields
	 *   An array of fields that will be sent with the POST request.
	 */
	public function setPostFields($fields = array()) 
	{
		$this->fields = $fields;
		return $this;
	}

	/**
	 * Get the response body.
	 *
	 * @return string
	 *   Response body.
	 */
	public function getResponseBody() 
	{
		return $this->responseBody;
	}

	/**
	 * Get the response header.
	 *
	 * @return string
	 *   Response header.
	 */
	public function getHeader() 
	{
		return $this->responseHeader;
	}

	/**
	 * Get the HTTP status code for the response.
	 *
	 * @return int
	 *   HTTP status code.
	 *
	 * @see http://www.w3.org/Protocols/rfc2616/rfc2616-sec10.html
	 */
	public function getHttpCode() 
	{
		return $this->httpCode;
	}

	/**
	 * Get the latency (the total time spent waiting) for the response.
	 *
	 * @return int
	 *   Latency, in milliseconds.
	 */
	public function getLatency() 
	{
		return $this->latency;
	}

	/**
	 * Get any cURL errors generated during the execution of the request.
	 *
	 * @return string
	 *   An error message, if any error was given. Otherwise, empty.
	 */
	public function getError() 
	{
		return $this->error;
	}

	/**
	 * Check for content in the HTTP response body.
	 *
	 * This method should not be called until after execute(), and will only check
	 * for the content if the response code is 200 OK.
	 *
	 * @param string $content
	 *   String for which the response will be checked.
	 *
	 * @return bool
	 *   TRUE if $content was found in the response, FALSE otherwise.
	 */
	public function checkResponseForContent($content = '') 
	{
		if ($this->httpCode == 200 && !empty($this->responseBody)) {
			if (strpos($this->responseBody, $content) !== FALSE) {
				return TRUE;
			}
		}
		return FALSE;
	}

	private function setUrlParams()
	{
		if ( empty($this->params) ) return $this;

		foreach( $this->params as $key => $value ){
			$this->url = str_replace("{{$key}}", $value, $this->url);
			//$this->debug['url_'. $key] = $this->url;
		}

		return $this;
	}

	private function setUrlQuery()
	{
		if ( empty($this->query) ) return $this;

		$this->url_parameterized = $this->url . '?' . http_build_query($this->query);

		return $this;
	}

	private function validate()
	{
		if ( !$this->url ) return Response::error('Url da requisição não informada');
		if ( $this->stop ) return Response::error('A requisição foi parada!');
		
		if ( $this->attempts >= $this->maxAttempts ) 
			return Response::error("O limite de $this->maxAttempts tentativas foi atingido");

		return Response::success();
	}

	/**
	 * Check a given address with cURL.
	 *
	 * After this method is completed, the response body, headers, latency, etc.
	 * will be populated, and can be accessed with the appropriate methods.
	 */
	public function execute() 
	{
		$this->setUrlQuery();
		$this->setUrlParams();

		$validate = $this->validate();

		if ( $validate->error ) {
			$this->responseHeader = null;
			$this->responseBody = [];
			$this->error = $validate->message;
			$this->httpCode = 0;

			return $this;
		}

		// Set a default latency value.
		$latency = 0;

		// Set up cURL options.
		$ch = curl_init();
		// If there are basic authentication credentials, use them.
		if (isset($this->userpwd)) {
			curl_setopt($ch, CURLOPT_USERPWD, $this->userpwd);
		}

		if ( !empty($this->headers) ){
			curl_setopt($ch, CURLOPT_HTTPHEADER, $this->headers);
		}

		// If cookies are enabled, use them.
		if ($this->cookiesEnabled) {
			curl_setopt($ch, CURLOPT_COOKIEJAR, $this->cookiePath);
			curl_setopt($ch, CURLOPT_COOKIEFILE, $this->cookiePath);
		}
		// Send a custom request if set (instead of standard GET).
		if (isset($this->requestType)) {
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $this->requestType);
			// If POST fields are given, and this is a POST request, add fields.
			if ($this->requestType !== 'GET' && !empty($this->fields)) {
				curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($this->fields) 
					? json_encode($this->fields) 
					: $this->fields
				);
			}
		}
		// Don't print the response; return it from curl_exec().
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
		curl_setopt($ch, CURLOPT_URL, $this->url_parameterized ?: $this->url);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->connectTimeout);
		curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
		// Follow redirects (maximum of 5).
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, TRUE);
		curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
		// SSL support.
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $this->ssl);
		// Set a custom UA string so people can identify our requests.
		curl_setopt($ch, CURLOPT_USERAGENT, $this->userAgent);
		// Output the header in the response.
		curl_setopt($ch, CURLOPT_HEADER, TRUE);
		$response = curl_exec($ch);
		$error = curl_error($ch);
		$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
		$time = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
		curl_close($ch);

		// Set the header, response, error and http code.
		$this->responseHeader = substr($response, 0, $header_size);
		$this->responseBody = substr($response, $header_size);
		$this->error = $error;
		$this->httpCode = $http_code;

		// Convert the latency to ms.
		$this->latency = round($time * 1000);
		
		$this->attempts = $this->attempts + 1;

		return $this;
	}

	public function stop($state=true)
	{
		$this->stop = $state;
		return $this;
	}

	public function retry()
	{
		$this->execute();
		return $this;
	}

	public function isError()
	{
		return $this->getHttpCode() > 339 || $this->getError();
	}

	public function setResponse($response=[])
	{
		$this->customResponse = (object)$response;
		return $this;
	}

	public function response()
	{
		if ( !empty($this->customResponse) ){
			return $this->customResponse;
		}

		$code = $this->getHttpCode();
        $response = $this->getResponseBody();
        $response = is_array($response) 
			? $response
			: (json_decode($response) ?? $response);

        $is_error = $this->isError();

        if ( $is_error ) return Response::error(($this->getError() ?: $response), [
            "http_code" => $code
        ]);

        return Response::success($response, [
            "http_code" => $code
        ]);
	}
}