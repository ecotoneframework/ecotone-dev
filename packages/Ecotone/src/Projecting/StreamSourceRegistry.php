<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Ecotone\Projecting;

use RuntimeException;

final class StreamSourceRegistry
{
    /**
     * @param StreamSource[] $sources
     */
    public function __construct(
        private array $sources,
    ) {
    }

    public function getFor(string $projectionName): StreamSource
    {
        foreach ($this->sources as $source) {
            if ($source->canHandle($projectionName)) {
                return $source;
            }
        }

        throw new RuntimeException("No stream source found for projection: {$projectionName}");
    }
}

