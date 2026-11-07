<?php

declare(strict_types=1);

namespace DDD\Tests\Argus;

use DDD\Domain\Base\Repo\Argus\Attributes\ArgusLoad;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusApiOperation;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusApiOperations;
use DDD\Tests\Argus\Support\ArgusConfigOverride;
use DDD\Tests\Argus\Support\ArgusProbeEntity;
use DDD\Tests\Argus\Support\TransportCapturingArgusApiOperations;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

/**
 * The executor's requests as they reach the transport: URL, headers after the merge, body, options — and how the
 * answers reach the operations.
 */
class ArgusApiOperationsTransportTest extends TestCase
{
    protected const array ARGUS_CONFIG = [
        'microservices' => ['baseUrl' => 'https://gateway.example.test/'],
        'requestSettings' => ['headers' => ['x-api-key' => 'configured-key', 'Accept-Language' => 'de']],
        'auth' => false,
        'appendDebugFlags' => false,
        'trackingHeader' => 'x-argus-tracking',
    ];

    protected function setUp(): void
    {
        ArgusConfigOverride::apply(self::ARGUS_CONFIG);
        ArgusApiOperations::resetForNewJob();
        ArgusLoad::$logArgusCalls = false;
    }

    protected function tearDown(): void
    {
        ArgusConfigOverride::restore();
        ArgusApiOperations::resetForNewJob();
        ArgusLoad::$logArgusCalls = false;
    }

    protected function addOperation(
        TransportCapturingArgusApiOperations $argusApiOperations,
        string $id,
        string $endpoint,
        array $payload = []
    ): ArgusProbeEntity {
        $argusProbeEntity = new ArgusProbeEntity();
        $argusApiOperation = $argusProbeEntity->createOperation($id, $endpoint, $payload);
        $argusApiOperations->addOperation($argusApiOperation);
        return $argusProbeEntity;
    }

    protected function sentRequest(TransportCapturingArgusApiOperations $argusApiOperations, int $position = 0): RequestInterface
    {
        return $argusApiOperations->sentRequests[$position]['request'];
    }

    public function testACallsOwnHeadersWinOverTheConfiguredHeaders(): void
    {
        $argusApiOperations = new TransportCapturingArgusApiOperations(new Response(200, [], '{"status":"OK","data":{}}'));
        $this->addOperation($argusApiOperations, 'call-1', 'POST:/service/items', [
            'headers' => ['x-api-key' => 'own-key'],
            'body' => ['name' => 'value'],
        ]);

        $argusApiOperations->execute();

        $sentRequest = $this->sentRequest($argusApiOperations);
        self::assertSame('https://gateway.example.test/service/items', (string)$sentRequest->getUri());
        self::assertSame('own-key', $sentRequest->getHeaderLine('x-api-key'));
        self::assertSame('de', $sentRequest->getHeaderLine('Accept-Language'));
        self::assertSame('application/json', $sentRequest->getHeaderLine('Content-Type'));
        self::assertSame('{"name":"value"}', (string)$sentRequest->getBody());
    }

    public function testTheTrackingHeaderIsConfigurable(): void
    {
        $argusApiOperations = new TransportCapturingArgusApiOperations(new Response(200, [], '{}'));
        $this->addOperation($argusApiOperations, 'call-1', 'GET:/service/items');

        $argusApiOperations->execute();

        $sentRequest = $this->sentRequest($argusApiOperations);
        self::assertStringContainsString('requestUID=', $sentRequest->getHeaderLine('x-argus-tracking'));
        self::assertFalse($sentRequest->hasHeader(ArgusApiOperations::DEFAULT_TRACKING_HEADER));
    }

    public function testWithAuthSwitchedOffNoAuthorizationIsAddedAndACallsOwnIsKept(): void
    {
        $argusApiOperations = new TransportCapturingArgusApiOperations(new Response(200, [], '{}'), new Response(200, [], '{}'));
        $this->addOperation($argusApiOperations, 'call-1', 'GET:/service/items');
        $this->addOperation($argusApiOperations, 'call-2', 'GET:https://third-party.example.test/v1', [
            'headers' => ['Authorization' => 'Bearer third-party-token'],
        ]);

        $argusApiOperations->execute();

        $authorizationByUrl = [];
        foreach ($argusApiOperations->sentRequests as $sentRequestEntry) {
            $authorizationByUrl[(string)$sentRequestEntry['request']->getUri()] = $sentRequestEntry['request']->getHeaderLine('Authorization');
        }
        self::assertSame('', $authorizationByUrl['https://gateway.example.test/service/items']);
        self::assertSame('Bearer third-party-token', $authorizationByUrl['https://third-party.example.test/v1']);
    }

    public function testDebugFlagsAreAppendedOnlyWhenConfigured(): void
    {
        ArgusConfigOverride::apply(['appendDebugFlags' => true] + self::ARGUS_CONFIG);
        $argusApiOperations = new TransportCapturingArgusApiOperations(new Response(200, [], '{}'));
        $this->addOperation($argusApiOperations, 'call-1', 'GET:/service/items');

        $argusApiOperations->execute();

        self::assertSame('debug=1&errors_as_json=1', $this->sentRequest($argusApiOperations)->getUri()->getQuery());
    }

    public function testTheTimeoutReachesTheTransport(): void
    {
        $argusApiOperations = new TransportCapturingArgusApiOperations(new Response(200, [], '{}'));
        $this->addOperation($argusApiOperations, 'call-1', 'GET:/service/items');

        $argusApiOperations->execute(timeout: 7.5);

        self::assertSame(7.5, $argusApiOperations->sentRequests[0]['options']['timeout']);
    }

    public function testAnEndpointWithoutRequestMethodRejectsTheBatchBeforeAnyRequestIsSent(): void
    {
        $argusApiOperations = new TransportCapturingArgusApiOperations(new Response(200, [], '{}'), new Response(200, [], '{}'));
        $this->addOperation($argusApiOperations, 'call-1', 'GET:/service/items');
        $this->addOperation($argusApiOperations, 'call-2', 'LegacyModule::legacyCall');

        try {
            $argusApiOperations->execute();
            self::fail('an endpoint without request method must be rejected');
        } catch (InvalidArgumentException $invalidArgumentException) {
            self::assertStringContainsString('LegacyModule::legacyCall', $invalidArgumentException->getMessage());
        }
        self::assertSame([], $argusApiOperations->sentRequests);
    }

    public function testAnEndpointWithoutRequestMethodRejectsTheSplitExecutionBeforeAnyRequestIsSent(): void
    {
        $argusApiOperations = new TransportCapturingArgusApiOperations(new Response(200, [], '{}'));
        $this->addOperation($argusApiOperations, 'call-1', 'GET:/service/items');
        $this->addOperation($argusApiOperations, 'call-2', '/service/without-method');

        $this->expectException(InvalidArgumentException::class);
        try {
            $argusApiOperations->sendExecution();
        } finally {
            self::assertSame([], $argusApiOperations->sentRequests);
        }
    }

    public function testStatusAndHeadersReachTheOperation(): void
    {
        $argusApiOperations = new TransportCapturingArgusApiOperations(new Response(404, ['X-Request-Id' => 'abc'], '{"message":"not here"}'));
        $argusProbeEntity = new ArgusProbeEntity();
        $argusApiOperation = $argusProbeEntity->createOperation('call-1', 'GET:/service/items');
        $argusApiOperations->addOperation($argusApiOperation);

        $argusApiOperations->execute();

        self::assertSame(404, $argusApiOperation->responseStatusCode);
        self::assertSame(['abc'], $argusApiOperation->responseHeaders['X-Request-Id']);
        self::assertSame('not here', $argusProbeEntity->receivedResults->message);
    }

    public function testTheSettleModeKeepsTheFulfilledCallsWhenOneIsRejected(): void
    {
        $argusApiOperations = new TransportCapturingArgusApiOperations(
            new Response(200, [], '{"status":"OK","data":{"value":1}}'),
            new ConnectException('connection refused', new Request('GET', 'https://gateway.example.test/service/b'))
        );
        $fulfilledProbeEntity = new ArgusProbeEntity();
        $fulfilledOperation = $fulfilledProbeEntity->createOperation('call-a', 'GET:/service/a');
        $argusApiOperations->addOperation($fulfilledOperation);
        $rejectedProbeEntity = new ArgusProbeEntity();
        $rejectedOperation = $rejectedProbeEntity->createOperation('call-b', 'GET:/service/b');
        $argusApiOperations->addOperation($rejectedOperation);

        $argusApiOperations->execute(keepFulfilledCallsWhenOneIsRejected: true);

        self::assertSame(1, $fulfilledProbeEntity->receivedResults->data->value);
        self::assertNull($fulfilledOperation->rejectionReason);
        self::assertTrue($rejectedProbeEntity->responseHandled);
        self::assertNull($rejectedProbeEntity->receivedResults);
        self::assertStringContainsString('connection refused', (string)$rejectedOperation->rejectionReason);
    }

    public function testWithoutTheSettleModeARejectedCallFailsTheBatch(): void
    {
        $argusApiOperations = new TransportCapturingArgusApiOperations(
            new Response(200, [], '{}'),
            new ConnectException('connection refused', new Request('GET', 'https://gateway.example.test/service/b'))
        );
        $this->addOperation($argusApiOperations, 'call-a', 'GET:/service/a');
        $this->addOperation($argusApiOperations, 'call-b', 'GET:/service/b');

        $this->expectException(ConnectException::class);
        $argusApiOperations->execute();
    }

    public function testThePrivateTransportReadsWithinItsByteLimitAndIsNotLogged(): void
    {
        ArgusLoad::$logArgusCalls = true;
        $argusApiOperations = new TransportCapturingArgusApiOperations(new Response(200, ['Content-Length' => '5'], 'bytes'));
        $argusProbeEntity = $this->addOperation($argusApiOperations, 'call-private', 'GET:/service/image', [
            ArgusApiOperation::PRIVATE_TRANSPORT => ['response' => 'binary', 'max_response_bytes' => 16],
        ]);

        $argusApiOperations->execute();

        self::assertSame(['body' => 'bytes'], $argusProbeEntity->receivedResults);
        self::assertFalse($argusApiOperations->sentRequests[0]['options']['allow_redirects']);
        self::assertArrayNotHasKey(ArgusApiOperation::PRIVATE_TRANSPORT, $argusApiOperations->sentRequests[0]['options']);
        self::assertSame([], ArgusApiOperations::getExecutedArgusCalls());
    }

    public function testThePrivateTransportRejectsAResponseOverItsByteLimit(): void
    {
        $argusApiOperations = new TransportCapturingArgusApiOperations(new Response(200, [], str_repeat('x', 40)));
        $this->addOperation($argusApiOperations, 'call-private', 'GET:/service/image', [
            ArgusApiOperation::PRIVATE_TRANSPORT => ['response' => 'binary', 'max_response_bytes' => 16],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('byte limit');
        $argusApiOperations->execute();
    }

    public function testInvalidPrivateTransportOptionsAreRejected(): void
    {
        $argusProbeEntity = new ArgusProbeEntity();

        $this->expectException(InvalidArgumentException::class);
        $argusProbeEntity->createOperation('call-private', 'GET:/service/image', [
            ArgusApiOperation::PRIVATE_TRANSPORT => ['response' => 'binary', 'max_response_bytes' => 0],
        ]);
    }

    public function testASplitExecutionSendsFirstAndHandsTheAnswersOverOnReceive(): void
    {
        $argusApiOperations = new TransportCapturingArgusApiOperations(new Response(200, [], '{"status":"OK","data":{"value":2}}'));
        $argusProbeEntity = $this->addOperation($argusApiOperations, 'call-1', 'GET:/service/items');

        self::assertTrue($argusApiOperations->sendExecution());
        self::assertCount(1, $argusApiOperations->sentRequests);
        self::assertFalse($argusProbeEntity->responseHandled);

        $argusApiOperations->advanceExecution();
        $argusApiOperations->receiveExecution();

        self::assertTrue($argusProbeEntity->responseHandled);
        self::assertSame(2, $argusProbeEntity->receivedResults->data->value);
    }

    public function testTheCallJournalRecordsOnlyWhileSwitchedOn(): void
    {
        $argusApiOperations = new TransportCapturingArgusApiOperations(new Response(200, [], '{}'), new Response(201, [], '{}'));
        $this->addOperation($argusApiOperations, 'call-1', 'GET:/service/one?x=1');
        $argusApiOperations->execute();
        self::assertSame(0, ArgusApiOperations::getCallJournal()->count());

        ArgusApiOperations::$journalRequests = true;
        $secondArgusApiOperations = new TransportCapturingArgusApiOperations(new Response(201, [], '{}'));
        $this->addOperation($secondArgusApiOperations, 'call-2', 'GET:/service/two?x=1');
        $secondArgusApiOperations->execute();

        $callJournal = ArgusApiOperations::getCallJournal();
        self::assertSame(1, $callJournal->count());
        self::assertSame('GET:/service/two', $callJournal->first()->endpoint);
        self::assertSame(201, $callJournal->first()->statusCode);
        self::assertSame(1, ArgusApiOperations::getSentCallCountsByEndpoint()['GET:/service/two?x=1'] ?? 0);
    }
}
