<?php

declare(strict_types=1);

namespace Ecotone\Projecting;

/**
 * licence Enterprise
 */
final class EnterpriseProjectionNameHeader implements ProjectionNameHeader
{
    public function applyTo(array $headers, string $projectionName): array
    {
        $headers[ProjectingHeaders::PROJECTION_NAME] = $projectionName;

        return $headers;
    }
}
