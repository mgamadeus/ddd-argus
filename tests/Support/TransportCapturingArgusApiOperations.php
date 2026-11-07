<?php

declare(strict_types=1);

namespace DDD\Tests\Argus\Support;

use DDD\Domain\Base\Repo\Argus\Utils\ArgusApiOperations;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;

/**
 * The executor with the network replaced by a queue of answers ({@see $mockHandler}) and every FINAL request — after
 * the header merge — kept in {@see $sentRequests} (Guzzle's history middleware: request, response, options).
 */
class TransportCapturingArgusApiOperations extends ArgusApiOperations
{
    public MockHandler $mockHandler;

    /** @var array[] Guzzle history entries: request, response, error, options */
    public array $sentRequests = [];

    public function __construct(mixed ...$queuedAnswers)
    {
        parent::__construct();
        $this->mockHandler = new MockHandler($queuedAnswers);
    }

    protected function createHttpClient(?CurlMultiHandler $curlMultiHandler = null): Client
    {
        $handlerStack = HandlerStack::create($this->mockHandler);
        $handlerStack->push(Middleware::history($this->sentRequests));
        return new Client(['handler' => $handlerStack]);
    }
}
