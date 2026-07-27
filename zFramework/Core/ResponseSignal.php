<?php

namespace zFramework\Core;

/**
 * "The response is ready, stop here."
 *
 * abort(), redirect(), refresh() and file downloads used to call die(). That
 * works on PHP-FPM and shared hosting, where the process ends with the request
 * anyway - but under a long-running server (RoadRunner and friends) die() kills
 * the worker, not the request.
 *
 * Throwing instead unwinds to Run::begin(), which sends the response and carries
 * on. Behaviour under FPM is unchanged: the script still stops right there and
 * prints exactly what die() would have printed. Nothing extra is required to run
 * on shared hosting - there is only one code path.
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
