<?php

declare(strict_types=1);

namespace DDD\Tests\Argus\Support;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\Base\Repo\Argus\Enums\ArgusApiOperationType;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusApiOperation;

/** The entity behind an operation in executor tests: keeps what its operation received */
class ArgusProbeEntity extends ValueObject
{
    public mixed $receivedResults = null;

    public bool $responseHandled = false;

    public ?string $callKind = null;

    public function handleLoadResponse(mixed &$callResponseData = null, ?ArgusApiOperation &$apiOperation = null): void
    {
        $this->receivedResults = $callResponseData;
        $this->responseHandled = true;
    }

    public function getArgusCallKind(): ?string
    {
        return $this->callKind;
    }

    public function createOperation(string $id, string $endpoint, array $payload = []): ArgusApiOperation
    {
        $argusProbeEntity = $this;
        return new ArgusApiOperation($argusProbeEntity, $id, $endpoint, $payload);
    }
}
