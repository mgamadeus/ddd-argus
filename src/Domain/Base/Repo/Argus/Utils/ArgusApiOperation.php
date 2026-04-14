<?php

declare (strict_types=1);

namespace DDD\Domain\Base\Repo\Argus\Utils;

use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Base\Repo\Argus\Enums\ArgusApiOperationType;
use DDD\Domain\Base\Repo\Argus\Traits\ArgusLoadTrait;
use DDD\Infrastructure\Traits\Serializer\SerializerTrait;

/**
 * Encapsels an Argus  API Operation Component, including function, params etc.
 *
 */
class ArgusApiOperation
{
    use SerializerTrait;

    /** @var DefaultObject|null|ArgusLoadTrait */
    public DefaultObject|null $entity;

    public ?string $id;

    public ?string $endpoint;

    public ?array $params;

    public ?array $generalParams;

    public array|object|null $results;

    public int $mergelimit = 1;

    public function __construct(
        DefaultObject &$entity,
        string $id,
        string $endpoint,
        array &$payload,
    ) {
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
        mixed $results,
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
