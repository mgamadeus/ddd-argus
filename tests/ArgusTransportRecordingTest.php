<?php

declare(strict_types=1);

namespace DDD\Tests\Argus;

use DDD\Domain\Base\Repo\Argus\Utils\ArgusApiOperations;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusTransportRecording;
use DDD\Domain\Base\Repo\Argus\Utils\RecordedRunIdCounterparts;
use DDD\Tests\Argus\Support\ArgusConfigOverride;
use DDD\Tests\Argus\Support\ArgusProbeEntity;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/** A recording made through the executor plays back the same answers, from its stored form */
class ArgusTransportRecordingTest extends TestCase
{
    protected function setUp(): void
    {
        ArgusConfigOverride::apply(['microservices' => ['baseUrl' => 'https://gateway.example.test'], 'auth' => false, 'appendDebugFlags' => false]);
    }

    protected function tearDown(): void
    {
        ArgusConfigOverride::restore();
        ArgusApiOperations::resetForNewJob();
    }

    /** @return ArgusProbeEntity[] the probes of the two operations, after the execution */
    protected function executeTwoCalls(): array
    {
        $argusApiOperations = new ArgusApiOperations();
        $firstProbeEntity = new ArgusProbeEntity();
        $firstProbeEntity->callKind = 'KIND.FIRST';
        $firstOperation = $firstProbeEntity->createOperation('call-1', 'POST:/service/first', ['body' => ['question' => 1]]);
        $argusApiOperations->addOperation($firstOperation);
        $secondProbeEntity = new ArgusProbeEntity();
        $secondOperation = $secondProbeEntity->createOperation('call-2', 'GET:/service/second');
        $argusApiOperations->addOperation($secondOperation);
        $argusApiOperations->execute(keepFulfilledCallsWhenOneIsRejected: true);
        return [$firstProbeEntity, $secondProbeEntity];
    }

    public function testARecordingPlaysTheRecordedAnswersBackFromItsStoredForm(): void
    {
        $recordingTransport = ArgusTransportRecording::forRecording(new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], '{"status":"OK","data":{"answer":"first"}}'),
            new ConnectException('timed out', new Request('GET', 'https://gateway.example.test/service/second')),
        ]));
        ArgusApiOperations::$transportRecording = $recordingTransport;
        $this->executeTwoCalls();
        $storedEntries = json_decode((string)json_encode($recordingTransport->toStorageArray()), true);

        self::assertCount(2, $storedEntries);
        self::assertSame('KIND.FIRST|ArgusProbeEntity', $storedEntries[0]['kind']);
        self::assertSame(ConnectException::class, $storedEntries[1]['transportFailure']['class']);

        $playingTransport = ArgusTransportRecording::forPlaying($storedEntries);
        ArgusApiOperations::$transportRecording = $playingTransport;
        [$firstProbeEntity, $secondProbeEntity] = $this->executeTwoCalls();

        self::assertSame('first', $firstProbeEntity->receivedResults->data->answer);
        self::assertTrue($secondProbeEntity->responseHandled);
        self::assertNull($secondProbeEntity->receivedResults);
        self::assertSame([], $playingTransport->unusedEntries());
        self::assertSame([], $playingTransport->buildChangedRequestBodies());
        self::assertSame(2, $playingTransport->playedRequests->count());
    }

    public function testAPlayedCallWithoutARecordedAnswerFails(): void
    {
        ArgusApiOperations::$transportRecording = ArgusTransportRecording::forPlaying([]);
        $argusApiOperations = new ArgusApiOperations();
        $argusProbeEntity = new ArgusProbeEntity();
        $argusApiOperation = $argusProbeEntity->createOperation('call-1', 'GET:/service/unrecorded');
        $argusApiOperations->addOperation($argusApiOperation);

        $argusApiOperations->execute(keepFulfilledCallsWhenOneIsRejected: true);

        self::assertStringContainsString('no recorded answer left', (string)$argusApiOperation->rejectionReason);
    }

    public function testIdCounterpartsTranslateBetweenReplayAndRecording(): void
    {
        $recordedRunIdCounterparts = new RecordedRunIdCounterparts();
        $recordedRunIdCounterparts->addCounterpart(7000001, 5000001);

        self::assertSame('/items/5000001?n=12', $recordedRunIdCounterparts->translateReplayTextToRecorded('/items/7000001?n=12'));
        self::assertSame('{"id":7000001,"v":1.5000001}', $recordedRunIdCounterparts->translateRecordedTextToReplay('{"id":5000001,"v":1.5000001}'));
    }
}
