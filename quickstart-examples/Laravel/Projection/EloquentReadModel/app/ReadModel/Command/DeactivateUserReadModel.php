<?php

/*
 * licence Apache-2.0
 */

declare(strict_types=1);

namespace App\ReadModel\Command;

use Ecotone\Modelling\Attribute\TargetIdentifier;

final readonly class DeactivateUserReadModel
{
    public function __construct(
        #[TargetIdentifier('user_id')] public string $userId,
    ) {
    }
}
