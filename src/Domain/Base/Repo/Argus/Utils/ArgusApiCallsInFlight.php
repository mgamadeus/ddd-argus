<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\Argus\Utils;

use DDD\Domain\Base\Repo\Argus\Enums\ArgusApiOperationType;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\Promise\PromiseInterface;

/**
 * The HTTP calls of one {@see ArgusApiOperations} execution that were sent and not yet received: their promises, the
 * key delimiter the responses are read back with, and the cURL multi handler that moves the transfers forward. A plain
 * runtime object handed from the send to the receive half of an execution, never persisted or serialized.
 */
class ArgusApiCallsInFlight
{
    /** @var PromiseInterface[] The calls' promises, keyed `endpoint<delimiter>callId` (the shape Guzzle's promise utilities take) */
    protected array $callPromises = [];

    /** @var ArgusApiCallRequestWriteProgress[] One per call that tracks its request write, keyed like the promises */
    protected array $requestWriteProgresses = [];

    /** The separator between the endpoint and the call id in the promise keys */
    public string $endpointToIdDelimiter = '';

    /** Whether a rejected call yields a null response instead of throwing out of the whole execution (the settle mode) */
    public bool $keepFulfilledCallsWhenOneIsRejected = false;

    /** The kind of execution the responses are handed to the operations as */
    public ArgusApiOperationType $operationType = ArgusApiOperationType::LOAD;

    /** The handler the transfers run on; null when the calls run on the default client (they move only when received) */
    public ?CurlMultiHandler $curlMultiHandler = null;

    public function addCall(
        string $callKey,
        PromiseInterface $callPromise,
        ?ArgusApiCallRequestWriteProgress $requestWriteProgress = null
    ): void {
        $this->callPromises[$callKey] = $callPromise;
        if ($requestWriteProgress !== null) {
            $this->requestWriteProgresses[$callKey] = $requestWriteProgress;
        }
    }

    /** @return PromiseInterface[] The calls' promises, keyed `endpoint<delimiter>callId` */
    public function getCallPromisesByCallKey(): array
    {
        return $this->callPromises;
    }

    /** Every call's request is fully written (an execution without calls counts as written) */
    public function areAllRequestsWritten(): bool
    {
        foreach ($this->requestWriteProgresses as $requestWriteProgress) {
            if (!$requestWriteProgress->requestWritten) {
                return false;
            }
        }
        return true;
    }

    /** When the last request was written; null while one is not written yet or no call tracks its write */
    public function findAllRequestsWrittenAtHr(): int|float|null
    {
        if ($this->requestWriteProgresses === [] || !$this->areAllRequestsWritten()) {
            return null;
        }
        $lastWrittenAtHr = 0;
        foreach ($this->requestWriteProgresses as $requestWriteProgress) {
            $lastWrittenAtHr = max($lastWrittenAtHr, $requestWriteProgress->requestWrittenAtHr ?? 0);
        }
        return $lastWrittenAtHr;
    }

    /** Every call has its answer or its failure (no promise is pending) */
    public function areAllCallsSettled(): bool
    {
        foreach ($this->callPromises as $callPromise) {
            if ($callPromise->getState() === PromiseInterface::PENDING) {
                return false;
            }
        }
        return true;
    }

    /** Cancel every call still pending: its transfer is removed, its promise rejected (a null answer in the settle mode) */
    public function cancelPendingCalls(): void
    {
        foreach ($this->callPromises as $callPromise) {
            if ($callPromise->getState() === PromiseInterface::PENDING) {
                $callPromise->cancel();
            }
        }
    }
}
