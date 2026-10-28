<?php

namespace zFramework\Core\Facades;

use CURLFile;
use CURLStringFile;

/**
 * One HTTP request per instance.
 *
 *   cURL::set($url)->headers([...])->post($body)->send(fn($body, $info, $error) => ...)
 *
 * The state used to live in statics, so two requests built side by side - or one
 * started inside another's callback - wrote over each other, and a worker had to
 * clear it between requests. Each set() is now its own request.
 */
class cURL
{
    /** Defaults; options() overrides them. Without any, a host that stopped answering held the caller for as long as the connection stayed open. */
    const CONNECT_TIMEOUT = 10;
    const TIMEOUT         = 30;

    private \CurlHandle $handle;
    private mixed $postFields = null;
    private bool $post        = false;
    private ?string $method   = null;
    private array $headers    = [];

    /**
     * set url and some options
     * @param string $url
     * @return self
     */
    public static function set(string $url): self
    {
        $request = new self();
        $request->handle = curl_init($url);
        curl_setopt_array($request->handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => false,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_USERAGENT      => 'zFramework',
        ]);
        return $request;
    }

    /**
     * HTTP method. Without one it is GET, or POST once post()/file() is called.
     * HEAD asks for no body: curl sent HEAD as a custom verb still waits for the
     * body the headers announce.
     * @param string $method
     * @return self
     */
    public function method(string $method): self
    {
        $this->method = strtoupper($method);
        return $this;
    }

    /**
     * Post parameters
     * @param mixed $fields array (form fields) or string (raw body)
     * @return self
     */
    public function post(mixed $fields = []): self
    {
        $this->post = true;
        $this->postFields = $fields;
        return $this;
    }

    /**
     * Post file parameters
     * @param string $fieldname
     * @param string $filename name the server sees
     * @param string $content  path of a file on disk, or the content itself
     * @param string $mime_type
     * @return self
     */
    public function file(string $fieldname, string $filename, string $content, string $mime_type = "text/plain"): self
    {
        $this->post = true;
        if (!is_array($this->postFields)) $this->postFields = [];

        # CURLFile reads a path; content held in memory used to be taken for one
        # and the upload failed. Anything that is not a file on disk is sent as is.
        $this->postFields[$fieldname] = strlen($content) < PHP_MAXPATHLEN && !str_contains($content, "\0") && @is_file($content)
            ? new CURLFile($content, $mime_type, $filename)
            : new CURLStringFile($content, $filename, $mime_type);
        return $this;
    }

    /**
     * Set Request Headers. Calls add up; a name given again replaces the earlier value.
     * @param array $headers ['Name' => 'value'] or ['Name: value']
     * @return self
     */
    public function headers(array $headers): self
    {
        foreach ($headers as $key => $value) {
            $line = is_int($key) ? (string) $value : "$key: $value";
            $this->headers[strtolower(trim(strstr($line, ':', true) ?: $line))] = $line;
        }
        return $this;
    }

    /**
     * Set Options
     * @param array $options
     * @return self
     */
    public function options(array $options = []): self
    {
        curl_setopt_array($this->handle, $options);
        return $this;
    }

    /**
     * Send request to target with all settings.
     *
     * The callback gets ($body, $info, $error):
     *   $body  - string, or false when the request failed
     *   $info  - curl_getinfo() plus 'headers': the response headers, names
     *            lower-cased, from the final response only (not a `100 Continue`
     *            or a redirect before it); a repeated name keeps the last value
     *   $error - ['error_no' => int, 'error_message' => string]
     * Without a callback the body (or false) is returned.
     *
     * @param \Closure $callback
     */
    public function send(?\Closure $callback = null)
    {
        if ($this->post) {
            curl_setopt($this->handle, CURLOPT_POST, true);
            curl_setopt($this->handle, CURLOPT_POSTFIELDS, $this->postFields);
        }

        if ($this->method === 'HEAD') curl_setopt($this->handle, CURLOPT_NOBODY, true);
        elseif ($this->method !== null && $this->method !== ($this->post ? 'POST' : 'GET')) curl_setopt($this->handle, CURLOPT_CUSTOMREQUEST, $this->method);

        if ($this->headers) curl_setopt($this->handle, CURLOPT_HTTPHEADER, array_values($this->headers));

        $headers = [];
        curl_setopt($this->handle, CURLOPT_HEADERFUNCTION, function ($handle, string $line) use (&$headers) {
            # Every status line starts a new response; only the last one's headers are kept.
            if (preg_match('#^HTTP/\S+\s+\d{3}#', $line)) $headers = [];
            elseif (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }
            return strlen($line);
        });

        $response = curl_exec($this->handle);
        $error    = ['error_no' => curl_errno($this->handle), 'error_message' => curl_error($this->handle)];
        $info     = curl_getinfo($this->handle) + ['headers' => $headers];

        if ($callback != null) return $callback($response, $info, $error);

        return $response;
    }
}
