# mgamadeus/ddd-argus

External API repository layer for the [mgamadeus/ddd](https://github.com/mgamadeus/ddd) framework — batched HTTP calls with multi-tier caching.

## Installation

```bash
composer require mgamadeus/ddd-argus
```

## What it does

Argus provides a repository pattern for external HTTP APIs, sitting alongside the DB repository layer (`LazyLoadRepo::DB`). It enables:

- **Batched parallel HTTP execution** via Guzzle promises
- **Multi-tier caching** — APC (in-process) + Redis Sentinel (distributed)
- **Bidirectional entity conversion** — domain entities ↔ Argus entities (`fromEntity()` / `toEntity()`)
- **Attribute-driven configuration** — a single `#[ArgusLoad]` attribute per entity defines endpoints, cache level, TTL
- **Full CRUD** — LOAD, CREATE, UPDATE, DELETE, PATCH, SYNCHRONIZE operations
- **Selective property loading** — load only specific child entities on demand

## Environment Variables

### Required

```env
# Base URL for the Argus batch API gateway
ARGUS_API_ENDPOINT="https://your-api-host.com/api/batch/"
```

### Optional

```env
# JSON override for default HTTP request settings (headers, timeout)
# Only specify keys you want to override — they merge with defaults
ARGUS_REQUEST_SETTINGS='{"headers":{"x-api-key":"your-custom-key"},"timeout":300}'
```

**Default request settings** (built into `ArgusApiOperations::getRequestSettings()`):

```json
{
    "headers": {
        "Connection": "Keep-Alive",
        "Keep-Alive": "600",
        "Accept-Charset": "ISO-8859-1,UTF-8;q=0.7,*;q=0.7",
        "Accept-Language": "de,en;q=0.7,en-us;q=0.3",
        "Accept": "*/*",
        "Content-Type": "application/json",
        "x-api-key": "apps-symfony"
    },
    "http_errors": false,
    "timeout": 600
}
```

## Usage

### Creating an Argus repo entity

```php
use DDD\Domain\Base\Repo\Argus\ArgusEntity;
use DDD\Domain\Base\Repo\Argus\Attributes\ArgusLoad;
use DDD\Domain\Base\Repo\Argus\Traits\ArgusTrait;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusCache;

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
        return ['body' => ['param' => $this->someProperty]];
    }

    public function handleLoadResponse(mixed &$callResponseData = null, ?ArgusApiOperation &$apiOperation = null): void
    {
        $data = $this->getResponseDataFromArgusResponse($callResponseData);
        if ($data) {
            $this->resultProperty = $data->value;
        }
        $this->postProcessLoadResponse($callResponseData, $data !== null);
    }
}
```

### Cache levels

| Constant | Value | Description |
|---|---|---|
| `CACHELEVEL_NONE` | 0 | No caching |
| `CACHELEVEL_MEMORY` | 1 | APC only (fast, process-local) |
| `CACHELEVEL_DB` | 2 | Redis Sentinel only (distributed) |
| `CACHELEVEL_MEMORY_AND_DB` | 3 | Both (recommended) |

### Cache TTL presets

| Constant | Seconds |
|---|---|
| `CACHE_TTL_TEN_MINUTES` | 600 |
| `CACHE_TTL_THIRTY_MINUTES` | 1800 |
| `CACHE_TTL_ONE_HOUR` | 3600 |
| `CACHE_TTL_ONE_DAY` | 86400 |
| `CACHE_TTL_ONE_WEEK` | 604800 |
| `CACHE_TTL_ONE_MONTH` | 2292000 |

## Authentication

Argus automatically authenticates outgoing API calls using a bearer token. The token is generated for the account specified by:

```env
# Account ID used for CLI and Argus batch operations (must have ADMIN or SUPERADMIN role)
CLI_DEFAULT_ACCOUNT_ID_FOR_CLI_OPERATIONS=1
```

The `ArgusApiOperations` class fetches a refresh token for this account via `AuthService`, exchanges it for an access token, and attaches it as `Authorization: Bearer <token>` to every outgoing request.

## Batch endpoints (server-side)

Modules that ship batch controllers (ddd-ai, ddd-common-geo) provide the server-side endpoints that Argus clients call. These endpoints **must be secured** in your project's `security.yaml`.

### Security configuration example

```yaml
# config/symfony/default/packages/security.yaml
security:
    password_hashers:
        Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface: 'auto'

    providers:
        app_user_provider:
            id: DDD\Symfony\Security\AccountProviders\AccountProvider
        all_users:
            chain:
                providers: ['app_user_provider']

    firewalls:
        main:
            stateless: true
            provider: all_users
            access_denied_handler: DDD\Symfony\Security\AccessDeniedHandlers\AccessDeniedHandler
            custom_authenticators:
                - DDD\Symfony\Security\Authenticators\TokenAuthenticator
                - DDD\Symfony\Security\Authenticators\LoginTokenAuthenticator

    role_hierarchy:
        ROLE_ADMIN: ROLE_USER
        ROLE_SUPER_ADMIN: [ ROLE_ADMIN, ROLE_ALLOWED_TO_SWITCH ]

    access_control:
        - { path: ^/api/public, roles: PUBLIC_ACCESS }
        - { path: ^/api/client, roles: ROLE_USER }
        - { path: ^/api/admin, roles: ROLE_ADMIN }
        - { path: ^/api/batch, roles: ROLE_SUPER_ADMIN }
```

The key line is `{ path: ^/api/batch, roles: ROLE_SUPER_ADMIN }` — this ensures that only the account specified by `CLI_DEFAULT_ACCOUNT_ID_FOR_CLI_OPERATIONS` (which must have SUPER_ADMIN role) can invoke batch operations. The bearer token is attached automatically by `ArgusApiOperations`.

## Architecture

### Core concepts

Argus repo classes follow this pattern:
- Class lives in `Domain/<BoundedContext>/Repo/Argus/...`
- Class **extends** a domain entity, value object, or entity set (not wraps — same properties, extended with loading)
- Class uses `ArgusTrait`
- Class has `#[ArgusLoad(...)]` attribute defining endpoint, cache level, TTL
- Class implements request payload and response parsing methods

### Entity ↔ Repo binding via LazyLoadRepo

For entities loaded through Argus, add the `#[LazyLoadRepo]` attribute on the entity:

```php
#[LazyLoadRepo(LazyLoadRepo::ARGUS, ArgusMyEntity::class)]
class MyEntity extends Entity
{
    // When this entity is lazy-loaded with ARGUS repo type,
    // it instantiates ArgusMyEntity and calls argusLoad()
}
```

Keep `uniqueKey()` deterministic for cache stability.

### Bidirectional conversion

- `fromEntity(DefaultObject &$entity): static` — copies all public properties from domain entity to Argus entity, recursively converting nested entities to their Argus equivalents
- `toEntity(): DefaultObject|null` — converts back from Argus entity to domain entity after loading

### Loading flow

```
Entity.lazyLoad()
  → ArgusEntity.argusLoad()
    → argusPrepareLoad()
      → constructApiOperations() recursively for this + children
      → ArgusApiCacheOperations.execute() — batch Redis lookup
      → ArgusApiOperations.execute() — parallel HTTP calls
    → handleLoadResponse() — parse result, populate properties
    → postProcessLoadResponse() — cache result, mark loaded
```

### Selective property loading

Load only specific child entities on demand:

```php
$argusEntity->setPropertiesToLoad(
    ArgusChildEntity::class,
    ArgusLoadingParameters::create(ArgusOtherChild::class, 'param1', 'param2')
);
$argusEntity->argusLoad();
```

Or configure properties that always load:

```php
ArgusMyEntity::setPropertiesToLoadAlways(ArgusChildEntity::class);
```

### Operation merging

Multiple similar operations can be merged into a single HTTP call. Set `mergelimit` on the operation payload to control batching (e.g., 10 keyword lookups merged into 1 API call).

## Patterns

### A) Simple Argus repo (non-LLM)

```php
#[ArgusLoad(
    loadEndpoint: 'POST:/service/path',
    cacheLevel: ArgusCache::CACHELEVEL_MEMORY_AND_DB,
    cacheTtl: ArgusCache::CACHE_TTL_ONE_DAY
)]
class ArgusMyEntity extends MyEntity
{
    use ArgusTrait;

    protected function getLoadPayload(): ?array
    {
        return ['body' => ['param' => $this->someProperty]];
    }

    public function handleLoadResponse(
        mixed &$callResponseData = null,
        ?ArgusApiOperation &$apiOperation = null
    ): void {
        $data = $this->getResponseDataFromArgusResponse($callResponseData);
        if ($data) {
            $this->resultProperty = $data->value;
        }
        $this->postProcessLoadResponse($callResponseData, $data !== null);
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic($this->someProperty);
    }
}
```

### B) Argus repo with CRUD operations

```php
#[ArgusLoad(
    loadEndpoint: 'GET:/resource/{id}',
    createEndpoint: 'POST:/resource',
    updateEndpoint: 'PUT:/resource/{id}',
    deleteEndpoint: 'DELETE:/resource/{id}',
)]
class ArgusResource extends Resource
{
    use ArgusTrait;

    protected function getLoadPayload(): ?array
    {
        return ['path' => ['id' => $this->id], 'body' => []];
    }

    protected function getCreatePayload(): ?array
    {
        return ['body' => ['name' => $this->name]];
    }

    // Path parameters in endpoints like {id} are automatically
    // replaced from the payload's 'path' array
}
```

Usage:

```php
$argus = new ArgusResource();
$argus->name = 'New Resource';
$argus->argusCreate();   // POST:/resource
$argus->argusUpdate();   // PUT:/resource/{id}
$argus->argusDelete();   // DELETE:/resource/{id}
```

### C) Multiple load endpoints

```php
#[ArgusLoad(loadEndpoint: [
    'GET:/metrics/impressions',
    'GET:/metrics/clicks',
])]
class ArgusMetrics extends Metrics
{
    // Each endpoint is called separately, results handled independently
}
```

Or with per-endpoint parameters:

```php
#[ArgusLoad(loadEndpoint: [
    ['GET:/metrics/daily' => ['query' => ['type' => 'impressions']]],
    ['GET:/metrics/daily' => ['query' => ['type' => 'clicks']]],
])]
```

### D) AI language model repo

For LLM-powered repos, use in combination with [ddd-ai](https://github.com/mgamadeus/ddd-ai):

```php
#[ArgusLoad(
    loadEndpoint: 'POST:/ai/openRouter/chatCompletions',
    cacheLevel: ArgusCache::CACHELEVEL_MEMORY_AND_DB,
    cacheTtl: ArgusCache::CACHELEVEL_NONE
)]
#[ArgusLanguageModel(
    defaultAIModelName: AIModel::MODEL_OPENAI_GPT5_2,
    defaultAIPromptName: 'My.Custom.Prompt',
)]
class ArgusMyAIEntity extends MyEntity
{
    use ArgusTrait, ArgusAILanguageModelTrait;

    public function getUserContent(): string|array
    {
        return 'The text to process';
        // Or for multimodal (text + images):
        // return [
        //     ['type' => 'text', 'text' => 'Describe this image'],
        //     ['type' => 'image_url', 'image_url' => ['image' => $photoEntity]],
        // ];
    }

    public function getAIPromptWithParametersApplied(): AIPrompt
    {
        $prompt = $this->getAIPrompt();
        $prompt->setParameter('locale', 'de-DE');
        return $prompt;
    }

    protected function applyLoadResult(string $resultText): void
    {
        $this->result = $resultText;
    }
}
```

`ArgusAILanguageModelTrait` handles:
- Model resolution via `AIModel::getService()->getAIModelByName()`
- Prompt resolution via `AIPrompt::getService()->getAIPromptByName()`
- Vendor-specific payload formation (OpenAI / Google Gemini)
- Image part normalization for multimodal payloads
- Response-format mode handling (`DEFAULT`, `JSON_OBJECT`)
- Token counting and cost tracking

## Service conventions

- Do **not** cache services in class properties — resolve inline via `Entity::getService()`
- Set `$service->throwErrors = true` before lookups requiring strict failure behavior
- Use `protected` visibility (not `private`) on methods and constants for extensibility

## Debugging

```php
// Deactivate all caching globally
ArgusLoad::$deactivateArgusCache = true;

// Log all API calls and responses
ArgusLoad::$logArgusCalls = true;

// Retrieve logged calls
$calls = ArgusApiOperations::getExecutedArgusCalls();

// Display call payload (echoes JSON and returns)
$argusEntity->argusLoad(displayCall: true);

// Display response (echoes JSON and returns)
$argusEntity->argusLoad(displayResponse: true);
```

## Request tracking

Every outgoing Argus request includes an `rc-tracking` header with:
- `accountId` — the authenticated account
- `requestUID` — unique ID for the current HTTP/CLI/messenger request (consistent across all operations in one request)
- `calledFrom` — call stack trace showing route/command/handler and method chain

## Also provides

- `BatchRequestDto` / `BatchResponseDto` — base DTOs for batch API controllers (used by other modules)
