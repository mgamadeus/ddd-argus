<?php

declare(strict_types=1);

namespace DDD\Tests\Argus\Support;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\Base\Repo\Argus\Traits\ArgusTrait;

/** Exposes the response envelope reading of ArgusTrait */
class ArgusEnvelopeProbe extends ValueObject
{
    use ArgusTrait;

    public function readResponseData(mixed $callResponseData): mixed
    {
        return $this->getResponseDataFromArgusResponse($callResponseData);
    }
}
