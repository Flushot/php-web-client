<?php

/**
 * HTTP client
 *
 * Wraps CURL's primitive C-like API, making it less cumbersome to send requests,
 * handles various edge cases, allows any HTTP verb, throws exceptions on errors,
 * and automatically decodes responses.
 */
class WebRequest {
    public $method = 'GET';
    public $url = null;
    private $finalUrl = null;

    public $args = [];
    public $queryString = null;

    public $body = null;
    public $files = [];

    public $headers = [];
    public $json = false;
    public $cache = null; // Cache implementation

    public $followRedirects = true;

    public $username = null;
    public $password = null;

    public $connectTimeout = null; // Timeout for connection attempt (null = default)
    public $executeTimeout = null; // Timeout for curl function execution (null = default)
    public $debug = false;

    public function __construct() {
        $this->cache = new NoCache();
    }

    /**
     * Validate state of object.
     *
     * @throws InvalidArgumentException on invalid state.
     */
    private function validate() {
        if (!$this->method)
            throw new InvalidArgumentException('method is required');

        if (!$this->url)
            throw new InvalidArgumentException('url is required');

        if (!is_string($this->url))
            throw new InvalidArgumentException('url must be a string');

        if ($this->args && !is_array($this->args))
            throw new InvalidArgumentException('args must be an associative array');

        if ($this->files && !is_array($this->files))
            throw new InvalidArgumentException('files must be an associative array');

        if ($this->body && $this->files)
            throw new InvalidArgumentException('body and files are mutually exclusive');

        if ($this->args && $this->queryString)
            throw new InvalidArgumentException('args and queryString are mutually exclusive');

        if ($this->queryString && !is_string($this->queryString))
            throw new InvalidArgumentException('queryString must be a string');

        if ($this->cache === null || !($this->cache instanceof WebRequestCache))
            throw new InvalidArgumentException('cache must be set to a WebRequestCache implementation (use NoCache to disable)');
    }

    public static function create($method, $url) {
        $request = new WebRequest();
        $request->method = $method;
        $request->url = $url;
        return $request;
    }

    public static function createAndSend($method, $url) {
        $request = self::create($method, $url);
        return $request->send()->getBody();
    }

    public function send() {
        $this->validate();

        $queryString = ( 
            $this->queryString 
                ? '?' . $this->queryString
                : self::buildQueryString($this->args) );

        $this->finalUrl = $this->url . $queryString;

        // Look in cache first
        $response = $this->cache->getFromCache($this->finalUrl);
        if ($response !== null) {
            // error_log('WebRequest: ' . get_class($this->cache) . ': Cache HIT: ' . $this->finalUrl);
            return $response;
        }
        else {
            error_log('WebRequest: ' . get_class($this->cache) . ': Cache MISS: ' . $this->finalUrl);

            // Send request
            $curlRequest = $this->initCurlRequest();
            $response = $this->sendCurlRequest($curlRequest);

            // Save in cache
            $this->cache->saveToCache($this->finalUrl, $response);

            return $response;
        }
    }

    /**
     * Create a new curl HTTP request.
     *
     * @return CURL request, ready to send.
     */
    private function initCurlRequest() {
        $method = strtoupper($this->method);

        // Construct request
        $curlRequest = curl_init($this->finalUrl);
        curl_setopt($curlRequest, CURLOPT_RETURNTRANSFER, 1); // Return contents instead of boolean on exec
        curl_setopt($curlRequest, CURLOPT_BINARYTRANSFER, 1);
        curl_setopt($curlRequest, CURLOPT_HEADER, 1); // Return headers in response
        if ($this->followRedirects)
            curl_setopt($curlRequest, CURLOPT_FOLLOWLOCATION, true); // Follow redirects

        // Timeouts
        if ($this->connectTimeout !== null)
            curl_setopt($curlRequest, CURLOPT_CONNECTTIMEOUT, $this->connectTimeout);
        if ($this->executeTimeout !== null)
            curl_setopt($curlRequest, CURLOPT_TIMEOUT, $this->executeTimeout);

        // Method-specific
        $acceptsBody = false;
        if ($method === 'POST') {
            curl_setopt($curlRequest, CURLOPT_POST, 1);
            $acceptsBody = true;
        }

        // BUG: curl will attempt to set "Transfer-Encoding: chunked" header if doing a PUT
        // the workaround is to POST, using an X-HTTP-Method-Override instead.
        // BEWARE: This also requires the server to know how to handle this header.
        if (in_array($method, array('PUT', 'PATCH'))) {
            $this->headers[] = "X-HTTP-Method-Override: $method";
            curl_setopt($curlRequest, CURLOPT_POST, 1);
            $acceptsBody = true;
        }

        if ($method === 'DELETE')
            curl_setopt($curlRequest, CURLOPT_DELETE, 1);

        // Body
        $sendBlankExpect = false;
        if ($acceptsBody) {
            if ($this->files !== null && count($this->files) > 0) {
                $files = [];
                foreach ($this->files as $fileKey => $localPath)
                    $files[$fileKey] = '@' . $localPath;
                
                curl_setopt($curlRequest, CURLOPT_POSTFIELDS, $files);

                // Workaround for CURL issue where curl_exec() returns both 100/CONTINUE and 200/OK separated by
                // blank line when using multipart form data.
                $sendBlankExpect = true;
            }
            elseif ($this->body) {
                // Default content-type of POST body
                if ($this->json && count(preg_grep('/content-type:/i', $this->headers)) === 0)
                    $this->headers[] = 'Content-Type: application/json';

                // Seems to implicitly set CURLOPT_POST=1
                curl_setopt($curlRequest, CURLOPT_POSTFIELDS, $this->body);
            }
        }

        // Add headers
        $headers = $this->headers;
        if (!$headers)
            $headers = [];

        if ($this->json && count(preg_grep('/accept:/i', $headers)) === 0)
            $headers[] = 'Accept: application/json';
        if ($sendBlankExpect)
            $headers[] = 'Expect:';

        if (count($headers) > 0)
            curl_setopt($curlRequest, CURLOPT_HTTPHEADER, $headers);

        // Authenticate request
        if ($this->username) {
            curl_setopt($curlRequest, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            curl_setopt($curlRequest, CURLOPT_USERPWD, $this->username . ':' . $this->password);
        }

        return $curlRequest;
    }

    /**
     * Send initialized HTTP request to web server.
     *
     * @param $curlRequest initialized CURL request.
     * @throws WebRequestException on non-2xx (error) response from server.
     * @return WebResponse
     */
    private function sendCurlRequest($curlRequest) {
        $this->validate();

        $curlResponse = curl_exec($curlRequest);

        // Request error
        if ($curlResponse === FALSE) {
            $error = curl_error($curlRequest);
            if (!$error)
                $error = 'curl did not return an error code';

            throw new WebRequestException("WebRequest to {$this->finalUrl} failed: $error");
        }

        $response = new WebResponse($curlRequest, $curlResponse);

        // Ensure there was no server error
        $status = $response->getStatus();
        if ($status < 200 || $status > 299) { // non-2xx means error
            $error = $response->getBody();

            if ($this->debug) {
                $errorIsJson = $response->getHeader('content-type') === 'application/json';
                $logMsg = 'WebRequest error ' . $status;
                if ($error != null)
                    $logMsg .= ': ' . ($errorIsJson ? json_encode($error) : $error);
                error_log($logMsg);
            }

            throw new WebRequestException($error, $status);
        }

        if ($this->debug) {
            $info = curl_getinfo($curlRequest);
            error_log("WebRequest to {$this->finalUrl} succeeded with status ${status} (took {$info['total_time']} seconds)");
        }

        return $response;
    }

    /**
     * Build a query string from an args hash.
     *
     * @param $args hash of query arguments [ argName => argValue ]
     * @return query string.
     */
    private static function buildQueryString($args) {
        if (!$args || count($args) === 0)
            return null;

        $queryString = [];
        foreach ($args as $k => $v)
            $queryString[] = urlencode($k) . '=' . urlencode($v);

        return '?' . join('&', $queryString);
    }
}


/**
 * HTTP response object
 */
class WebResponse {
    private $status = null; // HTTP status code
    private $headers = [];

    private $body = null;
    private $decodedBody = null;
    private $isDecoded = false;

    public function __construct($curlRequest, $curlResponse) {
        $this->status = curl_getinfo($curlRequest, CURLINFO_HTTP_CODE);

        // Parse headers
        $headerSize = curl_getinfo($curlRequest, CURLINFO_HEADER_SIZE);
        foreach (explode("\r\n", substr($curlResponse, 0, $headerSize)) as $headerLine) {
            if (!$headerLine || preg_match('/^HTTP\//', $headerLine) === 1)
                continue;

            $fields = explode(': ', $headerLine, 2);
            $this->headers[strtolower($fields[0])] = $fields[1];
        }

        $this->body = substr($curlResponse, $headerSize);
    }

    public function getHeader($key) {
        return $this->headers[strtolower($key)];
    }

    public function getHeaders() {
        return $this->headers;
    }

    public function getStatus() {
        return $this->status;
    }

    public function getBody() {
        if (!$this->isDecoded) {
            $this->decodedBody = $this->decodeResponseBody();
            $this->isDecoded = true;
        }

        return $this->decodedBody;
    }

    /**
     * Decode HTTP response entity.
     *
     * @throws DomainException when decode failed.
     * @return decoded response in its appropriate representation.
     */
    private function decodeResponseBody() {
        $type = $this->headers['content-type'];

        // Edge cases
        if (!$type)
            return $body;
        if ($this->body === null || $this->body === '')
            return $this->body;

        // JSON
        if (preg_match('@^application/json@', $type) === 1) {
            $decoded = json_decode($this->body, 
                                   true, // Decode as an associative array (instead of object)
                                   512, // Max stack depth for recursion
                                   JSON_BIGINT_AS_STRING); // Encode big ints as strings (instead of floats)

            if ($decoded === null) {
                // null is ambiguous in its meaning...
                $errorCode = json_last_error();
                if ($errorCode === JSON_ERROR_NONE) {
                    // Really decoded a null
                    return $decoded;
                }
                else {
                    // Decode failed

                    // Build JSON error message lookup
                    $jsonErrors = [];
                    $constants = get_defined_constants(true);
                    $prefix = 'JSON_ERROR_';
                    foreach ($constants['json'] as $name => $value) {
                        if (strncmp($name, $prefix, strlen($prefix)) === 0)
                            $jsonErrors[$value] = $name;
                    }

                    $message = $jsonErrors[$errorCode];
                    if (!$message)
                        $message = "Unknown error code: $errorCode";

                    throw new WebEntityCodecError("json_decode() failed: $message");
                }
            }
            else {
                // Decode succeeded
                return $decoded;
            }
        }
        // XML
        elseif (preg_match('@^(application|text)/xml@', $type) === 1) {
            $document = new DOMDocument();
            $document->loadXML($this->body);
            return $document;
        }
        // as-is
        else {
            return $this->body;
        }
    }
}


/**
 * WebRequest error
 */
class WebRequestException extends Exception {
    private $status; // Status code

    /**
     * @param $message detailed error message.
     * @param $status HTTP status code.
     */
    function __construct($message, $status=null) {
        $this->message = $message;
        $this->status = $status;
    }

    public function getStatus() {
        return $this->status;
    }
}


/**
 * Cache
 */
abstract class WebRequestCache {
    /**
     * @param $url the URL (used as a cache key).
     * @return whether or not the URL is in the cache and is still valid.
     */
    public abstract function isInCache($url);

    /**
     * @param $url the URL (used as a cache key).
     * @return cached WebResponse or null if there is no cache entry.
     */
    public function getFromCache($url) {
        $key = $this->createHash($url);

        if (!$this->isInCache($url))
            return null;

        $cacheFilePath = $this->getCacheFilePath($key);
        $fp = fopen($cacheFilePath, 'r');
        $data = fread($fp, filesize($cacheFilePath));
        fclose($fp);

        $data = $this->unserializeResponse($data);

        return $data;
    }

    /**
     * @param $url the URL (used as a cache key).
     * @param $response WebResponse object to store in cache.
     */
    public function saveToCache($url, WebResponse $response) {
        $key = $this->createHash($url);

        // error_log("Saving \"$url\" to cache with key $key");
        $data = $this->serializeResponse($response);

        $cacheFilePath = $this->getCacheFilePath($key);

        unlink($cacheFilePath);
        $fp = fopen($cacheFilePath, 'w+');
        fwrite($fp, $data);
        fclose($fp);
    }

    /**
     * Serializes a WebResponse into a cacheable string.
     * This method can be overridden by subclasses for custom serialization handling.
     */
    protected function serializeResponse(WebResponse $response) {
        return serialize($response);
    }

    /**
     * Un-serializes a WebResponse from a cacheable string.
     * This method can be overridden by subclasses for custom serialization handling.
     */
    protected function unserializeResponse($data) {
        return unserialize($data);
    }

    /**
     * Generates a valid path to a cache file for $key.
     */
    protected function getCacheFilePath($key) {
        $tempPath = sys_get_temp_dir();
        if (!$tempPath)
            throw new RuntimeException('Unable to get temp directory');

        return $tempPath . '/' . $key . '.webcache';
    }

    /**
     * Creates a hash of a cache key.
     *
     * @param $key the key to hash.
     */
    protected function createHash($key) {
        return hash('sha256', $key, FALSE);
    }
}


/**
 * Cache that has a fixed expiration.
 */
class FixedExpirationCache extends WebRequestCache {
    private $maxAgeInSeconds = 0;

    public function __construct($maxAgeInSeconds) {
        $this->maxAgeInSeconds = $maxAgeInSeconds;
    }

    public function isInCache($url) {
        $key = $this->createHash($url);

        $ageInSeconds = $this->getAgeInSeconds($key);
        if ($ageInSeconds === -1) // File doesn't exist
            return false;

        if ($this->maxAgeInSeconds <= 0) // 0 means never cache
            return true;

        $isExpired = ($ageInSeconds >= $this->maxAgeInSeconds);
        // if ($isExpired)
        //     error_log("Cache entry for $key is expired ($ageInSeconds seconds old)");

        return !$isExpired;
    }

    /**
     * Get age of cache entry (in seconds).
     *
     * @param $key the cache key to get of age of.
     * @param $fromTime (optional) the relative time to determine age from (defaults to now).
     * @return number of seconds old the cache entry is.
     */
    protected function getAgeInSeconds($key, $fromTime=null) {
        if ($fromTime === null)
            $fromTime = time();

        $cacheFilePath = $this->getCacheFilePath($key);
        if (!file_exists($cacheFilePath)) {
            // error_log("Cache entry does not exist for $key");
            return -1;
        }

        $mtime = filemtime($cacheFilePath);
        $age = $fromTime - $mtime;

        return $age;
    }
}


/**
 * Does not cache anything.
 */
class NoCache extends WebRequestCache {
    public function isInCache($url) {
        return false;
    }

    public function saveToCache($url, WebResponse $response) {
        // Do nothing
    }
}
