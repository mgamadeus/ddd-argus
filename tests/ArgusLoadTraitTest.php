<?php

declare(strict_types=1);

namespace DDD\Tests\Argus;

use DDD\Domain\Base\Repo\Argus\Utils\ArgusApiOperations;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusCache;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusTransportRecording;
use DDD\Infrastructure\Cache\Cache;
use DDD\Tests\Argus\Support\ArgusCachedProbe;
use DDD\Tests\Argus\Support\ArgusConfigOverride;
use DDD\Tests\Argus\Support\ArgusEnvelopeProbe;
use DDD\Tests\Argus\Support\ArgusTimeoutFromAttributeProbe;
use DDD\Tests\Argus\Support\ArgusTimeoutFromConstantProbe;
use DDD\Tests\Argus\Support\InMemoryRecordingCache;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/** Loading through ArgusLoadTrait: timeouts, cache writes and TTLs, the response envelopes */
class ArgusLoadTraitTest extends TestCase
{
    /** @var InMemoryRecordingCache[] */
    protected array $recordingCaches = [];

    protected function setUp(): void
    {
        $this->recordingCaches = InMemoryRecordingCache::installForArgusCacheGroups();
        ArgusConfigOverride::apply(['microservices' => ['baseUrl' => 'https://gateway.example.test'], 'auth' => false, 'appendDebugFlags' => false]);
    }

    protected function tearDown(): void
    {
        InMemoryRecordingCache::uninstall();
        ArgusConfigOverride::restore();
        ArgusApiOperations::resetForNewJob();
    }

    public function testTheAttributeTimeoutWinsOverTheClassDefault(): void
    {
        self::assertSame(12.0, (new ArgusTimeoutFromAttributeProbe())->getArgusSettings()->timeout);
    }

    public function testTheClassDefaultTimeoutAppliesWithoutAnAttributeTimeout(): void
    {
        self::assertSame(30.0, (new ArgusTimeoutFromConstantProbe())->getArgusSettings()->timeout);
    }

    public function testEveryInstanceWorksOnItsOwnCopyOfTheAttribute(): void
    {
        $firstProbe = new ArgusTimeoutFromConstantProbe();
        $firstProbe->getArgusSettings()->argusLoad->loadEndpoint = 'GET:/service/changed';

        self::assertSame('GET:/service/timeout', (new ArgusTimeoutFromConstantProbe())->getArgusSettings()->getLoadEndpoint());
    }

    public function testArgusCachePassesTheTtlToBothLevels(): void
    {
        ArgusCache::set('ttl-probe', 'value', ArgusCache::CACHE_TTL_ONE_DAY, ArgusCache::CACHELEVEL_MEMORY_AND_DB);

        $cacheKey = ArgusCache::getCacheKey('ttl-probe');
        self::assertSame(ArgusCache::CACHE_TTL_ONE_HOUR, $this->recordingCaches[Cache::CACHE_GROUP_APC]->ttlsByKey[$cacheKey]);
        self::assertSame(ArgusCache::CACHE_TTL_ONE_DAY, $this->recordingCaches[Cache::CACHE_GROUP_REDIS_SENTINEL]->ttlsByKey[$cacheKey]);
    }

    public function testASuccessfulLoadIsCachedWithTheDeclaredTtl(): void
    {
        ArgusApiOperations::$transportRecording = ArgusTransportRecording::forRecording(
            new MockHandler([new Response(200, [], '{"status":"OK","data":{"value":"loaded"}}')])
        );
        $argusCachedProbe = new ArgusCachedProbe();
        $argusCachedProbe->probeKey = 'success';

        $argusCachedProbe->argusLoad();

        self::assertSame('loaded', $argusCachedProbe->loadedValue);
        $cacheKey = ArgusCache::getCacheKey($argusCachedProbe->cacheKey());
        self::assertNotEmpty($this->recordingCaches[Cache::CACHE_GROUP_REDIS_SENTINEL]->valuesByKey[$cacheKey] ?? null);
        self::assertSame(ArgusCache::CACHE_TTL_TEN_MINUTES, $this->recordingCaches[Cache::CACHE_GROUP_REDIS_SENTINEL]->ttlsByKey[$cacheKey]);
    }

    public function testANon2xxAnswerIsNotCached(): void
    {
        ArgusApiOperations::$transportRecording = ArgusTransportRecording::forRecording(
            new MockHandler([new Response(503, [], '{"message":"upstream down"}')])
        );
        $argusCachedProbe = new ArgusCachedProbe();
        $argusCachedProbe->probeKey = 'failure';

        $argusCachedProbe->argusLoad();

        self::assertNull($argusCachedProbe->loadedValue);
        self::assertFalse($argusCachedProbe->getArgusSettings()->isLoadedSuccessfully);
        $cacheKey = ArgusCache::getCacheKey($argusCachedProbe->cacheKey());
        self::assertArrayNotHasKey($cacheKey, $this->recordingCaches[Cache::CACHE_GROUP_APC]->valuesByKey);
        self::assertArrayNotHasKey($cacheKey, $this->recordingCaches[Cache::CACHE_GROUP_REDIS_SENTINEL]->valuesByKey);
    }

    public function testTheNon2xxReasonNamesTheStatusAndTheUpstreamMessage(): void
    {
        $callResponseData = (object)['status' => 403, 'message' => 'no access'];

        self::assertSame(
            'http 403 from the upstream call: no access',
            ArgusCachedProbe::rejectionReasonForNon2xxAnswer($callResponseData, null)
        );
        self::assertNull(ArgusCachedProbe::rejectionReasonForNon2xxAnswer((object)['status' => 'OK'], null));
    }

    public function testTheResponseEnvelopesAreRead(): void
    {
        $argusEnvelopeProbe = new ArgusEnvelopeProbe();

        self::assertSame('a', $argusEnvelopeProbe->readResponseData((object)['status' => 'result', 'data' => 'a']));
        $responsesPayload = (object)['object' => 'response', 'output' => []];
        self::assertSame($responsesPayload, $argusEnvelopeProbe->readResponseData($responsesPayload));
        $chatCompletionsPayload = (object)['choices' => []];
        self::assertSame($chatCompletionsPayload, $argusEnvelopeProbe->readResponseData($chatCompletionsPayload));
        self::assertNull($argusEnvelopeProbe->readResponseData((object)['status' => 500, 'data' => 'x']));
    }
}
