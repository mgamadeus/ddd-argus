<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\Argus\Utils;

use DDD\Infrastructure\Cache\Cache as InfrastructureCache;

/**
 * Lets a second reader of ONE cached Argus entity JOIN a computation that is already running instead of starting the
 * same expensive load again (e.g. a model call of several seconds): the computing side writes a `pending:<cacheKey>`
 * marker into the shared (Redis) cache level before its load and removes it afterwards; a reader that finds no entry
 * but the marker polls the entity cache for a bounded time and, once the entry appeared, loads from it. On a timeout
 * the reader computes itself — the marker is a hint, never a lock, so a crashed computer costs at most the wait. The
 * marker carries its own expiry instant: a marker a reader finds past that instant counts as absent even when the
 * cache layer kept the key alive.
 *
 * Typical use: a background warm computes an entry while a user still reads, and the user's request arrives while the
 * warm still runs — without the join the request would start a second full computation.
 *
 * ```php
 * $argusCacheInFlightComputation = new ArgusCacheInFlightComputation($argusEntity->cacheKey());
 * if ($argusCacheInFlightComputation->joinPendingComputationOrClaim()) {
 *     try {
 *         $argusEntity->argusLoad(useArgusEntityCache: false);   // compute
 *     } finally {
 *         $argusCacheInFlightComputation->releaseComputation();
 *     }
 * } else {
 *     $argusEntity->argusLoad();                                 // the entry exists: served from the cache
 * }
 * ```
 */
class ArgusCacheInFlightComputation
{
    /** @var int How long a computing side's marker is honoured */
    public const int PENDING_MARKER_TTL_SECONDS = 45;

    /** @var int The longest a joining reader waits for the entry before computing itself */
    public const int DEFAULT_MAX_WAIT_MILLISECONDS = 10000;

    /** @var int The pause between two polls of the entity cache */
    public const int DEFAULT_POLL_STEP_MILLISECONDS = 250;

    /** @var string The prefix that turns an entity's cache key into its pending marker's key */
    public const string PENDING_MARKER_KEY_PREFIX = 'pending:';

    /** @var string This instance's owner token — the marker carries it, and only its owner's release removes the marker */
    protected string $ownerToken;

    public function __construct(
        protected string $cacheKey,
        protected int $pendingMarkerTtlSeconds = self::PENDING_MARKER_TTL_SECONDS
    ) {
        $this->ownerToken = bin2hex(random_bytes(8));
    }

    /**
     * The reader's path: false when the entry exists already or appeared while a pending computation was awaited
     * (the caller loads with the cache and hits), true when the caller must compute — the marker is then set for the
     * caller, who releases it with {@see releaseComputation()} after its load.
     */
    public function joinPendingComputationOrClaim(
        int $maxWaitMilliseconds = self::DEFAULT_MAX_WAIT_MILLISECONDS,
        int $pollStepMilliseconds = self::DEFAULT_POLL_STEP_MILLISECONDS
    ): bool {
        if ($this->hasCacheEntry()) {
            return false;
        }
        if ($this->isPending() && $this->waitForCacheEntryWhilePending($maxWaitMilliseconds, $pollStepMilliseconds)) {
            return false;
        }
        $this->claimComputation();
        return true;
    }

    /** The computing side's path (a warm that recomputes regardless of the entry): announce the computation */
    public function claimComputation(): void
    {
        $argusCachePendingMarker = new ArgusCachePendingMarker();
        $argusCachePendingMarker->expiresAt = time() + $this->pendingMarkerTtlSeconds;
        $argusCachePendingMarker->ownerToken = $this->ownerToken;
        $this->writePendingMarker($argusCachePendingMarker);
    }

    /**
     * Withdraw the OWN announcement — in a `finally`, so a failed computation does not keep readers waiting. A marker
     * another computer wrote over ours is left alone: its owner is still working and a third reader should keep
     * joining it.
     */
    public function releaseComputation(): void
    {
        $argusCachePendingMarker = $this->readPendingMarker();
        if ($argusCachePendingMarker !== null && $argusCachePendingMarker->ownerToken !== $this->ownerToken) {
            return;
        }
        $this->deletePendingMarker();
    }

    /** Whether a computation of this entry announced itself and its announcement has not expired */
    public function isPending(): bool
    {
        $argusCachePendingMarker = $this->readPendingMarker();
        return $argusCachePendingMarker !== null && $argusCachePendingMarker->expiresAt > time();
    }

    /**
     * Poll the entity cache while the marker holds: true once the entry exists, false when the wait is used up or
     * the computing side released (or lost) its marker without writing an entry.
     */
    public function waitForCacheEntryWhilePending(
        int $maxWaitMilliseconds = self::DEFAULT_MAX_WAIT_MILLISECONDS,
        int $pollStepMilliseconds = self::DEFAULT_POLL_STEP_MILLISECONDS
    ): bool {
        $pollStepMilliseconds = max(1, $pollStepMilliseconds);
        $waitedMilliseconds = 0;
        while ($waitedMilliseconds < $maxWaitMilliseconds) {
            $this->sleepMilliseconds($pollStepMilliseconds);
            $waitedMilliseconds += $pollStepMilliseconds;
            if ($this->hasCacheEntry()) {
                return true;
            }
            if (!$this->isPending()) {
                return $this->hasCacheEntry();
            }
        }
        return false;
    }

    /** Whether the entity cache holds a usable entry — an unsuccessful load's empty entry is no entry */
    public function hasCacheEntry(): bool
    {
        $cacheItem = ArgusCache::get($this->cacheKey, ArgusCache::CACHELEVEL_MEMORY_AND_DB);
        return $cacheItem !== null && (string)($cacheItem->data ?? '') !== '';
    }

    protected function getPendingMarkerCacheKey(): string
    {
        return self::PENDING_MARKER_KEY_PREFIX . $this->cacheKey;
    }

    /** The marker on the shared level, null without one — the computing side is another process */
    protected function readPendingMarker(): ?ArgusCachePendingMarker
    {
        // not ArgusCache::get(): its shared-level read re-writes the value under the cache group's default lifetime,
        // which would keep an expired marker's key alive for far longer than announced
        return ArgusCachePendingMarker::fromStoredValue(
            InfrastructureCache::instance(InfrastructureCache::CACHE_GROUP_REDIS_SENTINEL)
                ->get(ArgusCache::getCacheKey($this->getPendingMarkerCacheKey()))
        );
    }

    protected function writePendingMarker(ArgusCachePendingMarker $argusCachePendingMarker): void
    {
        ArgusCache::set(
            $this->getPendingMarkerCacheKey(),
            $argusCachePendingMarker->toStoredValue(),
            $this->pendingMarkerTtlSeconds,
            ArgusCache::CACHELEVEL_DB
        );
    }

    protected function deletePendingMarker(): void
    {
        ArgusCache::delete($this->getPendingMarkerCacheKey(), ArgusCache::CACHELEVEL_DB);
    }

    protected function sleepMilliseconds(int $milliseconds): void
    {
        usleep($milliseconds * 1000);
    }
}
