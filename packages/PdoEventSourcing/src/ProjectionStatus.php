<?php

namespace Ecotone\EventSourcing;

/**
 * licence Apache-2.0
 */
final class ProjectionStatus
{
    public const RUNNING = 'running';
    public const DELETING = 'deleting';
    public const REBUILDING = 'rebuilding';
    public const IDLE = 'idle';

    public function __construct(
        private string $type
    ) {
    }

    public function getStatus(): string
    {
        return $this->type;
    }

    public static function RUNNING(): self
    {
        return new self(self::RUNNING);
    }

    public static function DELETING(): self
    {
        return new self(self::DELETING);
    }

    public static function REBUILDING(): self
    {
        return new self(self::REBUILDING);
    }

    public static function IDLE(): self
    {
        return new self(self::IDLE);
    }
}
