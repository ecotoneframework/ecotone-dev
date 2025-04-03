<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Prooph;

use Ecotone\EventSourcing\Prooph\GapDetection\DateInterval;
use Ecotone\Messaging\Config\Container\DefinedObject;
use Ecotone\Messaging\Config\Container\Definition;
use Prooph\EventStore\Pdo\Projection\GapDetection as ProophGapDetection;

/**
 * licence Apache-2.0
 */
final class GapDetection implements DefinedObject
{
    public function __construct(private ?array $retryConfig = null, private ?DateInterval $detectionWindow = null)
    {
    }

    public function getDefinition(): Definition
    {
        return new Definition(self::class, [$this->retryConfig, $this->detectionWindow?->getDefinition() ]);
    }

    public function build(): ProophGapDetection
    {
        return new ProophGapDetection($this->retryConfig, $this->detectionWindow?->build());
    }
}
