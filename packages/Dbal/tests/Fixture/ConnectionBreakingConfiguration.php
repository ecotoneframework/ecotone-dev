<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture;

/**
 * Configuration for the ConnectionBreakingModule
 */
class ConnectionBreakingConfiguration
{
    private string $pointcut;
    private array $breakConnectionOnCalls;

    /**
     * Private constructor - use factory methods instead
     *
     * @param string $pointcut The pointcut type ('commit', 'message_acknowledge', or 'dead_letter_storage')
     * @param array $breakConnectionOnCalls Array of booleans indicating whether to break the connection on each call
     */
    private function __construct(string $pointcut, array $breakConnectionOnCalls = [])
    {
        $this->pointcut = $pointcut;
        $this->breakConnectionOnCalls = $breakConnectionOnCalls;
    }

    /**
     * Create a configuration that breaks the connection before commit
     *
     * @param array $breakConnectionOnCalls Array of booleans indicating whether to break the connection on each call
     */
    public static function createWithBreakBeforeCommit(array $breakConnectionOnCalls = []): self
    {
        return new self('commit', $breakConnectionOnCalls);
    }

    /**
     * Create a configuration that breaks the connection before message acknowledge
     *
     * @param array $breakConnectionOnCalls Array of booleans indicating whether to break the connection on each call
     */
    public static function createWithBreakBeforeMessageAcknowledge(array $breakConnectionOnCalls = []): self
    {
        return new self('message_acknowledge', $breakConnectionOnCalls);
    }

    /**
     * Get the pointcut type
     */
    public function getPointcutType(): string
    {
        return $this->pointcut;
    }

    /**
     * Get the array of booleans indicating whether to break the connection on each call
     *
     * @return array Array of booleans
     */
    public function getBreakConnectionOnCalls(): array
    {
        return $this->breakConnectionOnCalls;
    }

    /**
     * Whether this configuration is for breaking before commit
     */
    public function shouldBreakBeforeCommit(): bool
    {
        return $this->pointcut === 'commit';
    }

    /**
     * Whether this configuration is for breaking before message acknowledge
     */
    public function shouldBreakBeforeMessageAcknowledge(): bool
    {
        return $this->pointcut === 'message_acknowledge';
    }


}
