<?php

declare (strict_types=1);

namespace DDD\Domain\Base\Repo\Argus\Utils;

use DDD\Domain\Base\Repo\Argus\Attributes\ArgusLoad;
use DDD\Domain\Base\Repo\Argus\Enums\ArgusApiOperationType;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\AuthService;
use DDD\Domain\Base\Entities\MessageHandlers\AppMessageHandler;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Reflection\ClassWithNamespace;
use DDD\Infrastructure\Reflection\ReflectionClass;
use DDD\Infrastructure\Traits\Serializer\SerializerTrait;
use GuzzleHttp\Client;
use GuzzleHttp\Promise;
use GuzzleHttp\Psr7\Response;
use ReflectionException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

class ArgusApiOperations
{
    use SerializerTrait;

    /** @var ArgusApiOperation[]|null */
    public ?array $operations;

    /** @var ArgusApiOperation[]|null */
    public array $operationsById;

    /** @var ArgusApiOperation[]|null */
    public array $operationsGroupedByEndpoints;

    /** @var ArgusApiOperation[]|null */
    public array $operationsGroupedByEndpointsFinal;

    /** @var null */
    protected $dboperationsGroupedByHashes;

    /** @var array */
    protected array $dboperationsHashes;

    /** @var array Cache for executed Argus calls, can be displayed for debugging purposes by getExecutedArgusCalls */
    protected static array $exeutedArgusCalls = [];

    /** @var string|null Cached request UID for the current request */
    protected static ?string $requestUid = null;

    public function __construct()
    {
        $this->operations = [];
        $this->operationsGroupedByEndpoints = [];
        $this->operationsGroupedByEndpointsFinal = [];
        $this->operationsById = [];
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
     * @param bool $displayCall
     * @param bool $displayResponse
     * @param bool $useApiACallCache
     * @param bool $logOperationCalls
     * @param ArgusApiOperationType $operationType
     * @return void
     * @throws ReflectionException
     */
    public function execute(
        bool $displayCall = false,
        bool $displayResponse = false,
        bool $useApiACallCache = true,
        bool $logOperationCalls = true,
        ArgusApiOperationType $operationType = ArgusApiOperationType::LOAD,
        float $timeout = 0
    ): void {
        // first we have to resolve all the outanding merges, that are not combined into one endpoint call:
        // e.g. merge_limit 2 and 3 operations added => the last operation is still not in the endpoints_final
        foreach ($this->operationsGroupedByEndpoints as $endpoint => $ops) {
            $this->handleOutstandingMerges($endpoint, true);
        }
        //we need to split the calls, if they are too big:
        // first we count the calls
        $totalOperations = 0;

        if ($displayCall) {
            echo json_encode($this->operationsGroupedByEndpointsFinal, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            return;
        }
        foreach ($this->operationsGroupedByEndpointsFinal as $endpoint) {
            foreach ($endpoint as $call) {
                $totalOperations++;
            }
        }
        $numApiCalls = ceil($totalOperations / 300); //max 300 operations per call
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

            $results = $this->callArgusApi($actCall, $useApiACallCache, $logOperationCalls, $timeout);

            if ($displayResponse) {
                echo json_encode($results, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                return;
            }

            if (!empty($results)) {
                foreach ($results as $endpoint => $responses) {
                    foreach ($responses as $id => $response) {
                        if (isset($this->operationsById[$id])) {
                            if (is_array($this->operationsById[$id])) {
                                foreach ($this->operationsById[$id] as $op) {
                                    /** @var ArgusApiOperation $op */
                                    $op->handleResponse($response, $operationType);
                                }
                            } elseif (
                                is_object($this->operationsById[$id]) && method_exists(
                                    $this->operationsById[$id],
                                    'handleResponse'
                                )
                            ) {
                                $this->operationsById[$id]->handleResponse($response, $operationType);
                            }
                        }
                    }
                }
            }
            unset($actCall);
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
                $parameters = $request->attributes->get('_route_params', []);
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
                $classWithNamespace = new ClassWithNamespace($traceElement['class']);
                if (isset($classesToIgnore[$classWithNamespace->name])) {
                    continue;
                }
                if (is_a($traceElement['class'], AppMessageHandler::class, true)) {
                    $messageHandlerReflectionClass = ReflectionClass::instance((string)$traceElement['class']);
                    /** @var AsMessageHandler $asMessageHandlerAttribute */
                    $asMessageHandlerAttribute = $messageHandlerReflectionClass->getAttributeInstance(
                        AsMessageHandler::class
                    );
                    if ($asMessageHandlerAttribute && $asMessageHandlerAttribute->fromTransport) {
                        $messageHandler = $asMessageHandlerAttribute->fromTransport;
                    }
                } elseif (is_a($traceElement['class'], Command::class, true)) {
                    $commandReflectionClass = ReflectionClass::instance((string)$traceElement['class']);
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
     * calls argus microservices with call data and executes calls as individual HTTP requests in parallel
     * @param array $callData
     * @param bool $useCache
     * @param bool $logCall
     * @param float $timeout
     * @return array|object
     */
    protected function callArgusApi(
        array &$callData,
        bool $useCache = false,
        bool $logCall = true,
        float $timeout = 0
    ): array|object {
        $callArgusRequestConfig = self::getRequestSettings();
        $httpClient = new Client();
        $callPromises = [];
        $microserviceBaseUrl = Config::getEnv('ARGUS_API_ENDPOINT');
        $adminAcount = DDDService::instance()->getDefaultAccountForCliOperations();
        if ($adminAcount) {
            $refreshToken = (string)AuthService::instance()->getRefreshTokenForAccountId(
                $adminAcount->id,
                isShortLived: false
            );
            $accessToken = AuthService::instance()->getAccessTokenForAccountBasedOnRefreshToken($refreshToken, false);
        }

        //used to put endpoint and id into a single array index divided by delimiter
        $endpointToIdDelimiter = '___##___';

        //We add account and Route/Command/MessageHandler alongside with the most recent call stack entries to Logging Data
        $accountId = AuthService::instance()?->getAccount()?->id ?? null;
        $uid = self::getRequestUid();
        $routeCommandOrMessageHandlerAndTraceAsString = self::getCallStackAsString();

        foreach ($callData as $endpoint => $parameters) {
            // microservice calls have request method as prefix
            preg_match('/^(?P<method>(GET|PUT|POST|DELETE|PATCH)):(?P<url>[\S]+)/', $endpoint, $matches);
            if (isset($matches['url']) && isset($matches['method'])) {
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
                    $parametersAndCallId['http_errors'] = false;
                    // we put the http request into the call promises under an index combined of endpoint and id of the call
                    if (strpos($urlPathString, 'http') === false) {
                        // Remove trailing slash from base URL and leading slash from path to avoid double slashes
                        $baseUrl = rtrim($microserviceBaseUrl, '/');
                        $path = ltrim($urlPathString, '/');
                        $callUrl = $baseUrl . '/' . $path;
                    } else {
                        $callUrl = $urlPathString;
                    }

                    // When Symfony kernel debug mode is active, pass debug and errors_as_json flags to the Argus call
                    if ((bool)Config::getEnv('APP_DEBUG')) {
                        $separator = str_contains($callUrl, '?') ? '&' : '?';
                        $callUrl .= $separator . 'debug=1&errors_as_json=1';
                    }
                    unset($parametersAndCallId['merge']);
                    unset($parametersAndCallId['mergelimit']);
                    unset($parametersAndCallId['mergeindices']);
                    if (isset($parametersAndCallId['body'])) {
                        if (is_object($parametersAndCallId['body']) || is_array($parametersAndCallId['body'])) {
                            $parametersAndCallId['body'] = json_encode(
                                $parametersAndCallId['body'],
                                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                            );
                        }
                    }
                    $parametersAndCallId['timeout'] = $timeout;
                    $parametersAndCallId['headers'] =
                        (isset($parametersAndCallId['headers']) && is_array($parametersAndCallId['headers']))
                            ? array_merge($parametersAndCallId['headers'], $callArgusRequestConfig['headers'])
                            : $callArgusRequestConfig['headers'];

                    $parametersAndCallId['headers']['rc-tracking'] =
                        'accountId=' . ($accountId ?? '') . ' ' .
                        'requestUID=' . $uid .
                        ' calledFrom=' . $routeCommandOrMessageHandlerAndTraceAsString;

                    // Add Authorization header if bearer token is available
                    if (isset($accessToken)) {
                        $parametersAndCallId['headers']['Authorization'] = 'Bearer ' . $accessToken;
                    }

                    // we do not need to set content type for multipart requests because it is set by guzzle automatically
                    if (isset($parametersAndCallId['multipart'])) {
                        unset($parametersAndCallId['headers']['Content-Type']);
                    }

                    $callPromises[$endpoint . $endpointToIdDelimiter . $parametersAndCallId['id']] = $httpClient->requestAsync(
                        $matches['method'],
                        $callUrl,
                        $parametersAndCallId
                    );
                    if (ArgusLoad::$logArgusCalls) {
                        self::$exeutedArgusCalls[$endpoint . $endpointToIdDelimiter . $parametersAndCallId['id']] = ['call' => $parameters];
                    }
                }
            }
        }

        /** @var $responses Response[] */
        $responses = Promise\Utils::unwrap($callPromises);
        $results = [];

        foreach ($responses as $endpointAndId => $response) {
            // explode endpoints and ids by delimiter
            $endpointAndIdExploded = explode($endpointToIdDelimiter, $endpointAndId);
            $endpoint = $endpointAndIdExploded[0];
            $callId = $endpointAndIdExploded[1];
            if (!isset($results[$endpoint])) {
                $results[$endpoint] = [];
            }
            $results[$endpoint][$callId] = json_decode((string)$response->getBody());
            if (ArgusLoad::$logArgusCalls) {
                self::$exeutedArgusCalls[$endpointAndId]['response'] = $results[$endpoint][$callId];
            }
        }
        return $results;
    }

    /**
     * Returns request settings for Argus API calls.
     * Defaults are built-in; override via ARGUS_REQUEST_SETTINGS env var (JSON).
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

        $envOverride = Config::getEnv('ARGUS_REQUEST_SETTINGS');
        if ($envOverride && is_string($envOverride)) {
            $decoded = json_decode($envOverride, true);
            if (is_array($decoded)) {
                $defaults = array_replace_recursive($defaults, $decoded);
            }
        }

        return $defaults;
    }

    public static function getExecutedArgusCalls(): array
    {
        return self::$exeutedArgusCalls;
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
