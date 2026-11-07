# Changelog

## 1.3.1

- Fix: since 1.3.0 passes the TTL to the cache, the empty entry of a failed load (`postProcessLoadResponse($data, false)`)
  lived as long as a successful answer — a failed geocode stuck for the day or month the class caches. An empty entry
  now lives at most `ArgusCache::FAILED_LOAD_CACHE_TTL_MAX` (one hour, the effective lifetime before 1.3.0). Transport
  failures that must not be cached at all still pass `cacheFailure: false`.
- Note for installations without a Redis Sentinel cache group: that group falls back to APCu, so since 1.3.0 a
  successful answer stays in APCu for the class's full `cacheTtl` (a month for `CACHE_TTL_ONE_MONTH`), per host.

## 1.3.0

Requires `mgamadeus/ddd` ^3.14.3.

- **Configuration first:** `Base.Repo.argus` (`microservices.baseUrl`, `requestSettings`, `auth`, `appendDebugFlags`,
  `trackingHeader`) in the application config; `ARGUS_API_ENDPOINT` / `ARGUS_REQUEST_SETTINGS` / `APP_DEBUG` only where
  the config has no value. Defaults keep the 1.2 behaviour.
- **Header order changed:** a call's own headers now win over the configured headers (1.2: configured headers
  overwrote them), and the default account's bearer token is added only to calls without their own `Authorization`.
- **Endpoints without request method are rejected:** an endpoint without `GET:` / `POST:` / … prefix throws an
  `InvalidArgumentException` before any request of the batch is sent (1.2 dropped such a call silently).
- **Timeouts:** `#[ArgusLoad(timeout:)]` reaches the HTTP client (1.2 ignored it); without it the class constant
  `DEFAULT_ARGUS_TIMEOUT` applies. Every instance works on its own copy of the attribute.
- **Cache:** `ArgusCache::set()` passes the TTL to both levels (1.2 used the cache group's default lifetime); cache
  writes substitute invalid UTF-8 and log a failed encoding; `CACHE_TTL_FIFTEEN_MINUTES`; typed constants.
- **Failures:** settle mode (`ArgusSettings::$keepFulfilledCallsWhenOneIsRejected`); per-operation
  `responseStatusCode`, `responseHeaders`, `rejectionReason`; `rejectionReasonForNon2xxAnswer()` and
  `postProcessLoadResponse(cacheFailure: false)` keep a failed answer out of the cache.
- **Response envelopes:** `getResponseDataFromArgusResponse()` also reads `status: result`, OpenAI Responses and Chat
  Completions payloads.
- **Private transport** (`ArgusApiOperation::PRIVATE_TRANSPORT`): no redirects, bounded streamed read, never logged.
- **Split execution:** `sendArgusLoadRequests()` / `advanceArgusLoadRequests()` / `advanceArgusLoadRequestsUntilWritten()`
  / `receiveArgusLoadResponses()` (`ArgusApiCallsInFlight`, `ArgusApiCallRequestWriteProgress`).
- **Call journal:** `ArgusApiOperations::$journalRequests`, `getCallJournal()` (`ArgusSentCallJournalEntries`),
  `getSentCallCountsByEndpoint()`.
- **`ArgusCacheInFlightComputation`:** a second reader joins a running computation of one cached entity.
- **Recorded runs:** `ArgusTransportRecording` (record / play / play tool calls / play with live tools; typed entries,
  stored format `toStorageArray()`), `RecordedRunIdCounterparts`; optional entity method `getArgusCallKind()`.
- **Low-speed abort:** optional entity method `buildTransferStallCurlOptions()`.
- **Worker reset:** `ArgusWorkerStateResetter` clears the executor statics, `ArgusLoad::$logArgusCalls` and the
  per-class `setPropertiesToLoadAlways()` selections before every message a Messenger worker consumes.
- Tests (`vendor/bin/phpunit`).
