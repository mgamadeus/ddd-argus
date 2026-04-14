<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Batch\Base\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;
use stdClass;

class BatchRequestDto extends RequestDto
{
    /** @var stdClass The chat completions request payload for OpenAI API */
    #[Parameter(in: Parameter::BODY, required: true)]
    public stdClass $payload;
}
