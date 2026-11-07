# mgamadeus/ddd-argus -- Argus Module

External API repository layer for the `mgamadeus/ddd` framework -- batched HTTP calls with multi-tier caching.

**Package:** `mgamadeus/ddd-argus` (v1.3.x)
**Namespace:** `DDD\`
**Depends on:** `mgamadeus/ddd` (^3.14.3)

> **This module follows all DDD Core conventions.** For base patterns, see `vendor/mgamadeus/ddd/AGENTS.md` and skills in `vendor/mgamadeus/ddd`.

## Architecture

```
src/
+-- Domain/Base/Repo/Argus/
|   +-- Attributes/ArgusLoad.php          [#[ArgusLoad] attribute -- endpoint, cache, TTL config]
|   +-- ArgusEntity.php                   [Base class for Argus repo entities]
|   +-- ArgusSettings.php                 [Runtime state for loading operations]
|   +-- Traits/
|   |   +-- ArgusTrait.php               [Bidirectional conversion: fromEntity()/toEntity()]
|   |   +-- ArgusLoadTrait.php           [Full loading & CRUD: argusLoad/Create/Update/Delete]
|   +-- Enums/ArgusApiOperationType.php   [LOAD, CREATE, UPDATE, DELETE, PATCH, SYNCHRONIZE]
|   +-- Utils/
|       +-- ArgusCache.php               [Multi-tier cache: APC + Redis Sentinel]
|       +-- ArgusApiOperation.php         [Single API call with path param substitution]
|       +-- ArgusApiOperations.php        [Batch executor: parallel Guzzle, merging, auth, split execution, call journal]
|       +-- ArgusApiCallsInFlight.php     [Calls sent and not yet received (split execution)]
|       +-- ArgusApiCallRequestWriteProgress.php [Whether a sent request is fully written]
|       +-- ArgusSentCallJournalEntry(ies).php   [Call journal: endpoint, transfer time, status]
|       +-- ArgusCacheInFlightComputation.php    [A second reader joins a running computation]
|       +-- ArgusCachePendingMarker.php   [Its pending marker]
|       +-- ArgusTransportRecording.php   [Record / play every Argus call of a process]
|       +-- ArgusTransportRecordingEntry(ies).php, ArgusTransportRecordingPlayedRequest(s).php, ArgusTransportFailure.php
|       +-- RecordedRunIdCounterparts.php [Replay ids <-> recorded ids]
|       +-- ArgusApiCacheOperation.php    [Single cache lookup]
|       +-- ArgusApiCacheOperations.php   [Batch Redis MGET]
|       +-- ArgusLoadingParameters.php    [Selective property loading config]
|       +-- ArgusCacheItem.php            [Cache entry metadata]
+-- Presentation/Api/Batch/Base/Dtos/
|   +-- BatchRequestDto.php               [Base DTO for batch controllers]
|   +-- BatchReponseDto.php               [Base DTO for batch responses]
+-- Symfony/EventListeners/ArgusWorkerStateResetter.php [Worker reset before every consumed message]
+-- Modules/Argus/ArgusModule.php
```

## Core Concepts

### How It Works

Argus repo classes **extend** domain entities (not wrap them). They add loading/caching behavior via `ArgusTrait` + `ArgusLoadTrait` and are configured via `#[ArgusLoad]`.

```
Domain Entity  →  Argus Repo Entity (extends entity, adds API loading)
     ↕                    ↕
   LazyLoad          argusLoad() → parallel HTTP + multi-tier cache
```

### Loading Flow

```
Entity.lazyLoad()
  → ArgusEntity.argusLoad()
    → constructApiOperations() for this + children
    → ArgusApiCacheOperations.execute()  -- batch Redis MGET
    → ArgusApiOperations.execute()       -- parallel Guzzle HTTP
    → handleLoadResponse()              -- parse, populate
    → postProcessLoadResponse()         -- cache, mark loaded
```

### Cache Levels

| Level | Constant | Description |
|-------|----------|-------------|
| 0 | `CACHELEVEL_NONE` | No caching |
| 1 | `CACHELEVEL_MEMORY` | APC only (fast, single-server, max 1h TTL) |
| 2 | `CACHELEVEL_DB` | Redis Sentinel only (distributed) |
| 3 | `CACHELEVEL_MEMORY_AND_DB` | Both (recommended) |

### Cache TTL Presets

`CACHE_TTL_TEN_MINUTES` (600), `CACHE_TTL_THIRTY_MINUTES` (1800), `CACHE_TTL_ONE_HOUR` (3600), `CACHE_TTL_ONE_DAY` (86400), `CACHE_TTL_ONE_WEEK` (604800), `CACHE_TTL_ONE_MONTH` (2292000)

## Key Patterns

**Creating an Argus repo:** Extend domain entity, use `ArgusTrait`, add `#[ArgusLoad]`, implement `getLoadPayload()` and `handleLoadResponse()`.

**CRUD:** `argusLoad()`, `argusCreate()`, `argusUpdate()`, `argusDelete()`, `argusSynchronize()`

**Selective loading:** `setPropertiesToLoad(ArgusChild::class)` / `setPropertiesToLoadAlways(ArgusChild::class)`

**Operation merging:** Multiple operations batched into single HTTP call via `mergelimit`

**Path parameters:** `{id}` in endpoints auto-replaced from payload's `path` array

**Configuration:** `Base.Repo.argus` in the application config first (`microservices.baseUrl`, `requestSettings`,
`auth`, `appendDebugFlags`, `trackingHeader`), the environment only as fallback; a call's own headers win over the
configured ones. Every endpoint carries its request method (`GET:/path`); one without is rejected before the batch
is sent.

**Auth:** with `auth` on (default), attaches the Bearer token of the `CLI_DEFAULT_ACCOUNT_ID_FOR_CLI_OPERATIONS`
account to every call without its own `Authorization`

**Timeouts:** `#[ArgusLoad(timeout:)]`, else the class constant `DEFAULT_ARGUS_TIMEOUT`, else 0 (none)

**Failures:** settle mode `ArgusSettings::$keepFulfilledCallsWhenOneIsRejected`; per-operation `responseStatusCode`,
`responseHeaders`, `rejectionReason`; `rejectionReasonForNon2xxAnswer()` + `postProcessLoadResponse(cacheFailure: false)`
keep a failure out of the cache

**Executor:** a per-batch object created with `new` — never a container service; optional entity methods
`getArgusCallKind(): ?string` (kind of a call in a recorded run) and `buildTransferStallCurlOptions(): array`
(low-speed abort) are read when present

**Debugging:** `ArgusLoad::$deactivateArgusCache = true`, `ArgusLoad::$logArgusCalls = true`, `displayCall: true`

## Environment Variables

```env
ARGUS_API_ENDPOINT=https://...                    # Base URL when the config has none
CLI_DEFAULT_ACCOUNT_ID_FOR_CLI_OPERATIONS=1       # Required: SUPER_ADMIN account for auth
ARGUS_REQUEST_SETTINGS='{"headers":{}}'           # Optional: override default HTTP settings
```
