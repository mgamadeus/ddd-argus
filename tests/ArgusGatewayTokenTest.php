<?php

declare(strict_types=1);

namespace DDD\Tests\Argus;

use DDD\Domain\Base\Repo\Argus\Attributes\ArgusLoad;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusApiOperations;
use DDD\Tests\Argus\Support\ArgusConfigOverride;
use DDD\Tests\Argus\Support\ArgusProbeEntity;
use DDD\Tests\Argus\Support\TransportCapturingArgusApiOperations;
use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use ReflectionProperty;
use RuntimeException;

/**
 * A gateway's token header (`Base.Repo.argus.gateways`) goes only to calls whose final URL has the gateway's origin.
 */
class ArgusGatewayTokenTest extends TestCase
{
    protected const string TOKEN_ENV_NAME = 'ARGUS_TEST_GATEWAY_TOKEN';

    protected const string TOKEN = 'gateway-secret-4711';

    protected const array ARGUS_CONFIG = [
        'microservices' => ['baseUrl' => 'https://secure.gateway.example.test/'],
        'auth' => false,
        'appendDebugFlags' => false,
        'trackingHeader' => 'x-argus-tracking',
        'gateways' => [
            'secure' => [
                'origin' => 'https://secure.gateway.example.test',
                'tokenHeader' => 'X-Gateway-Token',
                'tokenEnvName' => self::TOKEN_ENV_NAME,
            ],
        ],
    ];

    protected string $errorLogFile;

    protected string|false $errorLogBefore;

    protected function setUp(): void
    {
        ArgusConfigOverride::apply(self::ARGUS_CONFIG);
        ArgusApiOperations::resetForNewJob();
        ArgusLoad::$logArgusCalls = false;
        $_ENV[self::TOKEN_ENV_NAME] = self::TOKEN;
        (new ReflectionProperty(ArgusApiOperations::class, 'loggedEmptyGatewayTokenEnvNames'))->setValue(null, []);
        $this->errorLogFile = (string)tempnam(sys_get_temp_dir(), 'argus-gateway-log-');
        $this->errorLogBefore = ini_get('error_log');
        ini_set('error_log', $this->errorLogFile);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->errorLogBefore === false ? '' : $this->errorLogBefore);
        @unlink($this->errorLogFile);
        unset($_ENV[self::TOKEN_ENV_NAME]);
        (new ReflectionProperty(ArgusApiOperations::class, 'loggedEmptyGatewayTokenEnvNames'))->setValue(null, []);
        ArgusConfigOverride::restore();
        ArgusApiOperations::resetForNewJob();
        ArgusLoad::$logArgusCalls = false;
    }

    protected function addOperation(TransportCapturingArgusApiOperations $argusApiOperations, string $id, string $endpoint, array $payload = []): void
    {
        $argusProbeEntity = new ArgusProbeEntity();
        $argusApiOperation = $argusProbeEntity->createOperation($id, $endpoint, $payload);
        $argusApiOperations->addOperation($argusApiOperation);
    }

    /** @return RequestInterface[] The sent requests by URL */
    protected function sentRequestsByUrl(TransportCapturingArgusApiOperations $argusApiOperations): array
    {
        $sentRequestsByUrl = [];
        foreach ($argusApiOperations->sentRequests as $sentRequestEntry) {
            $sentRequestsByUrl[(string)$sentRequestEntry['request']->getUri()] = $sentRequestEntry['request'];
        }
        return $sentRequestsByUrl;
    }

    public function testTheTokenGoesOnlyToCallsOfTheGatewayOrigin(): void
    {
        $argusApiOperations = new TransportCapturingArgusApiOperations(
            new Response(200, [], '{}'),
            new Response(200, [], '{}'),
            new Response(200, [], '{}'),
            new Response(200, [], '{}')
        );
        $this->addOperation($argusApiOperations, 'relative', 'GET:/service/items');
        $this->addOperation($argusApiOperations, 'absolute', 'GET:https://SECURE.gateway.example.test:443/service/other');
        $this->addOperation($argusApiOperations, 'third-party', 'POST:https://third-party.example.test/v1/chat');
        $this->addOperation($argusApiOperations, 'other-port', 'GET:https://secure.gateway.example.test:8443/service/items');

        $argusApiOperations->execute();

        $sentRequestsByUrl = $this->sentRequestsByUrl($argusApiOperations);
        self::assertSame(self::TOKEN, $sentRequestsByUrl['https://secure.gateway.example.test/service/items']->getHeaderLine('X-Gateway-Token'));
        self::assertSame(self::TOKEN, $sentRequestsByUrl['https://secure.gateway.example.test/service/other']->getHeaderLine('X-Gateway-Token'));
        self::assertFalse($sentRequestsByUrl['https://third-party.example.test/v1/chat']->hasHeader('X-Gateway-Token'));
        self::assertFalse($sentRequestsByUrl['https://secure.gateway.example.test:8443/service/items']->hasHeader('X-Gateway-Token'));
        self::assertStringNotContainsString(self::TOKEN, $sentRequestsByUrl['https://secure.gateway.example.test/service/items']->getHeaderLine('x-argus-tracking'));
    }

    public function testACallsOwnTokenHeaderIsKept(): void
    {
        $argusApiOperations = new TransportCapturingArgusApiOperations(new Response(200, [], '{}'));
        $this->addOperation($argusApiOperations, 'own', 'GET:/service/items', ['headers' => ['x-gateway-token' => 'call-token']]);

        $argusApiOperations->execute();

        self::assertSame('call-token', $argusApiOperations->sentRequests[0]['request']->getHeaderLine('X-Gateway-Token'));
    }

    public function testAnEmptyTokenVariableSendsNothingAndIsLoggedOnceByName(): void
    {
        $_ENV[self::TOKEN_ENV_NAME] = '';
        $argusApiOperations = new TransportCapturingArgusApiOperations(new Response(200, [], '{}'), new Response(200, [], '{}'));
        $this->addOperation($argusApiOperations, 'first', 'GET:/service/one');
        $this->addOperation($argusApiOperations, 'second', 'GET:/service/two');

        $argusApiOperations->execute();

        foreach ($argusApiOperations->sentRequests as $sentRequestEntry) {
            self::assertFalse($sentRequestEntry['request']->hasHeader('X-Gateway-Token'));
        }
        $logContent = (string)file_get_contents($this->errorLogFile);
        self::assertSame(1, substr_count($logContent, self::TOKEN_ENV_NAME));
    }

    public function testAGatewayCallFollowsNoRedirectToAnotherOrigin(): void
    {
        $argusApiOperations = new TransportCapturingArgusApiOperations(
            new Response(302, ['Location' => 'https://elsewhere.example.test/collect'], ''),
            new Response(200, [], '{}')
        );
        $this->addOperation($argusApiOperations, 'redirected', 'GET:/service/items');

        try {
            $argusApiOperations->execute();
            self::fail('a redirect to another origin must not be followed');
        } catch (RuntimeException $runtimeException) {
            self::assertStringContainsString('redirect to another origin', $runtimeException->getMessage());
            self::assertStringNotContainsString(self::TOKEN, $runtimeException->getMessage());
        }
        self::assertSame(1, $argusApiOperations->mockHandler->count(), 'the redirect target was never requested');
        self::assertFalse($argusApiOperations->sentRequests[0]['options']['debug']);
    }

    public function testAGatewayCallFollowsARedirectWithinItsOrigin(): void
    {
        $argusApiOperations = new TransportCapturingArgusApiOperations(
            new Response(302, ['Location' => 'https://secure.gateway.example.test/service/moved'], ''),
            new Response(200, [], '{"status":"OK","data":{"value":1}}')
        );
        $argusProbeEntity = new ArgusProbeEntity();
        $argusApiOperation = $argusProbeEntity->createOperation('moved', 'GET:/service/items');
        $argusApiOperations->addOperation($argusApiOperation);

        $argusApiOperations->execute();

        self::assertSame(0, $argusApiOperations->mockHandler->count());
        self::assertSame(1, $argusProbeEntity->receivedResults->data->value);
    }

    public function testTheTokenStaysOutOfTheExecutedCallsLog(): void
    {
        ArgusLoad::$logArgusCalls = true;
        $argusApiOperations = new TransportCapturingArgusApiOperations(new Response(200, [], '{}'));
        $this->addOperation($argusApiOperations, 'logged', 'GET:/service/items');

        $argusApiOperations->execute();

        self::assertNotSame([], ArgusApiOperations::getExecutedArgusCalls());
        self::assertStringNotContainsString(self::TOKEN, json_encode(ArgusApiOperations::getExecutedArgusCalls()));
    }

    public function testAnInvalidGatewayRejectsTheBatchBeforeAnyRequestIsSent(): void
    {
        foreach (
            [
                ['origin' => 'https://secure.gateway.example.test/path', 'tokenHeader' => 'X-Gateway-Token', 'tokenEnvName' => 'X'],
                ['origin' => 'secure.gateway.example.test', 'tokenHeader' => 'X-Gateway-Token', 'tokenEnvName' => 'X'],
                ['origin' => 'https://secure.gateway.example.test', 'tokenHeader' => 'X Gateway', 'tokenEnvName' => 'X'],
                ['origin' => 'https://secure.gateway.example.test', 'tokenHeader' => 'X-Gateway-Token', 'tokenEnvName' => ''],
            ] as $invalidGateway
        ) {
            ArgusConfigOverride::apply(['gateways' => ['broken' => $invalidGateway]] + self::ARGUS_CONFIG);
            $argusApiOperations = new TransportCapturingArgusApiOperations(new Response(200, [], '{}'));
            $this->addOperation($argusApiOperations, 'never-sent', 'GET:/service/items');
            try {
                $argusApiOperations->execute();
                self::fail('an invalid gateway must reject the batch: ' . json_encode($invalidGateway));
            } catch (InvalidArgumentException $invalidArgumentException) {
                self::assertStringContainsString('"broken"', $invalidArgumentException->getMessage());
            }
            self::assertSame([], $argusApiOperations->sentRequests);
        }
    }
}
