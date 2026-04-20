---
name: ddd-module-argus-specialist
description: Create and work with Argus external API repositories in the ddd-argus module -- batched HTTP calls, multi-tier caching, CRUD operations, bidirectional entity conversion, and selective property loading. Use when integrating external APIs via the Argus pattern.
metadata:
  author: mgamadeus
  version: "1.0.0"
  module: mgamadeus/ddd-argus
---

# Argus Module Specialist

External API repository layer for batched HTTP calls with multi-tier caching.

> **Base patterns:** See core skills in `vendor/mgamadeus/ddd` for entity/service conventions.

## When to Use

- Creating Argus repo entities to integrate external APIs
- Configuring caching strategies for API responses
- Implementing CRUD operations against external services
- Debugging Argus API calls and cache behavior
- Understanding the loading flow and operation merging

## Creating an Argus Repo Entity

```php
use DDD\Domain\Base\Repo\Argus\Attributes\ArgusLoad;
use DDD\Domain\Base\Repo\Argus\Traits\ArgusTrait;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusCache;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusApiOperation;

#[ArgusLoad(
    loadEndpoint: 'POST:/my-service/endpoint',
    cacheLevel: ArgusCache::CACHELEVEL_MEMORY_AND_DB,
    cacheTtl: ArgusCache::CACHE_TTL_ONE_DAY
)]
class ArgusMyEntity extends MyEntity
{
    use ArgusTrait;

    protected function getLoadPayload(): ?array
    {
        return [
            'body' => ['id' => $this->id, 'language' => $this->languageCode],
            // Optional: 'query' => [...], 'path' => [...], 'headers' => [...]
        ];
    }

    public function handleLoadResponse(
        mixed &$callResponseData = null,
        ?ArgusApiOperation &$apiOperation = null
    ): void {
        $data = $this->getResponseDataFromArgusResponse($callResponseData);
        if ($data) {
            $this->name = $data->name;
            $this->geoPoint = new GeoPoint();
            $this->geoPoint->lat = $data->location->latitude;
        }
        $this->postProcessLoadResponse($callResponseData, $data !== null);
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic($this->id . '_' . $this->languageCode);
    }
}
```

**Rules:**
- **Extend** the domain entity (not wrap it) -- same properties, extended with loading
- Use `ArgusTrait` (provides `fromEntity()`, `toEntity()`, `cacheKey()`, `getResponseDataFromArgusResponse()`)
- `uniqueKey()` must be deterministic for cache stability
- Always call `$this->postProcessLoadResponse($callResponseData, $success)` at the end of `handleLoadResponse()`

## Naming Convention

The Argus repo class name is always `Argus` + the domain entity class name:

| Domain Entity | Argus Repo |
|---|---|
| `SupportTicketAnalyzer` | `ArgusSupportTicketAnalyzer` |
| `ChatMessageModeration` | `ArgusChatMessageModeration` |
| `SupportEmailCleaned` | `ArgusSupportEmailCleaned` |

The Argus class **extends** the domain entity: `class ArgusChatMessageModeration extends ChatMessageModeration`

**NEVER** suffix the domain entity with `Result`, `Response`, `Data`, etc. The Argus class name = `Argus` + exact domain entity name.

## Binding: Two-Sided LazyLoad Wiring (CRITICAL)

Argus lazy-loading requires attributes on **both sides** -- the domain entity AND the parent entity property. Missing either side causes silent failures.

### Side 1: Domain entity class -- `#[LazyLoadRepo]`

The domain entity (value object) declares which Argus repo class loads it:

```php
#[LazyLoadRepo(LazyLoadRepo::ARGUS, ArgusMyEntity::class)]
class MyEntity extends ValueObject
{
    // properties...
}
```

### Side 2: Parent entity property -- `#[LazyLoad]` + `#[DatabaseColumn(ignoreProperty: true)]`

The parent entity that owns this value object declares the lazy-loaded property:

```php
class ParentEntity extends Entity
{
    #[DatabaseColumn(ignoreProperty: true)]
    #[LazyLoad(repoType: LazyLoadRepo::ARGUS)]
    public ?MyEntity $myEntity;
}
```

### Complete example (both sides)

```php
// Domain entity (value object) -- has #[LazyLoadRepo]
#[LazyLoadRepo(LazyLoadRepo::ARGUS, ArgusSupportTicketAnalyzer::class)]
class SupportTicketAnalyzer extends ValueObject
{
    public ?string $generatedTitle;
    public ?string $generatedSummary;
}

// Parent entity -- has #[LazyLoad] on the property
class SupportTicket extends Entity
{
    #[DatabaseColumn(ignoreProperty: true)]
    #[LazyLoad(repoType: LazyLoadRepo::ARGUS)]
    public ?SupportTicketAnalyzer $supportTicketAnalyzer;
}

// Argus repo -- extends domain entity
class ArgusSupportTicketAnalyzer extends SupportTicketAnalyzer
{
    use ArgusTrait, ArgusAILanguageModelTrait;
    // lazyload(), getUserContent(), applyLoadResult()
}
```

Both `#[LazyLoadRepo]` on the value object AND `#[LazyLoad(repoType: LazyLoadRepo::ARGUS)]` on the parent property are required. If you forget `#[LazyLoadRepo]` on the value object, the framework cannot find the Argus repo class to instantiate.

## `#[ArgusLoad]` Attribute

```php
#[ArgusLoad(
    loadEndpoint: 'POST:/service/path',          // or array for multiple
    createEndpoint: 'POST:/service/create',       // optional
    updateEndpoint: 'PUT:/service/{id}',          // optional, {id} from path params
    deleteEndpoint: 'DELETE:/service/{id}',        // optional
    synchronizeEndpoint: 'POST:/service/sync',    // optional
    cacheLevel: ArgusCache::CACHELEVEL_MEMORY_AND_DB,  // 0-3
    cacheTtl: ArgusCache::CACHE_TTL_ONE_DAY,           // seconds
    timeout: 30.0                                       // HTTP timeout
)]
```

## Payload Format

```php
protected function getLoadPayload(): ?array
{
    return [
        'body' => [...],            // HTTP body (JSON encoded)
        'query' => [...],           // URL query params
        'path' => ['id' => 123],    // Path params: {id} replaced in endpoint
        'headers' => [...],         // Custom headers
        'merge' => true,            // Enable operation merging
        'mergelimit' => 10,         // Merge N operations into 1 call
        'general_params' => [...],  // Shared params for all merged ops
    ];
}
```

## CRUD Operations

```php
$argus = new ArgusMyEntity();
$argus->someProperty = 'value';

$argus->argusLoad();          // Calls loadEndpoint
$argus->argusCreate();        // Calls createEndpoint
$argus->argusUpdate();        // Calls updateEndpoint
$argus->argusDelete();        // Calls deleteEndpoint
$argus->argusSynchronize();   // Calls synchronizeEndpoint
```

Each has a corresponding `get{Operation}Payload()` method to override.

## Cache Levels & TTLs

| Level | Constant | Behavior |
|-------|----------|----------|
| 0 | `CACHELEVEL_NONE` | No caching |
| 1 | `CACHELEVEL_MEMORY` | APC only (fast, max 1h, process-local) |
| 2 | `CACHELEVEL_DB` | Redis Sentinel only (distributed) |
| 3 | `CACHELEVEL_MEMORY_AND_DB` | Both (recommended) |

TTLs: `CACHE_TTL_TEN_MINUTES` (600), `CACHE_TTL_THIRTY_MINUTES` (1800), `CACHE_TTL_ONE_HOUR` (3600), `CACHE_TTL_ONE_DAY` (86400), `CACHE_TTL_ONE_WEEK` (604800), `CACHE_TTL_ONE_MONTH` (2292000)

## Selective Property Loading

```php
// Per-call: load only specific children
$argus->setPropertiesToLoad(ArgusChildEntity::class);
$argus->setPropertiesToLoad(
    ArgusLoadingParameters::create(ArgusOther::class, 'param1', 'param2')
);
$argus->argusLoad();

// Per-class: always load these children
ArgusMyEntity::setPropertiesToLoadAlways(ArgusChildEntity::class);
```

## Response Parsing Helper

```php
// Standard Argus response: { "status": 200, "data": { ... } }
$data = $this->getResponseDataFromArgusResponse($callResponseData);
// Returns $callResponseData->data if status is 200 or 'OK', null otherwise
```

## Debugging

```php
ArgusLoad::$deactivateArgusCache = true;   // Disable all caching
ArgusLoad::$logArgusCalls = true;          // Log calls + responses

$argus->argusLoad(displayCall: true);      // Echo payload, don't execute
$argus->argusLoad(displayResponse: true);  // Echo response, don't execute

$calls = ArgusApiOperations::getExecutedArgusCalls();  // Retrieve log
```

## Batch DTOs

For modules that ship batch controllers, use the provided base DTOs:

```php
use DDD\Presentation\Api\Batch\Base\Dtos\BatchRequestDto;   // Has $payload (stdClass, body)
use DDD\Presentation\Api\Batch\Base\Dtos\BatchReponseDto;   // Has $status, $responseData
```

## Checklist (New Argus Repo)

- [ ] Argus class name follows convention: `Argus` + exact domain entity name (no `Result`/`Response` suffix)
- [ ] Argus class **extends** the domain entity (not wraps)
- [ ] Uses `ArgusTrait`
- [ ] Has `#[ArgusLoad(...)]` with endpoint, cache level, TTL
- [ ] Implements `getLoadPayload()` returning array with `body`/`query`/`path`
- [ ] Implements `handleLoadResponse()` calling `postProcessLoadResponse()` at end
- [ ] `uniqueKey()` is deterministic (for cache key stability)
- [ ] **Side 1:** Domain entity has `#[LazyLoadRepo(LazyLoadRepo::ARGUS, ArgusMyEntity::class)]` on its class
- [ ] **Side 2:** Parent entity has `#[DatabaseColumn(ignoreProperty: true)]` + `#[LazyLoad(repoType: LazyLoadRepo::ARGUS)]` on the property
- [ ] Never use `private` -- always `protected`
