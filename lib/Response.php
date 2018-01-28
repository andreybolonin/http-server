<?php

namespace Aerys;

use Amp\ByteStream\InMemoryStream;
use Amp\ByteStream\InputStream;
use Amp\Http\Cookie\ResponseCookie;
use Amp\Http\Status;
use Amp\Loop;
use Amp\Socket\Socket;

class Response extends Message {
    /** @var \Amp\ByteStream\InputStream  */
    private $body;

    /** @var int HTTP status code. */
    private $status;

    /** @var string Response reason. */
    private $reason;

    /** @var ResponseCookie[] */
    private $cookies = [];

    /** @var array */
    private $push = [];

    /** @var array|null */
    private $detach;

    /** @var callable[] */
    private $onDispose = [];

    /**
     * @param \Amp\ByteStream\InputStream|string|null $stringOrStream
     * @param string[][] $headers
     * @param int $code Status code.
     * @param string|null $reason Status code reason.
     *
     * @throws \Error If one of the arguments is invalid.
     */
    public function __construct(
        $stringOrStream = null,
        array $headers = [],
        int $code = Status::OK,
        string $reason = null
    ) {
        $this->status = $this->validateStatusCode($code);
        $this->reason = $reason ?? Status::getReason($this->status);

        if (!empty($headers)) {
            $this->setHeaders($headers);
        }

        $this->setBody($stringOrStream);
    }

    public function __destruct() {
        foreach ($this->onDispose as $callable) {
            try {
                $callable();
            } catch (\Throwable $exception) {
                Loop::defer(function () use ($exception) {
                    throw $exception; // Forward uncaught exceptions to the loop error handler.
                });
            }
        }
    }

    /**
     * Returns the stream for the message body.
     *
     * @return \Amp\ByteStream\InputStream
     */
    public function getBody(): InputStream {
        return $this->body;
    }

    /**
     * Sets the stream for the message body. Note that using a string will automatically set the Content-Length header
     * to the length of the given string. Setting a stream will remove the Content-Length header.
     *
     * @param \Amp\ByteStream\InputStream|string|null $stringOrStream
     *
     * @throws \TypeError If the body given is not a string or instance of \Amp\ByteStream\InputStream
     */
    public function setBody($stringOrStream) {
        if ($stringOrStream instanceof InputStream) {
            $this->body = $stringOrStream;
            return;
        }

        if ($stringOrStream !== null && !\is_string($stringOrStream)) {
            throw new \TypeError("The response body must a string, null, or instance of " . InputStream::class);
        }

        $this->body = new InMemoryStream($stringOrStream);
        $this->setHeader("content-length", (string) \strlen($stringOrStream));
    }

    /**
     * Sets the named header to the given value.
     *
     * @param string $name
     * @param string|string[] $value
     *
     * @throws \Error If the header name or value is invalid.
     */
    public function setHeader(string $name, $value) {
        parent::setHeader($name, $value);

        if (\stripos($name, "set-cookie") === 0) {
            $this->setCookiesFromHeaders();
        }
    }

    /**
     * Adds the value to the named header, or creates the header with the given value if it did not exist.
     *
     * @param string $name
     * @param string|string[] $value
     *
     * @throws \Error If the header name or value is invalid.
     */
    public function addHeader(string $name, $value) {
        parent::addHeader($name, $value);

        if (\stripos($name, "set-cookie") === 0) {
            $this->setCookiesFromHeaders();
        }
    }

    /**
     * Removes the given header if it exists.
     *
     * @param string $name
     */
    public function removeHeader(string $name) {
        parent::removeHeader($name);

        if (\stripos($name, "set-cookie") === 0) {
            $this->cookies = [];
        }
    }

    /**
     * Returns the response status code.
     *
     * @return int
     */
    public function getStatus(): int {
        return $this->status;
    }

    /**
     * Returns the reason phrase describing the status code.
     *
     * @return string
     */
    public function getReason(): string {
        return $this->reason;
    }

    /**
     * Sets the response status code and reason phrase. Use null for the reason phrase to use the default phrase
     * associated with the status code.
     *
     * @param int $code 100 - 599
     * @param string|null $reason
     */
    public function setStatus(int $code, string $reason = null) {
        $this->status = $this->validateStatusCode($code);
        $this->reason = $reason ?? Status::getReason($this->status);
    }

    /**
     * @return ResponseCookie[]
     */
    public function getCookies(): array {
        return $this->cookies;
    }

    /**
     * @param string $name Name of the cookie.
     *
     * @return ResponseCookie|null
     */
    public function getCookie(string $name) { /* : ?ResponseCookie */
        return $this->cookies[$name] ?? null;
    }

    /**
     * Adds a cookie to the response.
     *
     * @param ResponseCookie $cookie
     */
    public function setCookie(ResponseCookie $cookie) {
        $this->cookies[$cookie->getName()] = $cookie;
        $this->setHeadersFromCookies();
    }

    /**
     * Removes a cookie from the response.
     *
     * @param string $name
     */
    public function removeCookie(string $name) {
        if (isset($this->cookies[$name])) {
            unset($this->cookies[$name]);
            $this->setHeadersFromCookies();
        }
    }

    /**
     * @param int $code
     *
     * @return int
     *
     * @throws \Error
     */
    private function validateStatusCode(int $code): int {
        if ($code < 100 || $code > 599) {
            throw new \Error(
                'Invalid status code. Must be an integer between 100 and 599, inclusive.'
            );
        }

        return $code;
    }

    /**
     * Sets cookies based on headers.
     *
     * @throws \Error
     */
    private function setCookiesFromHeaders() {
        $this->cookies = [];

        $headers = $this->getHeaderArray("set-cookie");

        foreach ($headers as $line) {
            $cookie = ResponseCookie::fromHeader($line);
            $this->cookies[$cookie->getName()] = $cookie;
        }
    }

    /**
     * Sets headers based on cookie values.
     */
    private function setHeadersFromCookies() {
        $values = [];

        foreach ($this->cookies as $cookie) {
            $values[] = (string) $cookie;
        }

        $this->setHeader("set-cookie", $values);
    }

    /**
     * @return string[][]
     */
    public function getPush(): array {
        return $this->push;
    }

    /**
     * @param string $url URL of resource to push to the client.
     * @param string[][] Additional headers to attach to the request.
     */
    public function push(string $url, array $headers = []) {
        \assert((function ($headers) {
            foreach ($headers as $name => $header) {
                if ($name[0] === ":" || !strncasecmp("host", $name, 4)) {
                    return false;
                }
            }
            return true;
        })($headers), "Headers must not contain colon prefixed headers or a Host header");

        $this->push[$url] = $headers;
    }

    /**
     * @return bool True if a detach callback has been set, false if none.
     */
    public function isDetached(): bool {
        return $this->detach !== null;
    }

    /**
     * @param callable $detach Callback invoked once the response has been written to the client. The callback is given
     *     an instance of \Amp\Socket\ServerSocket as the first parameter, followed by the given arguments.
     * @param array ...$args Arguments to pass to the detach callback.
     */
    public function detach(callable $detach, ...$args) {
        $this->detach = [$detach, $args];
    }

    /**
     * Registers a function that is invoked when the Response is discarded. A response is discarded either once it has
     * been written to the client or if it replaced in a middleware chain.
     *
     * @param callable $onDispose
     */
    public function onDispose(callable $onDispose) {
        $this->onDispose[] = $onDispose;
    }

    /**
     * @internal
     *
     * @param \Amp\Socket\Socket $socket
     */
    public function export(Socket $socket) {
        \assert(\is_array($this->detach));

        list($detch, $args) = $this->detach;
        $detch($socket, ...$args);
    }
}
