<?php

declare (strict_types=1);

namespace DDD\Domain\Base\Repo\Argus\Utils;

use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Base\Repo\Argus\Enums\ArgusApiOperationType;
use DDD\Domain\Base\Repo\Argus\Traits\ArgusLoadTrait;
use DDD\Infrastructure\Traits\Serializer\SerializerTrait;
use InvalidArgumentException;

/**
 * Encapsels an Argus  API Operation Component, including function, params etc.
 *
 */
class ArgusApiOperation
{
    use SerializerTrait;

    /**
     * Payload key of the private transport: `[ArgusApiOperation::PRIVATE_TRANSPORT => ['response' => 'json'|'binary',
     * 'max_response_bytes' => 1…4194304]]`. The response is read streamed and bounded (error responses included), no
     * redirect is followed, and neither the call nor its answer reaches the call log or a displayed response. The key is
     * removed before the HTTP client receives the payload; a private operation cannot be merged.
     */
    public const string PRIVATE_TRANSPORT = '_argus_private_transport';

    public const string PRIVATE_RESPONSE_FORMAT_JSON = 'json';
    public const string PRIVATE_RESPONSE_FORMAT_BINARY = 'binary';
    public const int PRIVATE_RESPONSE_MAX_BYTES_LIMIT = 4194304;

    /** @var DefaultObject|null|ArgusLoadTrait */
    public DefaultObject|null $entity;

    public ?string $id;

    public ?string $endpoint;

    public ?array $params;

    public ?array $generalParams;

    public array|object|null $results;

    /** @var string[][]|null The response headers of the call this operation received (header name => values) */
    public ?array $responseHeaders = null;

    /** @var int|null The HTTP status code of the response this operation received; null until it arrived or when the call was rejected */
    public ?int $responseStatusCode = null;

    /** @var string|null Settle mode only: why the call was rejected at the transport level; null for a fulfilled call */
    public ?string $rejectionReason = null;

    /** @var string|null Private transport: the response format (json or binary); null for a normal operation */
    public ?string $privateResponseFormat = null;

    /** @var int Private transport: the most bytes read from the response */
    public int $privateResponseMaxBytes = 0;

    public int $mergelimit = 1;

    public function __construct(
        DefaultObject &$entity,
        string $id,
        string $endpoint,
        #[\SensitiveParameter] array &$payload,
    ) {
        if (isset($payload[self::PRIVATE_TRANSPORT])) {
            $transportOptions = $payload[self::PRIVATE_TRANSPORT];
            if (!is_array($transportOptions)
                || !in_array(
                    $transportOptions['response'] ?? null,
                    [self::PRIVATE_RESPONSE_FORMAT_JSON, self::PRIVATE_RESPONSE_FORMAT_BINARY],
                    true
                )
                || !is_int($transportOptions['max_response_bytes'] ?? null)
                || $transportOptions['max_response_bytes'] < 1
                || $transportOptions['max_response_bytes'] > self::PRIVATE_RESPONSE_MAX_BYTES_LIMIT
                || isset($payload['merge'])
                || !ArgusApiOperations::isEndpointWithRequestMethod($endpoint)
            ) {
                throw new InvalidArgumentException('Invalid private Argus transport options');
            }
            $this->privateResponseFormat = $transportOptions['response'];
            $this->privateResponseMaxBytes = $transportOptions['max_response_bytes'];
            unset($payload[self::PRIVATE_TRANSPORT]);
        }
        $this->entity = $entity;
        $this->id = $id;
        if (isset($payload['path'])) {
            $endpoint = self::getEndpointWithPathParametersApplied($endpoint, $payload);
            unset($payload['path']);
        }

        $this->endpoint = $endpoint;
        if (isset($payload['merge'])) {
            $this->params = $payload['params'];
            if (isset($payload['general_params'])) {
                $this->generalParams = $payload['general_params'];
            }

            $this->mergelimit = $payload['mergelimit'];
        } else {
            $this->params = $payload;
        }
    }

    /**
     * Returns endpoint with path parameters applied, e.g.
     * POST:/rc-business-listings/listings/{directoryName} and path patameters  ['path' => ['directoryName' => 'google']]
     * results in POST:/rc-business-listings/listings/google
     * @param string $endpoint
     * @param array $payload
     * @return array|string|string[]
     */
    public static function getEndpointWithPathParametersApplied(string $endpoint, array &$payload)
    {
        if (!isset($payload['path'])) {
            return $endpoint;
        }
        foreach ($payload['path'] as $pathParamterName => $pathParamterValue) {
            $endpoint = str_replace("{{$pathParamterName}}", $pathParamterValue, $endpoint);
        }
        return $endpoint;
    }

    /**
     * Handles the response of the call by calling the corresponding Method on the initiating ArgusEntity
     * @param mixed $results
     * @param ArgusApiOperationType $operationType
     * @return void
     */
    public function handleResponse(
        #[\SensitiveParameter] mixed $results,
        ArgusApiOperationType $operationType = ArgusApiOperationType::LOAD
    ): void {
        $this->results = $results;
        if ($operationType === ArgusApiOperationType::LOAD) {
            $this->entity->handleLoadResponse($results, $this);
            return;
        }
        if ($operationType === ArgusApiOperationType::UPDATE) {
            $this->entity->handleUpdateResponse($results, $this);
            return;
        }
        if ($operationType === ArgusApiOperationType::DELETE) {
            $this->entity->handleDeleteResponse($results, $this);
            return;
        }
        if ($operationType === ArgusApiOperationType::CREATE) {
            $this->entity->handleCreateResponse($results, $this);
            return;
        }
        if ($operationType === ArgusApiOperationType::SYNCHRONIZE) {
            $this->entity->handleSynchronizeResponse($results, $this);
        }
    }

    /**
     * @return string
     */
    public function uniqueKey(): string
    {
        return $this->id;
    }
}
