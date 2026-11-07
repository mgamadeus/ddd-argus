<?php

declare (strict_types=1);

namespace DDD\Domain\Base\Repo\Argus\Utils;

use DDD\Domain\Base\Repo\Argus\Attributes\ArgusLoad;
use DDD\Domain\Base\Repo\Argus\Enums\ArgusApiOperationType;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\AuthService;
use DDD\Domain\Base\Entities\MessageHandlers\AppMessageHandler;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Reflection\ClassWithNamespace;
use DDD\Infrastructure\Reflection\ReflectionClass;
use DDD\Infrastructure\Traits\Serializer\SerializerTrait;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\TransferStats;
use InvalidArgumentException;
use LogicException;
use Psr\Http\Message\ResponseInterface;
use ReflectionException;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

/**
 * One batch of Argus API calls: collects the operations of a load (merging what may be merged), sends them in parallel
 * and hands every answer to the operation(s) it belongs to. A per-batch object created with `new` — never a container
 * service, since two loads must never share one batch.
 *
 * Configuration (`Base.Repo.argus` in the application config; the environment only where the config has no value):
 *
 * | Key | Meaning | Default |
 * |---|---|---|
 * | `microservices.baseUrl` | base URL for relative endpoints | env `ARGUS_API_ENDPOINT` |
 * | `requestSettings` | request options, e.g. `headers` (merged over the built-in defaults) | env `ARGUS_REQUEST_SETTINGS` (JSON) |
 * | `auth` | send `Authorization: Bearer` of the default CLI account on every call that carries no own `Authorization` | true |
 * | `appendDebugFlags` | append `debug=1&errors_as_json=1` to every URL | env `APP_DEBUG` |
 * | `trackingHeader` | header carrying account id, request uid and call stack; `''` sends none | `rc-tracking` |
 *
 * Header order: the configured headers first, a call's own headers over them (a call's `Content-Type`, `x-api-key`,
 * `Authorization`, `Accept-Language` win).
 *
 * Every endpoint carries its request method (`GET:/path`, `POST:https://…`); an endpoint without one is rejected with
 * an {@see InvalidArgumentException} before any request of the batch is sent.
 */
class ArgusApiOperations
{
    use SerializerTrait;

    /** @var string The pattern an endpoint must match: the request method, a colon and the URL or path */
    public const string ENDPOINT_WITH_REQUEST_METHOD_PATTERN = '/^(?P<method>(GET|PUT|POST|DELETE|PATCH)):(?P<url>\S+)/';

    /** @var string The tracking header sent when the config names none */
    public const string DEFAULT_TRACKING_HEADER = 'rc-tracking';

    /** @var int The most journal entries kept ({@see getCallJournal()}); older ones are dropped */
    public const int CALL_JOURNAL_MAX_ENTRIES = 2000;

    /** @var int The most calls sent in one round; a larger batch is split into several rounds */
    public const int MAX_CALLS_PER_ROUND = 300;

    /** @var string The separator between endpoint and call id in the keys of a round's promises */
    protected const string ENDPOINT_TO_ID_DELIMITER = '___##___';

    /** @var ArgusApiOperation[]|null */
    public ?array $operations;

    /** @var array Operations by id; a merged call's id holds the list of operations merged into it */
    public array $operationsById;

    /** @var array Endpoint groups with merge limits and pending operations */
    public array $operationsGroupedByEndpoints;

    /** @var array Merged request options, grouped by endpoint */
    public array $operationsGroupedByEndpointsFinal;

    /** @var null */
    protected $dboperationsGroupedByHashes;

    /** @var array */
    protected array $dboperationsHashes;

    /** @var array Cache for executed Argus calls, can be displayed for debugging purposes by getExecutedArgusCalls */
    protected static array $exeutedArgusCalls = [];

    /**
     * @var int[] How often each endpoint (`METHOD:path`) was SENT in this process — cached operations never count.
     *      Never reset: a harness reads the difference around a case (e.g. the billable calls a run caused).
     */
    protected static array $sentCallCountsByEndpoint = [];

    /** @var string|null Cached request UID for the current request */
    protected static ?string $requestUid = null;

    /**
     * @var bool Whether sent requests are journalled ({@see getCallJournal()}). Off by default; switched on for the
     *      span a caller wants measured (e.g. one tool call) and off again afterwards.
     */
    public static bool $journalRequests = false;

    /** @var ArgusSentCallJournalEntries|null The requests sent while {@see $journalRequests} was on */
    protected static ?ArgusSentCallJournalEntries $callJournal = null;

    /**
     * @var ArgusTransportRecording|null While set, every Argus HTTP call of this process is recorded or played through
     *      it ({@see ArgusTransportRecording}); null in every normal run
     */
    public static ?ArgusTransportRecording $transportRecording = null;

    /** @var string[][][] Response headers per call id of the round in progress */
    protected array $responseHeadersByCallId = [];

    /** @var int[] Response status codes per call id of the round in progress */
    protected array $responseStatusCodesByCallId = [];

    /** @var string[] Settle mode: the transport-level rejection reason per call id (no entry = fulfilled) */
    protected array $rejectionReasonsByCallId = [];

    /** The calls {@see sendExecution()} sent and {@see receiveExecution()} has not received yet */
    protected ?ArgusApiCallsInFlight $callsInFlight = null;

    public function __construct()
    {
        $this->operations = [];
        $this->operationsGroupedByEndpoints = [];
        $this->operationsGroupedByEndpointsFinal = [];
        $this->operationsById = [];
    }

    /**
     * The process state a worker job must not inherit from the previous one: the executed-calls log, the request uid
     * (it would stamp the previous job's uid on the next job's calls), a transport recording, the call journal.
     * The sent-call counts are kept on purpose.
     */
    public static function resetForNewJob(): void
    {
        self::$exeutedArgusCalls = [];
        self::$requestUid = null;
        self::$transportRecording = null;
        self::$journalRequests = false;
        self::$callJournal = null;
    }

    /** Whether an endpoint carries its request method (`GET:/path`, `POST:https://…`) */
    public static function isEndpointWithRequestMethod(string $endpoint): bool
    {
        return (bool)preg_match(self::ENDPOINT_WITH_REQUEST_METHOD_PATTERN, $endpoint);
    }

    /**
     * @param ArgusApiOperation $operation
     * @return void
     */
    public function addOperation(ArgusApiOperation &$operation)
    {
        if (isset($this->operationsById[$operation->id])) // keep sure, we are not adding duplicates
        {
            return;
        }
        $this->operations[] = $operation;
        $endpoint = $operation->endpoint;
        $id = $operation->id;
        if (!isset($this->operationsGroupedByEndpoints[$endpoint])) {
            $this->operationsGroupedByEndpoints[$endpoint] = [
                'operationsByGeneralParamsHash' => [],
                'mergelimit' => 1,
                'operations' => [],
            ];
        }
        if (!isset($this->operationsGroupedByEndpointsFinal[$endpoint])) {
            $this->operationsGroupedByEndpointsFinal[$endpoint] = [];
        }
        $this->operationsById[$id] = $operation;
        $this->operationsGroupedByEndpoints[$endpoint]['operations'][] = $operation;
        /**
         * handle merging of operations:
         * in some cases e.g. adwords data, it is possibile to split operations to every single element e.g. one adwords call per keyword,
         * but it is more efficient to merge many e.g. keywords to one adwords call
         */
        $this->operationsGroupedByEndpoints[$endpoint]['mergelimit'] = $operation->mergelimit;
        $generalParamsHash = md5(
            json_encode($operation->generalParams ?? null, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
        if (!isset($this->operationsGroupedByEndpoints[$endpoint]['operationsByGeneralParamsHash'][$generalParamsHash])) {
            $this->operationsGroupedByEndpoints[$endpoint]['operationsByGeneralParamsHash'][$generalParamsHash] = ['actMergeOutstandingOperations' => []];
        }
        $this->operationsGroupedByEndpoints[$endpoint]['operationsByGeneralParamsHash'][$generalParamsHash]['actMergeOutstandingOperations'][] = $operation;
        $this->handleOutstandingMerges($endpoint);
    }

    /**
     * handle merging of operations:
     * in some cases e.g. adwords data, it is possibile to split operations to every single element e.g. one adwords call per keyword,
     * but it is more efficient to merge many e.g. keywords to one adwords call
     * ###
     * Looks after outstanding operations, that have to be merged to one single endpoint call
     * @param $endpoint
     * @param bool $force if true, merges always
     */
    protected function handleOutstandingMerges(string $endpoint, bool $force = false): void
    {
        $endpointGroup = $this->operationsGroupedByEndpoints[$endpoint];
        foreach ($endpointGroup['operationsByGeneralParamsHash'] as $hash => $generalParamsHashgroup) {
            if (
                count($generalParamsHashgroup['actMergeOutstandingOperations']) && (count(
                        $generalParamsHashgroup['actMergeOutstandingOperations']
                    ) % $endpointGroup['mergelimit'] == 0 || $force)
            ) {//the last group is full, we have to create a new endpoint call
                $id = '';
                $mergedParams = [];
                if ($generalParamsHashgroup['actMergeOutstandingOperations'][0]->generalParams ?? false) {
                    $mergedParams = array_merge_recursive(
                        $mergedParams,
                        $generalParamsHashgroup['actMergeOutstandingOperations'][0]->generalParams
                    );
                }
                foreach ($generalParamsHashgroup['actMergeOutstandingOperations'] as $outstandingOperation) {
                    $id .= ($id ? '_' : '') . $outstandingOperation->id; // we concatenate the ids of every single operation to obtain the merged groups id
                    $mergedParams = array_merge_recursive(
                        $mergedParams,
                        $outstandingOperation->params
                    ); //we merge the parameters e.g. keywords
                }
                //general params are not merged recoursive since they are duplicated otherwise
                $mergedParams['id'] = md5($id);
                $this->operationsById[$mergedParams['id']] = $generalParamsHashgroup['actMergeOutstandingOperations'];
                $generalParamsHashgroup['actMergeOutstandingOperations'] = []; //we reset the outstanding operations array
                $this->operationsGroupedByEndpointsFinal[$endpoint][] = $mergedParams;
                // we write the settings back, since we operated on array copy and these changes are not applied
                $endpointGroup['operationsByGeneralParamsHash'][$hash] = $generalParamsHashgroup;
                $this->operationsGroupedByEndpoints[$endpoint] = $endpointGroup;
            }
        }
    }

    /**
     * Merges what is outstanding and rejects the batch when one of its endpoints carries no request method — before
     * anything is sent.
     *
     * @throws InvalidArgumentException
     */
    protected function prepareCallsForSending(): void
    {
        // first we have to resolve all the outanding merges, that are not combined into one endpoint call:
        // e.g. merge_limit 2 and 3 operations added => the last operation is still not in the endpoints_final
        foreach ($this->operationsGroupedByEndpoints as $endpoint => $ops) {
            $this->handleOutstandingMerges($endpoint, true);
        }
        foreach ($this->operationsGroupedByEndpointsFinal as $endpoint => $calls) {
            if ($calls && !self::isEndpointWithRequestMethod((string)$endpoint)) {
                throw new InvalidArgumentException(
                    'Argus endpoint "' . $endpoint . '" carries no request method (expected e.g. "GET:/path" or "POST:https://…"); the batch was not sent'
                );
            }
        }
    }

    /**
     * @param bool $displayCall
     * @param bool $displayResponse
     * @param bool $useApiACallCache
     * @param bool $logOperationCalls
     * @param ArgusApiOperationType $operationType
     * @param float $timeout
     * @param bool $keepFulfilledCallsWhenOneIsRejected settle mode, see {@see \DDD\Domain\Base\Repo\Argus\ArgusSettings::$keepFulfilledCallsWhenOneIsRejected}
     * @return void
     * @throws ReflectionException
     * @throws InternalErrorException
     * @throws InvalidArgumentException an endpoint without request method
     */
    public function execute(
        bool $displayCall = false,
        bool $displayResponse = false,
        bool $useApiACallCache = true,
        bool $logOperationCalls = true,
        ArgusApiOperationType $operationType = ArgusApiOperationType::LOAD,
        float $timeout = 0,
        bool $keepFulfilledCallsWhenOneIsRejected = false
    ): void {
        $this->prepareCallsForSending();
        //we need to split the calls, if they are too big:
        // first we count the calls
        $totalOperations = 0;

        if ($displayCall) {
            echo json_encode(
                $this->redactPrivateCalls($this->operationsGroupedByEndpointsFinal),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
            return;
        }
        foreach ($this->operationsGroupedByEndpointsFinal as $endpoint) {
            foreach ($endpoint as $call) {
                $totalOperations++;
            }
        }
        $numApiCalls = ceil($totalOperations / self::MAX_CALLS_PER_ROUND);
        for ($i = 0; $i < $numApiCalls; $i++) {
            $actCall = null;
            if ($numApiCalls > 1) {
                $actCall = [];
                foreach ($this->operationsGroupedByEndpointsFinal as $endPoint => $callData) {
                    foreach ($callData as $index => $call) {
                        if ($index % $numApiCalls == $i) {
                            if (!isset($actCall[$endPoint])) {
                                $actCall[$endPoint] = [];
                            }
                            $actCall[$endPoint][] = $call;
                        }
                    }
                }
            } else {
                $actCall = $this->operationsGroupedByEndpointsFinal;
            }

            if (!$actCall || !count($actCall)) {//nothing to load
                return;
            }

            $results = $this->callArgusApi(
                $actCall,
                $useApiACallCache,
                $logOperationCalls,
                $timeout,
                $keepFulfilledCallsWhenOneIsRejected
            );

            if ($displayResponse) {
                foreach ($results as &$responses) {
                    foreach ($responses as $callId => &$response) {
                        if ($this->findPrivateOperation((string)$callId)) {
                            $response = ['redacted' => true];
                        }
                    }
                    unset($response);
                }
                unset($responses);
                echo json_encode($results, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                return;
            }

            $this->handResultsToOperations($results, $operationType);
            unset($actCall);
        }
    }

    /**
     * The first half of {@see execute()} for a caller that has work to do while the answers travel: merge, send every
     * call and return at once; {@see advanceExecution()} moves the transfers forward between the caller's own steps,
     * {@see receiveExecution()} waits for the answers and hands them to the operations. Only for an execution of ONE
     * call round outside a recorded run; false = nothing was sent and the caller runs {@see execute()} instead. True
     * with nothing to send is an empty execution (receiving it does nothing).
     *
     * @throws InternalErrorException
     * @throws InvalidArgumentException an endpoint without request method
     */
    public function sendExecution(
        ArgusApiOperationType $operationType = ArgusApiOperationType::LOAD,
        float $timeout = 0,
        bool $keepFulfilledCallsWhenOneIsRejected = false
    ): bool {
        if (self::$transportRecording !== null || $this->callsInFlight !== null) {
            return false;
        }
        $this->prepareCallsForSending();
        $totalOperations = 0;
        foreach ($this->operationsGroupedByEndpointsFinal as $calls) {
            $totalOperations += count($calls);
        }
        if ($totalOperations > self::MAX_CALLS_PER_ROUND) {
            return false; // more than one call round
        }
        if ($totalOperations === 0) {
            return true;
        }
        // select_timeout 1 ms: a tick between the caller's steps never blocks it for longer
        $curlMultiHandler = new CurlMultiHandler(['select_timeout' => 0.001]);
        $this->callsInFlight = $this->sendArgusApiCalls(
            $this->operationsGroupedByEndpointsFinal,
            $this->createHttpClient($curlMultiHandler),
            $timeout,
            $keepFulfilledCallsWhenOneIsRejected,
            tracksRequestWrites: true
        );
        $this->callsInFlight->operationType = $operationType;
        $this->callsInFlight->curlMultiHandler = $curlMultiHandler;
        $curlMultiHandler->tick(); // resolve and connect now
        return true;
    }

    /** Move the transfers of {@see sendExecution()} forward without waiting (a PHP process moves them only when asked) */
    public function advanceExecution(): void
    {
        $this->callsInFlight?->curlMultiHandler?->tick();
    }

    /**
     * Move the transfers of {@see sendExecution()} forward until every request is fully written (the connection, the
     * TLS handshake and the request bytes need ticks; the remote side then works without this process), every call
     * has settled or `$maxSeconds` passed. Returns whether every request is written.
     */
    public function advanceExecutionUntilRequestsWritten(float $maxSeconds): bool
    {
        $argusApiCallsInFlight = $this->callsInFlight;
        if ($argusApiCallsInFlight?->curlMultiHandler === null) {
            return false;
        }
        $giveUpAtHr = hrtime(true) + $maxSeconds * 1e9;
        while (!$argusApiCallsInFlight->areAllRequestsWritten() && !$argusApiCallsInFlight->areAllCallsSettled() && hrtime(true) < $giveUpAtHr) {
            $argusApiCallsInFlight->curlMultiHandler->tick();
        }
        return $argusApiCallsInFlight->areAllRequestsWritten();
    }

    /** When the last request of {@see sendExecution()} was written; null while one is not written yet */
    public function findRequestsWrittenAtHr(): int|float|null
    {
        return $this->callsInFlight?->findAllRequestsWrittenAtHr();
    }

    /**
     * Wait for the answers of {@see sendExecution()} and hand them to the operations; nothing when nothing is in flight.
     * With `$deadlineHr` (an hrtime) the wait ends there: the calls still pending are cancelled and answer as rejected
     * calls (a null answer with its reason in the settle mode).
     */
    public function receiveExecution(int|float|null $deadlineHr = null): void
    {
        $argusApiCallsInFlight = $this->callsInFlight;
        if ($argusApiCallsInFlight === null) {
            return;
        }
        $this->callsInFlight = null;
        if ($deadlineHr !== null && $argusApiCallsInFlight->curlMultiHandler !== null) {
            while (!$argusApiCallsInFlight->areAllCallsSettled() && hrtime(true) < $deadlineHr) {
                $argusApiCallsInFlight->curlMultiHandler->tick();
            }
            $argusApiCallsInFlight->cancelPendingCalls();
        }
        $this->handResultsToOperations(
            $this->receiveArgusApiCalls($argusApiCallsInFlight),
            $argusApiCallsInFlight->operationType
        );
    }

    /**
     * Hand every received result to the operation(s) it answers — a merged call answers each operation merged into it —
     * with the call's headers, status and rejection reason.
     */
    protected function handResultsToOperations(array|object $results, ArgusApiOperationType $operationType): void
    {
        if (empty($results)) {
            return;
        }
        foreach ($results as $endpoint => $responses) {
            foreach ($responses as $id => $response) {
                if (!isset($this->operationsById[$id])) {
                    continue;
                }
                $operations = is_array($this->operationsById[$id]) ? $this->operationsById[$id] : [$this->operationsById[$id]];
                foreach ($operations as $operation) {
                    if (!$operation instanceof ArgusApiOperation) {
                        continue;
                    }
                    $operation->responseHeaders = $this->responseHeadersByCallId[$id] ?? null;
                    $operation->responseStatusCode = $this->responseStatusCodesByCallId[$id] ?? null;
                    $operation->rejectionReason = $this->rejectionReasonsByCallId[$id] ?? null;
                    $operation->handleResponse($response, $operationType);
                }
            }
        }
    }

    /**
     * Returns call stack as string with ClassName->method:line|ClassName->method:line ... ignoring some classes such as ArgusApiOperations
     * In case of message handlers returns MessageHandler:transport_name
     * In case of CLI commands returns CLI:command_name
     * For logging purposes
     * @param int $maxDept
     * @return string
     */
    public static function getCallStackAsString(int $maxDept = 7): string
    {
        $routeAndTrace = '';
        try {
            $requestService = DDDService::instance()->getRequestService();
            $request = $requestService->getRequestStack()->getMainRequest();
            $route = '';
            if ($request) {
                $route = $request->attributes->get('_route', 'N/A');
            }
            // Capture debug backtrace
            $callStackAsString = '';
            $debugBacktrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
            $classesToIgnore = [
                'ArgusApiOperations' => true,
                'HttpKernelRunner' => true,
                'Kernel' => true,
                'HttpKernel' => true,
                'Application' => true,
                'Command' => true,
                'ConsoleApplicationRunner' => true,
                'RejectRedeliveredMessageMiddleware' => true,
                'DispatchAfterCurrentBusMiddleware' => true,
                'FailedMessageProcessingMiddleware' => true,
                'SendMessageMiddleware' => true,
                'HandleMessageMiddleware' => true,
                'SyncTransport' => true,
                'RoutableMessageBus' => true,
                'TraceableMessageBus' => true,
                'MessageBus' => true,
                'TraceableMiddleware' => true,
                'AddBusNameStampMiddleware' => true,
            ];
            $currentLevel = 0;
            // we treat CLI commands and message handlers as entry points
            $cliCommand = null;
            $messageHandler = null;
            foreach ($debugBacktrace as $traceElement) {
                if (!isset($traceElement['class'])) {
                    continue;
                }
                /** @var string $className */
                $className = $traceElement['class'];
                $classWithNamespace = new ClassWithNamespace($className);
                if (isset($classesToIgnore[$classWithNamespace->name])) {
                    continue;
                }
                if (is_a($className, AppMessageHandler::class, true)) {
                    $messageHandlerReflectionClass = ReflectionClass::instance($className);
                    /** @var AsMessageHandler $asMessageHandlerAttribute */
                    $asMessageHandlerAttribute = $messageHandlerReflectionClass->getAttributeInstance(
                        AsMessageHandler::class
                    );
                    if ($asMessageHandlerAttribute && $asMessageHandlerAttribute->fromTransport) {
                        $messageHandler = $asMessageHandlerAttribute->fromTransport;
                    }
                } elseif (is_a($className, Command::class, true)) {
                    $commandReflectionClass = ReflectionClass::instance($className);
                    /** @var AsCommand $asCommandAttribute */
                    $asCommandAttribute = $commandReflectionClass->getAttributeInstance(AsCommand::class);
                    if ($asCommandAttribute && $asCommandAttribute->name) {
                        $cliCommand = $asCommandAttribute->name;
                    }
                }
                if ($currentLevel <= $maxDept) {
                    $line = $traceElement['line'] ?? 'N/A'; // Safely access the line key
                    $callStackAsString = $classWithNamespace->name . '->' . $traceElement['function'] . ':' . $line . ($callStackAsString ? '|' : '') . $callStackAsString;
                }
                $currentLevel++;
            }
            if ($messageHandler) {
                $routeAndTrace = 'MessageHandler:' . $messageHandler . '::' . $callStackAsString;
            } elseif ($cliCommand) {
                $routeAndTrace = 'CLI:' . $cliCommand . '::' . $callStackAsString;
            } else {
                $routeAndTrace = $route . '::' . $callStackAsString;
            }
        } catch (Throwable $t) {
            return $routeAndTrace;
        }
        return $routeAndTrace;
    }

    /**
     * Sends the calls of one round and waits for their answers, keyed by endpoint and call id.
     *
     * @throws InternalErrorException
     */
    protected function callArgusApi(
        #[\SensitiveParameter] array &$callData,
        bool $useCache = false,
        bool $logCall = true,
        float $timeout = 0,
        bool $keepFulfilledCallsWhenOneIsRejected = false
    ): array|object {
        return $this->receiveArgusApiCalls(
            $this->sendArgusApiCalls($callData, $this->createHttpClient(), $timeout, $keepFulfilledCallsWhenOneIsRejected)
        );
    }

    /**
     * Send every call of `$callData` through `$httpClient` without waiting for an answer, one request per call. The
     * answers are read by {@see receiveArgusApiCalls()}. `$tracksRequestWrites` records per call when its request
     * bytes are written.
     *
     * @throws InternalErrorException a body that cannot be JSON-encoded
     */
    protected function sendArgusApiCalls(
        #[\SensitiveParameter] array &$callData,
        Client $httpClient,
        float $timeout = 0,
        bool $keepFulfilledCallsWhenOneIsRejected = false,
        bool $tracksRequestWrites = false
    ): ArgusApiCallsInFlight {
        $this->responseHeadersByCallId = [];
        $this->responseStatusCodesByCallId = [];
        $this->rejectionReasonsByCallId = [];
        foreach (array_keys($callData) as $endpoint) {
            if (!self::isEndpointWithRequestMethod((string)$endpoint)) {
                throw new InvalidArgumentException(
                    'Argus endpoint "' . $endpoint . '" carries no request method (expected e.g. "GET:/path" or "POST:https://…"); the batch was not sent'
                );
            }
        }
        $callArgusRequestConfig = self::getRequestSettings();
        $microserviceBaseUrl = self::getMicroserviceBaseUrl();
        $appendDebugFlags = self::isAppendingDebugFlags();
        $trackingHeader = self::getTrackingHeader();
        $accessToken = self::isSendingBearerTokenOfDefaultAccount() ? self::createBearerTokenOfDefaultAccount() : null;
        $argusApiCallsInFlight = new ArgusApiCallsInFlight();
        $argusApiCallsInFlight->endpointToIdDelimiter = self::ENDPOINT_TO_ID_DELIMITER;
        $argusApiCallsInFlight->keepFulfilledCallsWhenOneIsRejected = $keepFulfilledCallsWhenOneIsRejected;

        //We add account and Route/Command/MessageHandler alongside with the most recent call stack entries to Logging Data
        $accountId = AuthService::instance()?->getAccount()?->id ?? null;
        $uid = self::getRequestUid();
        $routeCommandOrMessageHandlerAndTraceAsString = self::getCallStackAsString();

        foreach ($callData as $endpoint => $parameters) {
            preg_match(self::ENDPOINT_WITH_REQUEST_METHOD_PATTERN, $endpoint, $matches);
            $urlPathString = $matches['url'];
            if ($urlPathString && $urlPathString[0] != '/' && strpos($urlPathString, 'http') === false) {
                $urlPathString = '/' . $urlPathString;
            }
            if ($urlPathString && $urlPathString[strlen($urlPathString) - 1] == '/') {
                $urlPathString = substr($urlPathString, 0, strlen($urlPathString) - 1);
            }

            // calldata is organized by endpoints and call data
            // each element contains [params => [... params for the endpoint call ...], id => the id of the call]
            foreach ($parameters as $parametersAndCallId) {
                $callId = (string)$parametersAndCallId['id'];
                $privateOperation = $this->findPrivateOperation($callId);
                $parametersAndCallId['http_errors'] = false;
                if (strpos($urlPathString, 'http') === false) {
                    // Remove trailing slash from base URL and leading slash from path to avoid double slashes
                    $callUrl = rtrim((string)$microserviceBaseUrl, '/') . '/' . ltrim($urlPathString, '/');
                } else {
                    $callUrl = $urlPathString;
                }
                if ($appendDebugFlags) {
                    $separator = str_contains($callUrl, '?') ? '&' : '?';
                    $callUrl .= $separator . 'debug=1&errors_as_json=1';
                }
                unset($parametersAndCallId['merge']);
                unset($parametersAndCallId['mergelimit']);
                unset($parametersAndCallId['mergeindices']);
                if (isset($parametersAndCallId['body'])) {
                    if (is_object($parametersAndCallId['body']) || is_array($parametersAndCallId['body'])) {
                        // JSON_INVALID_UTF8_SUBSTITUTE: payload content can contain invalid UTF-8 (e.g. a
                        // truncated multibyte char from terminal input or a tool result) — without it
                        // json_encode returns FALSE and the request silently goes out with body=false
                        // (observed: an AI completion endpoint then returns empty responses in a loop).
                        $encodedBody = json_encode(
                            $parametersAndCallId['body'],
                            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
                        );
                        if ($encodedBody === false) {
                            throw new InternalErrorException(
                                'Argus request body could not be JSON-encoded: ' . json_last_error_msg()
                            );
                        }
                        $parametersAndCallId['body'] = $encodedBody;
                    }
                }
                $parametersAndCallId['timeout'] = $timeout;
                // the configured headers first, the call's own headers over them
                $parametersAndCallId['headers'] =
                    (isset($parametersAndCallId['headers']) && is_array($parametersAndCallId['headers']))
                        ? array_merge($callArgusRequestConfig['headers'] ?? [], $parametersAndCallId['headers'])
                        : ($callArgusRequestConfig['headers'] ?? []);

                if ($trackingHeader !== '') {
                    $parametersAndCallId['headers'][$trackingHeader] =
                        'accountId=' . ($accountId ?? '') . ' ' .
                        'requestUID=' . $uid .
                        ' calledFrom=' . $routeCommandOrMessageHandlerAndTraceAsString;
                }

                // the default account's bearer token, unless the call sends an Authorization of its own
                if ($accessToken !== null && !self::hasHeader($parametersAndCallId['headers'], 'Authorization')) {
                    $parametersAndCallId['headers']['Authorization'] = 'Bearer ' . $accessToken;
                }

                // we do not need to set content type for multipart requests because it is set by guzzle automatically
                if (isset($parametersAndCallId['multipart'])) {
                    unset($parametersAndCallId['headers']['Content-Type']);
                }

                if ($privateOperation) {
                    // the private transport follows no redirect, enters no debug output and reads the answer
                    // streamed within its byte limit (error responses included)
                    $parametersAndCallId['allow_redirects'] = false;
                    $parametersAndCallId['debug'] = false;
                    $parametersAndCallId['stream'] = true;
                    $parametersAndCallId['read_timeout'] = $timeout > 0 ? $timeout : 15;
                    $parametersAndCallId['on_headers'] = static function (ResponseInterface $response) use ($privateOperation): void {
                        $contentLength = $response->getHeaderLine('Content-Length');
                        if ($contentLength !== '' && (!ctype_digit($contentLength)
                                || (float)$contentLength > $privateOperation->privateResponseMaxBytes)) {
                            throw new RuntimeException('Private Argus response exceeds byte limit');
                        }
                    };
                }

                if (self::$transportRecording !== null) {
                    // a recorded run matches calls by their kind (a request option for the recording's handler, never sent)
                    $parametersAndCallId[ArgusTransportRecording::CALL_KIND_REQUEST_OPTION] = $this->findCallKindOfCall($callId);
                }
                // the low-speed abort the call's Argus class declares: a stalled transfer ends before the whole
                // timeout — set here, after the merge, so the cURL option keys stay intact
                $transferStallCurlOptions = $this->findTransferStallCurlOptionsOfCall($callId);
                if ($transferStallCurlOptions !== []) {
                    $parametersAndCallId['curl'] = (is_array($parametersAndCallId['curl'] ?? null) ? $parametersAndCallId['curl'] : []) + $transferStallCurlOptions;
                }
                $requestWriteProgress = null;
                if ($tracksRequestWrites) {
                    $requestWriteProgress = new ArgusApiCallRequestWriteProgress();
                    $parametersAndCallId['progress'] = $requestWriteProgress;
                }
                $journalEndpoint = $endpoint;
                $parametersAndCallId['on_stats'] = static function (TransferStats $transferStats) use ($journalEndpoint): void {
                    self::journalSentRequest(
                        $journalEndpoint,
                        $transferStats->getTransferTime() ?? 0.0,
                        $transferStats->getResponse()?->getStatusCode()
                    );
                };
                try {
                    $callPromise = $httpClient->requestAsync($matches['method'], $callUrl, $parametersAndCallId);
                } catch (Throwable $exception) {
                    if ($privateOperation) {
                        throw new RuntimeException('Private Argus transport failed');
                    }
                    throw $exception;
                }
                if ($privateOperation) {
                    $callPromise = $callPromise->otherwise(static function (): never {
                        // no previous exception: the HTTP client's exceptions can carry request and response data
                        throw new RuntimeException('Private Argus transport failed');
                    });
                }
                $argusApiCallsInFlight->addCall(
                    $endpoint . self::ENDPOINT_TO_ID_DELIMITER . $callId,
                    $callPromise,
                    $requestWriteProgress
                );
                self::$sentCallCountsByEndpoint[$endpoint] = (self::$sentCallCountsByEndpoint[$endpoint] ?? 0) + 1;
                if (ArgusLoad::$logArgusCalls && !$privateOperation) {
                    $safeCalls = $this->redactPrivateCalls([$endpoint => $parameters]);
                    self::$exeutedArgusCalls[$endpoint . self::ENDPOINT_TO_ID_DELIMITER . $callId] = ['call' => $safeCalls[$endpoint]];
                }
            }
        }
        return $argusApiCallsInFlight;
    }

    /**
     * Wait for the answers of the calls {@see sendArgusApiCalls()} sent and decode them, keyed by endpoint and call id;
     * the headers, the status and a rejection reason per call are kept for the operations' handlers.
     */
    protected function receiveArgusApiCalls(ArgusApiCallsInFlight $argusApiCallsInFlight): array
    {
        $endpointToIdDelimiter = $argusApiCallsInFlight->endpointToIdDelimiter;
        $responses = $this->collectResponses(
            $argusApiCallsInFlight->getCallPromisesByCallKey(),
            $argusApiCallsInFlight->keepFulfilledCallsWhenOneIsRejected,
            $endpointToIdDelimiter
        );
        $results = [];
        foreach ($responses as $endpointAndId => $response) {
            [$endpoint, $callId] = explode($endpointToIdDelimiter, (string)$endpointAndId, 2);
            if (!isset($results[$endpoint])) {
                $results[$endpoint] = [];
            }
            if ($response === null) {
                // a REJECTED call under the settle mode: a null result (its handler still runs and classifies the
                // failure), no status, the reason recorded per call id
                $results[$endpoint][$callId] = null;
                continue;
            }
            $privateOperation = $this->findPrivateOperation($callId);
            if ($privateOperation) {
                $responseBytes = $this->readPrivateResponse($response, $privateOperation->privateResponseMaxBytes);
                $results[$endpoint][$callId] = $privateOperation->privateResponseFormat === ArgusApiOperation::PRIVATE_RESPONSE_FORMAT_BINARY
                    ? ['body' => $responseBytes]
                    : json_decode($responseBytes);
            } else {
                $results[$endpoint][$callId] = json_decode((string)$response->getBody());
            }
            $this->responseHeadersByCallId[$callId] = $response->getHeaders();
            $this->responseStatusCodesByCallId[$callId] = $response->getStatusCode();
            if (ArgusLoad::$logArgusCalls && !$privateOperation) {
                self::$exeutedArgusCalls[$endpointAndId]['response'] = $results[$endpoint][$callId];
            }
        }
        return $results;
    }

    /**
     * Wait for every promise of a round. Default: `Utils::unwrap()` — the first REJECTED promise (a timeout, a
     * connection or DNS error) throws out of the whole batch. Settle mode: `Utils::settle()` — a rejected promise
     * yields a null response with its reason recorded per call id, while the fulfilled ones are returned as usual.
     *
     * @param Promise\PromiseInterface[] $callPromises keyed `endpoint<delimiter>callId`
     * @return Response[] keyed like the promises; null for a rejected call in the settle mode
     */
    protected function collectResponses(
        array $callPromises,
        bool $keepFulfilledCallsWhenOneIsRejected,
        string $endpointToIdDelimiter
    ): array {
        if (!$keepFulfilledCallsWhenOneIsRejected) {
            return Promise\Utils::unwrap($callPromises);
        }
        $responses = [];
        foreach (Promise\Utils::settle($callPromises)->wait() as $endpointAndId => $settled) {
            if (($settled['state'] ?? null) === Promise\PromiseInterface::FULFILLED) {
                $responses[$endpointAndId] = $settled['value'];
                continue;
            }
            $reason = $settled['reason'] ?? null;
            $reasonText = $reason instanceof Throwable
                ? $reason::class . ': ' . mb_substr($reason->getMessage(), 0, 160)
                : (string)$reason;
            $callId = str_contains((string)$endpointAndId, $endpointToIdDelimiter)
                ? explode($endpointToIdDelimiter, (string)$endpointAndId, 2)[1]
                : (string)$endpointAndId;
            $this->rejectionReasonsByCallId[$callId] = $reasonText;
            $responses[$endpointAndId] = null;
        }
        return $responses;
    }

    /**
     * The HTTP client of a round: the transport recording's handler while one is active, else Guzzle's default stack
     * (on `$curlMultiHandler` for a split execution).
     */
    protected function createHttpClient(?CurlMultiHandler $curlMultiHandler = null): Client
    {
        if (self::$transportRecording !== null) {
            return new Client(['handler' => self::$transportRecording->buildHandlerStack()]);
        }
        if ($curlMultiHandler !== null) {
            return new Client(['handler' => HandlerStack::create($curlMultiHandler)]);
        }
        return new Client();
    }

    /**
     * The kind of a call for a recorded run, read from the Argus entity behind it (its first operation) through the
     * optional method `getArgusCallKind(): ?string` (e.g. an AI usage scope), followed by the class's short name;
     * null for a class that declares no kind.
     */
    protected function findCallKindOfCall(string $callId): ?string
    {
        $operations = $this->operationsById[$callId] ?? [];
        $operation = is_array($operations) ? ($operations[0] ?? null) : $operations;
        $argusEntity = $operation instanceof ArgusApiOperation && isset($operation->entity) ? $operation->entity : null;
        if (!is_object($argusEntity) || !method_exists($argusEntity, 'getArgusCallKind')) {
            return null;
        }
        try {
            $callKind = $argusEntity->getArgusCallKind();
        } catch (Throwable) {
            return null;
        }
        if (!is_string($callKind) || $callKind === '') {
            return null;
        }
        return $callKind . '|' . substr((string)strrchr('\\' . $argusEntity::class, '\\'), 1);
    }

    /**
     * The cURL options of the low-speed abort the Argus entity of a call declares through the optional method
     * `buildTransferStallCurlOptions(): array` (e.g. `[CURLOPT_LOW_SPEED_LIMIT => 1, CURLOPT_LOW_SPEED_TIME => 30]`);
     * [] for every other class and for a call of several merged operations.
     *
     * @return int[] cURL option => value
     */
    protected function findTransferStallCurlOptionsOfCall(string $callId): array
    {
        $operations = $this->operationsById[$callId] ?? [];
        $operations = is_array($operations) ? $operations : [$operations];
        if (count($operations) !== 1) {
            return [];
        }
        $argusEntity = isset($operations[0]->entity) ? $operations[0]->entity : null;
        if (!is_object($argusEntity) || !method_exists($argusEntity, 'buildTransferStallCurlOptions')) {
            return [];
        }
        try {
            $transferStallCurlOptions = $argusEntity->buildTransferStallCurlOptions();
        } catch (Throwable) {
            return [];
        }
        return is_array($transferStallCurlOptions) ? $transferStallCurlOptions : [];
    }

    /**
     * The private operation behind a call id, or null.
     *
     * @throws LogicException a private operation merged with others
     */
    protected function findPrivateOperation(string $callId): ?ArgusApiOperation
    {
        $operations = $this->operationsById[$callId] ?? [];
        $operations = is_array($operations) ? $operations : [$operations];
        foreach ($operations as $operation) {
            if ($operation instanceof ArgusApiOperation && $operation->privateResponseFormat !== null) {
                if (count($operations) !== 1) {
                    throw new LogicException('Private Argus operations cannot be merged');
                }
                return $operation;
            }
        }
        return null;
    }

    /** The calls with every private call replaced by its id and a redaction mark */
    protected function redactPrivateCalls(array $calls): array
    {
        foreach ($calls as &$requests) {
            foreach ($requests as &$request) {
                if ($this->findPrivateOperation((string)$request['id'])) {
                    $request = ['id' => $request['id'], 'redacted' => true];
                }
            }
            unset($request);
        }
        unset($requests);
        return $calls;
    }

    /**
     * Reads a private response streamed and bounded: more than `$maxBytes`, a stalled or an incomplete read fails
     * without the response's content in the message.
     */
    protected function readPrivateResponse(#[\SensitiveParameter] ResponseInterface $response, int $maxBytes): string
    {
        $responseBody = $response->getBody();
        $responseBytes = '';
        try {
            $contentLength = $response->getHeaderLine('Content-Length');
            $expectedBytes = $contentLength !== '' && ctype_digit($contentLength) ? (int)$contentLength : null;
            if ($expectedBytes !== null && $expectedBytes > $maxBytes) {
                throw new RuntimeException('Private Argus response exceeds byte limit');
            }
            // HTTP keep-alive need not close the stream after a complete Content-Length body
            while ($expectedBytes === null || strlen($responseBytes) < $expectedBytes) {
                $chunk = $responseBody->read(min(8192, ($expectedBytes ?? ($maxBytes + 1)) - strlen($responseBytes)));
                if ($chunk === '') {
                    if ($responseBody->eof()) {
                        break;
                    }
                    throw new RuntimeException('Private Argus response read stalled');
                }
                $responseBytes .= $chunk;
                if (strlen($responseBytes) > $maxBytes) {
                    throw new RuntimeException('Private Argus response exceeds byte limit');
                }
            }
            if ($expectedBytes !== null && strlen($responseBytes) !== $expectedBytes) {
                throw new RuntimeException('Private Argus response is incomplete');
            }
            return $responseBytes;
        } catch (Throwable) {
            throw new RuntimeException('Private Argus response could not be read within its byte limit');
        } finally {
            $responseBody->close();
        }
    }

    /** Record one sent request in the call journal while {@see $journalRequests} is on (called from the client's on_stats) */
    public static function journalSentRequest(string $endpoint, float $transferSeconds, ?int $statusCode): void
    {
        if (!self::$journalRequests) {
            return;
        }
        $sentCallJournalEntry = new ArgusSentCallJournalEntry();
        $sentCallJournalEntry->endpoint = mb_substr((string)preg_replace('/\?.*$/', '', $endpoint), 0, 160);
        $sentCallJournalEntry->transferMilliseconds = (int)round($transferSeconds * 1000);
        $sentCallJournalEntry->statusCode = $statusCode;
        $sentCallJournalEntry->endedAtMilliseconds = (int)round(hrtime(true) / 1e6);
        $sentCallJournalEntry->startedAtMilliseconds = $sentCallJournalEntry->endedAtMilliseconds - $sentCallJournalEntry->transferMilliseconds;
        $callJournal = self::getCallJournal();
        $callJournal->add($sentCallJournalEntry);
        if ($callJournal->count() > self::CALL_JOURNAL_MAX_ENTRIES) {
            self::$callJournal = $callJournal->getElementsSlice(-self::CALL_JOURNAL_MAX_ENTRIES, self::CALL_JOURNAL_MAX_ENTRIES);
        }
    }

    /** The requests sent while {@see $journalRequests} was on, at most {@see CALL_JOURNAL_MAX_ENTRIES} */
    public static function getCallJournal(): ArgusSentCallJournalEntries
    {
        return self::$callJournal ??= new ArgusSentCallJournalEntries();
    }

    public static function clearCallJournal(): void
    {
        self::$callJournal = null;
    }

    /** @return int[] How often each endpoint (`METHOD:path`) was sent in this process, by endpoint */
    public static function getSentCallCountsByEndpoint(): array
    {
        return self::$sentCallCountsByEndpoint;
    }

    public static function getExecutedArgusCalls(): array
    {
        return self::$exeutedArgusCalls;
    }

    /** The application's `Base.Repo.argus` config; [] when there is none */
    protected static function getArgusConfig(): array
    {
        // read as a whole: Config::get() of a single key that holds false reads as "not configured"
        $argusConfig = Config::get('Base.Repo.argus');
        return is_array($argusConfig) ? $argusConfig : [];
    }

    /** The base URL relative endpoints are sent to: config `microservices.baseUrl`, else env `ARGUS_API_ENDPOINT` */
    public static function getMicroserviceBaseUrl(): ?string
    {
        $configuredBaseUrl = self::getArgusConfig()['microservices']['baseUrl'] ?? null;
        if (is_string($configuredBaseUrl) && $configuredBaseUrl !== '') {
            return $configuredBaseUrl;
        }
        $environmentBaseUrl = Config::getEnv('ARGUS_API_ENDPOINT');
        return is_string($environmentBaseUrl) && $environmentBaseUrl !== '' ? $environmentBaseUrl : null;
    }

    /**
     * Returns request settings for Argus API calls: the built-in defaults, replaced by config `requestSettings`, or —
     * without that config — by env `ARGUS_REQUEST_SETTINGS` (JSON).
     */
    protected static function getRequestSettings(): array
    {
        $defaults = [
            'headers' => [
                'Connection' => 'Keep-Alive',
                'Keep-Alive' => '600',
                'Accept-Charset' => 'ISO-8859-1,UTF-8;q=0.7,*;q=0.7',
                'Accept-Language' => 'de,en;q=0.7,en-us;q=0.3',
                'Accept' => '*/*',
                'Content-Type' => 'application/json',
                'x-api-key' => 'apps-symfony'
            ],
            'http_errors' => false,
            'timeout' => 600
        ];

        $configuredRequestSettings = self::getArgusConfig()['requestSettings'] ?? null;
        if (is_array($configuredRequestSettings)) {
            return array_replace_recursive($defaults, $configuredRequestSettings);
        }
        $envOverride = Config::getEnv('ARGUS_REQUEST_SETTINGS');
        if ($envOverride && is_string($envOverride)) {
            $decoded = json_decode($envOverride, true);
            if (is_array($decoded)) {
                $defaults = array_replace_recursive($defaults, $decoded);
            }
        }

        return $defaults;
    }

    /** Whether every call gets the default CLI account's bearer token: config `auth`, default true */
    protected static function isSendingBearerTokenOfDefaultAccount(): bool
    {
        $auth = self::getArgusConfig()['auth'] ?? true;
        return filter_var($auth, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
    }

    /** Whether every URL gets `debug=1&errors_as_json=1`: config `appendDebugFlags`, default env `APP_DEBUG` */
    protected static function isAppendingDebugFlags(): bool
    {
        $argusConfig = self::getArgusConfig();
        if (array_key_exists('appendDebugFlags', $argusConfig)) {
            return (bool)filter_var($argusConfig['appendDebugFlags'], FILTER_VALIDATE_BOOLEAN);
        }
        return (bool)Config::getEnv('APP_DEBUG');
    }

    /** The tracking header's name: config `trackingHeader`, default {@see DEFAULT_TRACKING_HEADER}; '' sends none */
    protected static function getTrackingHeader(): string
    {
        $trackingHeader = self::getArgusConfig()['trackingHeader'] ?? self::DEFAULT_TRACKING_HEADER;
        return is_string($trackingHeader) ? $trackingHeader : self::DEFAULT_TRACKING_HEADER;
    }

    /** The default CLI account's access token, or null without such an account */
    protected static function createBearerTokenOfDefaultAccount(): ?string
    {
        $defaultAccount = DDDService::instance()->getDefaultAccountForCliOperations();
        if (!$defaultAccount) {
            return null;
        }
        $refreshToken = AuthService::instance()->getRefreshTokenForAccountId($defaultAccount->id, isShortLived: false);
        $accessToken = AuthService::instance()->getAccessTokenForAccountBasedOnRefreshToken($refreshToken, false);
        return is_string($accessToken) && $accessToken !== '' ? $accessToken : null;
    }

    /** Whether a header map carries a header, case-insensitively */
    protected static function hasHeader(array $headers, string $headerName): bool
    {
        foreach (array_keys($headers) as $existingHeaderName) {
            if (strcasecmp((string)$existingHeaderName, $headerName) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Gets or generates a cached request UID for the current request.
     * Ensures the same UID is used for all operations within a single HTTP, CLI, or messenger request.
     *
     * @return string The cached request UID.
     */
    protected static function getRequestUid(): string
    {
        if (self::$requestUid === null) {
            self::$requestUid = self::generateUid();
        }
        return self::$requestUid;
    }

    /**
     * Generates a random unique identifier (UID) of the specified length.
     *
     * This method uses cryptographic functions to generate a secure random UID.
     * The UID is returned as a hexadecimal string.
     *
     * @param int $length The desired length of the UID. Default is 16 characters.
     * @return string The generated UID as a hexadecimal string.
     */
    protected static function generateUid(int $length = 16): string
    {
        try {
            return substr(bin2hex(random_bytes((int)ceil($length / 2))), 0, $length);
        } catch (Throwable) {
            // If random_bytes fails, we fall back to the next method
            return uniqid('', true);
        }
    }
}
