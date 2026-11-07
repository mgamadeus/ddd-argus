<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\Argus\Utils;

use Closure;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

/**
 * The Argus transport of a recorded-run comparison: while installed as {@see ArgusApiOperations::$transportRecording},
 * every Argus HTTP call of the process goes through ONE Guzzle handler this object provides.
 *
 * - RECORD passes each call to the real network and keeps request and response ({@see $entries}).
 * - PLAY answers each call from the recording and keeps the request it was asked ({@see $playedRequests}), so a replay
 *   can compare what it sent. A played call without a recorded answer fails; {@see unusedEntries()} names the recorded
 *   answers a replay never asked for — a complete replay has neither.
 * - PLAY_TOOL_CALLS answers ONLY the calls made inside a tool (`duringTool`), for a comparison whose tools run live;
 *   while {@see $toolCallLabel} is set, a call is answered only from the calls recorded under the same label.
 *   {@see buildChangedRequestBodies()} names every in-tool request whose body differs from the recorded one.
 * - PLAY_WITH_LIVE_TOOLS answers EVERY call: a call made inside a tool from the recorded in-tool calls, any other call
 *   from the recorded calls made outside a tool.
 *
 * Turns and kinds: while {@see $turnOrdinal} is set, every recorded call carries the turn it belongs to and its KIND
 * ({@see kindOf()}). A player answers a call only from the recorded calls of the SAME turn and kind — the very same body
 * first, else the oldest unused one — and never borrows another turn's answer. A recorded transport failure (timeout,
 * refused connection) is stored as exception class and message, never as a status-0 response, and played back as the
 * same exception.
 *
 * A recording is stored with {@see toStorageArray()} and read back with {@see forPlaying()} and its siblings.
 */
class ArgusTransportRecording
{
    public const string MODE_RECORD = 'record';
    public const string MODE_PLAY = 'play';
    public const string MODE_PLAY_TOOL_CALLS = 'play-tool-calls';
    public const string MODE_PLAY_WITH_LIVE_TOOLS = 'play-with-live-tools';

    /**
     * The Guzzle request option {@see ArgusApiOperations} puts on a call while a recording is active: the kind the
     * call's Argus entity declares (`getArgusCallKind()`). Read by the recording's handler, never sent.
     */
    public const string CALL_KIND_REQUEST_OPTION = 'argus_recorded_run_call_kind';

    /** The recorded calls in the order they were made */
    public ArgusTransportRecordingEntries $entries;

    /** What a replay sent, in order, and the recorded call that answered it */
    public ArgusTransportRecordingPlayedRequests $playedRequests;

    /** While true, the calls being recorded are made INSIDE a tool */
    public bool $insideToolExecution = false;

    /** The scripted tool call the calls of this moment belong to; null: no label is recorded or matched */
    public ?string $toolCallLabel = null;

    /** The owner turn the calls of this moment belong to; null: no turn is recorded or matched */
    public ?int $turnOrdinal = null;

    /**
     * Rewrites a recorded answer to a call made OUTSIDE a tool before it is handed over: (answer body, recorded
     * position) → body — e.g. to translate ids the recording run created into the replay's ids. Null: answers are
     * played byte for byte.
     *
     * @var (Closure(string, int): string)|null
     */
    public ?Closure $playedAnswerTranslator = null;

    /**
     * Rows the replay created under other ids than the recording run: a call is matched in the recording's ids and
     * its recorded answer is handed over in the replay's ids. Null: matched and answered byte for byte.
     */
    public ?RecordedRunIdCounterparts $idCounterparts = null;

    /** Whether any recorded call carries a turn (computed once per player) */
    protected ?bool $recordingCarriesTurns = null;

    /** @var bool[] The recorded positions a replay has answered from, by position */
    protected array $usedEntryPositions = [];

    /** @param callable|null $networkHandler the handler RECORD passes calls to (null: Guzzle's own network handler) */
    public function __construct(public readonly string $mode, protected $networkHandler = null)
    {
        $this->entries = new ArgusTransportRecordingEntries();
        $this->playedRequests = new ArgusTransportRecordingPlayedRequests();
    }

    /** @param array[] $storedEntries a recording's calls, as {@see toStorageArray()} stores them */
    public static function forPlaying(array $storedEntries): static
    {
        return static::createPlayer(self::MODE_PLAY, $storedEntries);
    }

    /**
     * The player of a tool-call comparison: answers the calls the tools make from the recording's in-tool calls.
     *
     * @param array[] $storedEntries a recording's calls, as {@see toStorageArray()} stores them
     */
    public static function forPlayingToolCalls(array $storedEntries): static
    {
        return static::createPlayer(self::MODE_PLAY_TOOL_CALLS, $storedEntries);
    }

    /**
     * The player of a run whose tools run live: answers every call, in-tool calls from in-tool recordings.
     *
     * @param array[] $storedEntries a recording's calls, as {@see toStorageArray()} stores them
     */
    public static function forPlayingWithLiveTools(array $storedEntries): static
    {
        return static::createPlayer(self::MODE_PLAY_WITH_LIVE_TOOLS, $storedEntries);
    }

    public static function forRecording(?callable $networkHandler = null): static
    {
        return new static(self::MODE_RECORD, $networkHandler);
    }

    /** @param array[] $storedEntries */
    protected static function createPlayer(string $mode, array $storedEntries): static
    {
        $argusTransportRecording = new static($mode);
        foreach (array_values($storedEntries) as $storedEntry) {
            $argusTransportRecordingEntry = ArgusTransportRecordingEntry::fromStorageArray((array)$storedEntry);
            $argusTransportRecording->entries->add($argusTransportRecordingEntry);
        }
        return $argusTransportRecording;
    }

    /** The handler stack the Argus HTTP client is built with while this recording is active */
    public function buildHandlerStack(): HandlerStack
    {
        if ($this->mode !== self::MODE_RECORD) {
            return HandlerStack::create(
                fn(RequestInterface $request, array $options): PromiseInterface => $this->play($request, $options)
            );
        }
        $handlerStack = HandlerStack::create($this->networkHandler);
        $handlerStack->push(function (callable $nextHandler): callable {
            return function (RequestInterface $request, array $options) use ($nextHandler): PromiseInterface {
                // the position is taken when the call is SENT: parallel calls answer in any order, a replay asks in
                // the order the code sends them
                $requestBody = (string)$request->getBody();
                $argusTransportRecordingEntry = new ArgusTransportRecordingEntry();
                $argusTransportRecordingEntry->endpoint = self::endpointOf($request);
                $argusTransportRecordingEntry->requestBody = $requestBody;
                $argusTransportRecordingEntry->duringTool = $this->insideToolExecution;
                $argusTransportRecordingEntry->kind = self::kindOf(
                    $argusTransportRecordingEntry->endpoint,
                    $requestBody,
                    $options[self::CALL_KIND_REQUEST_OPTION] ?? null
                );
                $argusTransportRecordingEntry->turnOrdinal = $this->turnOrdinal;
                $argusTransportRecordingEntry->toolCallLabel = $this->toolCallLabel;
                $this->entries->add($argusTransportRecordingEntry);
                try {
                    $networkPromise = $nextHandler($request, $options);
                } catch (Throwable $synchronousFailure) {
                    // a handler that fails before it returns a promise is the same transport failure
                    $networkPromise = Create::rejectionFor($synchronousFailure);
                }
                return $networkPromise->then(
                    function (ResponseInterface $response) use ($argusTransportRecordingEntry): ResponseInterface {
                        $responseBody = (string)$response->getBody();
                        $argusTransportRecordingEntry->statusCode = $response->getStatusCode();
                        $argusTransportRecordingEntry->responseHeaders = ['Content-Type' => $response->getHeader('Content-Type')];
                        $argusTransportRecordingEntry->responseBody = $responseBody;
                        return new Response($response->getStatusCode(), $response->getHeaders(), $responseBody);
                    },
                    function (mixed $reason) use ($argusTransportRecordingEntry): PromiseInterface {
                        // a transport failure is recorded as what it was, never as a status-0 response
                        $argusTransportFailure = new ArgusTransportFailure();
                        $argusTransportFailure->exceptionClass = is_object($reason) ? $reason::class : RuntimeException::class;
                        $argusTransportFailure->message = $reason instanceof Throwable ? $reason->getMessage() : (string)json_encode($reason);
                        $argusTransportRecordingEntry->transportFailure = $argusTransportFailure;
                        return Create::rejectionFor($reason);
                    }
                );
            };
        });
        return $handlerStack;
    }

    /** @return string[] The recorded calls a replay never asked for: endpoint by position */
    public function unusedEntries(): array
    {
        $unusedEntries = [];
        foreach ($this->entries->getElements() as $position => $argusTransportRecordingEntry) {
            $isAnswerableByThisPlayer = match ($this->mode) {
                self::MODE_PLAY_TOOL_CALLS => $argusTransportRecordingEntry->duringTool,
                self::MODE_PLAY_WITH_LIVE_TOOLS => true,
                default => !$argusTransportRecordingEntry->duringTool,
            };
            if (!isset($this->usedEntryPositions[$position]) && $isAnswerableByThisPlayer) {
                $unusedEntries[$position] = $argusTransportRecordingEntry->endpoint;
            }
        }
        return $unusedEntries;
    }

    /**
     * The requests of a replay whose body is not byte-identical to the recorded call that answered it, and the
     * requests no recorded call answered — one line per request, empty when every body is unchanged.
     *
     * @return string[] by played position
     */
    public function buildChangedRequestBodies(): array
    {
        $changedRequestBodies = [];
        foreach ($this->playedRequests->getElements() as $playedPosition => $playedRequest) {
            $answeredByPosition = $playedRequest->answeredByPosition;
            if ($answeredByPosition === null) {
                $changedRequestBodies[$playedPosition] = "call #{$playedPosition} {$playedRequest->endpoint}: no recorded call answered it";
                continue;
            }
            $recordedRequestBody = $this->entries->findEntryAtPosition($answeredByPosition)?->requestBody ?? '';
            if ($recordedRequestBody !== $playedRequest->requestBody) {
                $changedRequestBodies[$playedPosition] = "call #{$playedPosition} (recorded #{$answeredByPosition}) {$playedRequest->endpoint}: request body changed — "
                    . self::describeFirstDifference($recordedRequestBody, $playedRequest->requestBody);
            }
        }
        return $changedRequestBodies;
    }

    /** @return array[] The recorded calls in the stored recording format, in order */
    public function toStorageArray(): array
    {
        $storedEntries = [];
        foreach ($this->entries->getElements() as $argusTransportRecordingEntry) {
            $storedEntries[] = $argusTransportRecordingEntry->toStorageArray();
        }
        return $storedEntries;
    }

    protected function play(RequestInterface $request, array $options = []): PromiseInterface
    {
        $sentEndpoint = self::endpointOf($request);
        $sentRequestBody = (string)$request->getBody();
        // matched in the recording's ids; the played request keeps what was sent
        $endpoint = $this->idCounterparts?->translateReplayTextToRecorded($sentEndpoint) ?? $sentEndpoint;
        $requestBody = $this->idCounterparts?->translateReplayTextToRecorded($sentRequestBody) ?? $sentRequestBody;
        $kind = self::kindOf($endpoint, $requestBody, $options[self::CALL_KIND_REQUEST_OPTION] ?? null);
        $playedRequest = new ArgusTransportRecordingPlayedRequest();
        $playedRequest->endpoint = $sentEndpoint;
        $playedRequest->requestBody = $sentRequestBody;
        $playedRequest->kind = $kind;
        $playedRequest->turnOrdinal = $this->turnOrdinal;
        $this->playedRequests->add($playedRequest);
        $turnMismatch = $this->describeTurnMismatch();
        if ($turnMismatch !== null) {
            return Create::rejectionFor(
                new RuntimeException("recorded-run comparison: {$turnMismatch} — cannot answer {$endpoint} (kind {$kind})")
            );
        }
        // within the call's own turn and kind: the recorded call with the very same request first (parallel calls of
        // one kind), else the oldest unused call of the kind — a request that changed is still answered, and the
        // comparison shows how it changed. A recording without kinds matches by the fingerprint, and a non-JSON call
        // (a GET, a form) falls back to the endpoint's order.
        $answerPosition = null;
        $sameKindPosition = null;
        $sameEndpointPosition = null;
        foreach ($this->entries->getElements() as $position => $argusTransportRecordingEntry) {
            if (isset($this->usedEntryPositions[$position])
                || $argusTransportRecordingEntry->endpoint !== $endpoint
                || !$this->isAnswerableEntry($argusTransportRecordingEntry)
                || ($this->turnOrdinal !== null && $argusTransportRecordingEntry->turnOrdinal !== $this->turnOrdinal)) {
                continue;
            }
            if ($argusTransportRecordingEntry->requestBody === $requestBody) {
                $answerPosition = $position;
                break;
            }
            $entryKind = $argusTransportRecordingEntry->kind
                ?? self::fingerprintOf($argusTransportRecordingEntry->endpoint, $argusTransportRecordingEntry->requestBody);
            $requestKind = $argusTransportRecordingEntry->kind !== null ? $kind : self::fingerprintOf($endpoint, $requestBody);
            if ($sameKindPosition === null && $entryKind === $requestKind) {
                $sameKindPosition = $position;
            }
            $sameEndpointPosition ??= $position;
        }
        $answerPosition ??= $sameKindPosition ?? (self::isKindedRequest($requestBody) ? null : $sameEndpointPosition);
        $playedRequest->answeredByPosition = $answerPosition;
        if ($answerPosition === null) {
            return Create::rejectionFor(new RuntimeException(
                "recorded-run comparison: no recorded answer left for {$endpoint} (kind {$kind}"
                . ($this->turnOrdinal !== null ? ", turn {$this->turnOrdinal}" : '') . ')'
            ));
        }
        $this->usedEntryPositions[$answerPosition] = true;
        $argusTransportRecordingEntry = $this->entries->findEntryAtPosition($answerPosition);
        if ($argusTransportRecordingEntry->transportFailure !== null) {
            return Create::rejectionFor(
                self::buildRecordedTransportFailure($argusTransportRecordingEntry->transportFailure, $request)
            );
        }
        $responseBody = $argusTransportRecordingEntry->responseBody;
        if ($this->playedAnswerTranslator !== null && !$argusTransportRecordingEntry->duringTool) {
            $responseBody = ($this->playedAnswerTranslator)($responseBody, $answerPosition);
        }
        if ($this->idCounterparts !== null) {
            $responseBody = $this->idCounterparts->translateRecordedTextToReplay($responseBody);
        }
        return Create::promiseFor(new Response(
            $argusTransportRecordingEntry->statusCode,
            $argusTransportRecordingEntry->responseHeaders,
            $responseBody
        ));
    }

    /**
     * Why turns cannot be matched, or null: a recording made per turn needs a turn set, and a turn set needs a
     * recording made per turn — never a silent match across turns.
     */
    protected function describeTurnMismatch(): ?string
    {
        if ($this->recordingCarriesTurns === null) {
            $this->recordingCarriesTurns = false;
            foreach ($this->entries->getElements() as $argusTransportRecordingEntry) {
                if ($argusTransportRecordingEntry->turnOrdinal !== null) {
                    $this->recordingCarriesTurns = true;
                    break;
                }
            }
        }
        if ($this->recordingCarriesTurns && $this->turnOrdinal === null) {
            return 'the recording matches calls per owner turn, but no turn is set';
        }
        if (!$this->recordingCarriesTurns && $this->turnOrdinal !== null && $this->entries->count() > 0) {
            return 'a turn is set, but the recording carries no turns (record it again)';
        }
        return null;
    }

    /**
     * The KIND of a call: the kind its Argus entity declares, plus the sorted question keys of a decision call
     * (`{model, state, questions}`); a call without a declared kind keeps the fingerprint ({@see fingerprintOf()}).
     */
    public static function kindOf(string $endpoint, string $requestBody, mixed $declaredCallKind): string
    {
        if (!is_string($declaredCallKind) || $declaredCallKind === '') {
            return self::fingerprintOf($endpoint, $requestBody);
        }
        $decodedBody = json_decode($requestBody, true);
        $questionKeys = is_array($decodedBody) && is_array($decodedBody['questions'] ?? null) ? array_keys($decodedBody['questions']) : [];
        sort($questionKeys);
        return $declaredCallKind . ($questionKeys !== [] ? '|' . implode(',', $questionKeys) : '');
    }

    /** The KIND of a call without a declared kind: its endpoint, its model and the start of its first message (or its top-level keys) */
    public static function fingerprintOf(string $endpoint, string $requestBody): string
    {
        $decodedBody = json_decode($requestBody, true);
        if (!is_array($decodedBody)) {
            return $endpoint;
        }
        $firstMessage = $decodedBody['messages'][0]['content'] ?? null;
        $firstMessageStart = is_string($firstMessage)
            ? mb_substr($firstMessage, 0, 120)
            : (is_array($firstMessage) ? mb_substr((string)json_encode($firstMessage), 0, 120) : '');
        $topLevelKeys = array_keys($decodedBody);
        sort($topLevelKeys);
        return $endpoint . '|' . (string)($decodedBody['model'] ?? '') . '|' . ($firstMessageStart !== '' ? $firstMessageStart : implode(',', $topLevelKeys));
    }

    /**
     * The recorded transport failure as the exception it was: a ConnectException or a plain RequestException gets the
     * request again; every other class becomes a RuntimeException naming it.
     */
    protected static function buildRecordedTransportFailure(
        ArgusTransportFailure $argusTransportFailure,
        RequestInterface $request
    ): Throwable {
        return match ($argusTransportFailure->exceptionClass) {
            ConnectException::class => new ConnectException($argusTransportFailure->message, $request),
            RequestException::class => new RequestException($argusTransportFailure->message, $request),
            default => new RuntimeException(
                "recorded transport failure {$argusTransportFailure->exceptionClass}: {$argusTransportFailure->message}"
            ),
        };
    }

    /**
     * Whether this player may answer from a recorded call: PLAY answers the calls made outside a tool, PLAY_TOOL_CALLS
     * the calls made inside one (under the current label when both carry one), PLAY_WITH_LIVE_TOOLS every call from
     * the recorded calls on the same side of a tool boundary.
     */
    protected function isAnswerableEntry(ArgusTransportRecordingEntry $argusTransportRecordingEntry): bool
    {
        if ($this->mode === self::MODE_PLAY_WITH_LIVE_TOOLS) {
            return $argusTransportRecordingEntry->duringTool === $this->insideToolExecution;
        }
        if ($this->mode !== self::MODE_PLAY_TOOL_CALLS) {
            return !$argusTransportRecordingEntry->duringTool;
        }
        if (!$argusTransportRecordingEntry->duringTool) {
            return false;
        }
        return $this->toolCallLabel === null
            || $argusTransportRecordingEntry->toolCallLabel === null
            || $argusTransportRecordingEntry->toolCallLabel === $this->toolCallLabel;
    }

    /** Where two request bodies start to differ, with a little context from both */
    protected static function describeFirstDifference(string $recordedRequestBody, string $playedRequestBody): string
    {
        $commonLength = min(strlen($recordedRequestBody), strlen($playedRequestBody));
        $offset = 0;
        while ($offset < $commonLength && $recordedRequestBody[$offset] === $playedRequestBody[$offset]) {
            $offset++;
        }
        $contextStart = max(0, $offset - 60);
        return 'at byte ' . $offset . ': recorded «…' . substr($recordedRequestBody, $contextStart, 160)
            . '…» replayed «…' . substr($playedRequestBody, $contextStart, 160) . '…»';
    }

    /** Whether a request body carries a kind beyond its endpoint (a JSON object: a model, decision or API call) */
    protected static function isKindedRequest(string $requestBody): bool
    {
        return is_array(json_decode($requestBody, true));
    }

    protected static function endpointOf(RequestInterface $request): string
    {
        $uri = $request->getUri();
        return $request->getMethod() . ':' . $uri->getScheme() . '://' . $uri->getHost() . $uri->getPath();
    }
}
