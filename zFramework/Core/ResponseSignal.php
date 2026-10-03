<?php

namespace zFramework\Core;

/**
 * "The response is ready, stop here."
 *
 * Thrown by abort(), redirect(), refresh() and file downloads. It unwinds to
 * Run::begin(), which sends the response and carries on.
 *
 * Where die() would end the process - fine under PHP-FPM, fatal to a
 * long-running worker - this ends only the request, and behaves identically
 * either way. One code path for both.
 *
 * Extends Error rather than Exception on purpose: application code doing
 * `try { ... } catch (\Exception $e)` around a controller would otherwise
 * swallow its own redirect and keep going.
 */
class ResponseSignal extends \Error
{
    /**
     * @param int    $status  HTTP status code, or 0 to leave it untouched.
     * @param array  $headers name => value
     * @param string $body    Already-rendered output.
     */
    public function __construct(
        public readonly int $status = 0,
        public readonly array $headers = [],
        public readonly string $body = ''
    ) {
        parent::__construct($body, $status);
    }

    /**
     * Whether the browser goes on to another page after this response.
     *
     * redirect(), back() and refresh() hand the visitor to a page that has not
     * rendered yet - that page is where pending alerts and JustOneTime data are
     * shown, so they must survive this request. abort() and a download are the
     * end of the line and consume them.
     *
     * @return bool
     */
    public function navigates(): bool
    {
        return isset($this->headers['Location']) || isset($this->headers['Refresh']);
    }

    /**
     * Emit the response this signal carries.
     * @return void
     */
    public function send(): void
    {
        # Through Response rather than header() directly: under the CLI SAPI a
        # long-running worker collects these instead of PHP dropping them.
        if ($this->status) \zFramework\Core\Facades\Response::status($this->status);
        foreach ($this->headers as $name => $value) \zFramework\Core\Facades\Response::header($name, $value);

        if (strlen($this->body)) echo $this->body;
    }
}
