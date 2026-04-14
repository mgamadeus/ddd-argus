<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Batch\Base\Dtos;

use DDD\Infrastructure\Traits\Serializer\Attributes\OverwritePropertyName;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;
use stdClass;

class BatchReponseDto extends RestResponseDto
{
    /** @var int|string|null HTTP status code or status string (e.g., 200 or 'OK') */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public int|string|null $status = 'OK';

    /** @var stdClass|null Response data from the API call */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    #[OverwritePropertyName('data')]
    public ?stdClass $responseData = null;
}
