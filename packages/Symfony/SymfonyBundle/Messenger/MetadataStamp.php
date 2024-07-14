<?php

declare(strict_types=1);

namespace Ecotone\SymfonyBundle\Messenger;

use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * licence Apache-2.0
 */
final class MetadataStamp implements StampInterface
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(private array $metadata)
    {

    }

    /**
     * @return array<string, mixed> $metadata
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }
}
