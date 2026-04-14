<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\Argus\Enums;

enum ArgusApiOperationType {
    case LOAD;
    case UPDATE;
    case DELETE;
    case CREATE;
    case PATCH;
    case SYNCHRONIZE;
}
