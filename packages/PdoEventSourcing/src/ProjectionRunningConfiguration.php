<?php

namespace Ecotone\EventSourcing;

use Assert\Assertion;
use Ecotone\EventSourcing\Prooph\GapDetection;
use Ecotone\EventSourcing\Prooph\Metadata\MetadataMatcher;
use Ecotone\Messaging\Config\Container\DefinedObject;
use Ecotone\Messaging\Config\Container\Definition;
use InvalidArgumentException;
use Prooph\EventStore\Pdo\Projection\PdoEventStoreReadModelProjector;

/**
 * licence Apache-2.0
 */
class ProjectionRunningConfiguration implements DefinedObject
{
    private const EVENT_DRIVEN = 'event-driven';
    private const POLLING = 'polling';

    public const OPTION_INITIALIZE_ON_STARTUP = 'initializeOnStartup';
    public const DEFAULT_INITIALIZE_ON_STARTUP = true;

    public const OPTION_AMOUNT_OF_CACHED_STREAM_NAMES = 'cache_size';
    public const DEFAULT_AMOUNT_OF_CACHED_STREAM_NAMES = 1000;

    public const OPTION_WAIT_BEFORE_CALLING_ES_WHEN_NO_EVENTS_FOUND = 'sleep';
    public const DEFAULT_WAIT_BEFORE_CALLING_ES_WHEN_NO_EVENTS_FOUND = 0;

    public const OPTION_PERSIST_CHANGES_AFTER_AMOUNT_OF_OPERATIONS = 'persist_block_size';
    public const DEFAULT_PERSIST_CHANGES_AFTER_AMOUNT_OF_OPERATIONS = 1000;

    public const OPTION_PROJECTION_LOCK_TIMEOUT = 'lock_timeout_ms';
    public const DEFAULT_PROJECTION_LOCK_TIMEOUT = 1000;

    public const OPTION_UPDATE_LOCK_TIMEOUT_AFTER = 'update_lock_threshold';
    public const DEFAULT_UPDATE_LOCK_TIMEOUT_AFTER = 0;

    public const OPTION_LOAD_COUNT = 'load_count';
    public const DEFAULT_LOAD_COUNT = null;

    public const OPTION_METADATA_MATCHER = 'metadata_matcher';
    public const DEFAULT_METADATA_MATCHER = null;

    public const OPTION_IS_TESTING_SETUP = 'isTestingSetup';
    public const DEFAULT_IS_TESTING_SETUP = false;

    public const OPTION_GAP_DETECTION = PdoEventStoreReadModelProjector::OPTION_GAP_DETECTION;

    private array $options;
    private bool $isTestingSetup = false;

    public function __construct(
        private string $projectionName,
        private string $runningType,
        array $options = [],
    ) {
        $this->options = ($options !== []) ? $options : [
            self::OPTION_INITIALIZE_ON_STARTUP => self::DEFAULT_INITIALIZE_ON_STARTUP,
            self::OPTION_AMOUNT_OF_CACHED_STREAM_NAMES => self::DEFAULT_AMOUNT_OF_CACHED_STREAM_NAMES,
            self::OPTION_WAIT_BEFORE_CALLING_ES_WHEN_NO_EVENTS_FOUND => self::DEFAULT_WAIT_BEFORE_CALLING_ES_WHEN_NO_EVENTS_FOUND,
            self::OPTION_PERSIST_CHANGES_AFTER_AMOUNT_OF_OPERATIONS => self::DEFAULT_PERSIST_CHANGES_AFTER_AMOUNT_OF_OPERATIONS,
            self::OPTION_PROJECTION_LOCK_TIMEOUT => self::DEFAULT_PROJECTION_LOCK_TIMEOUT,
            self::OPTION_UPDATE_LOCK_TIMEOUT_AFTER => self::DEFAULT_UPDATE_LOCK_TIMEOUT_AFTER,
            self::OPTION_IS_TESTING_SETUP => self::DEFAULT_IS_TESTING_SETUP,
            self::OPTION_LOAD_COUNT => self::DEFAULT_LOAD_COUNT,
            self::OPTION_METADATA_MATCHER => self::DEFAULT_METADATA_MATCHER,
            self::OPTION_GAP_DETECTION => new GapDetection(retryConfig: [0, 5, 50, 500, 800], detectionWindow: new GapDetection\DateInterval('PT10S')),
        ];
    }

    public function getDefinition(): Definition
    {
        $options = $this->options;
        foreach ($this->options as $key => $value) {
            $options[$key] = match ($key) {
                self::OPTION_METADATA_MATCHER, self::OPTION_GAP_DETECTION => $value?->getDefinition(),
                default => $value,
            };
        }

        return new Definition(
            self::class,
            [
                $this->projectionName,
                $this->runningType,
                $options,
            ]
        );
    }

    public static function createEventDriven(string $projectionName): static
    {
        return new self($projectionName, self::EVENT_DRIVEN);
    }

    public static function createPolling(string $projectionName): static
    {
        return new self($projectionName, self::POLLING);
    }

    public function getProjectionName(): string
    {
        return $this->projectionName;
    }

    public function isPolling(): bool
    {
        return $this->runningType === self::POLLING;
    }

    public function isEventDriven(): bool
    {
        return $this->runningType === self::EVENT_DRIVEN;
    }

    public function isTestingSetup(): bool
    {
        return $this->isTestingSetup;
    }

    public function withOption(string $key, mixed $value): static
    {
        if ($key === self::OPTION_METADATA_MATCHER && $value !== null) {
            Assertion::isInstanceOf($value, MetadataMatcher::class);
        }

        if ($key === self::OPTION_GAP_DETECTION && $value !== null) {
            Assertion::isInstanceOf($value, GapDetection::class);
        }

        $self = clone $this;
        $self->options[$key] = $value;

        return $self;
    }

    public function withOptions(array $options): static
    {
        $self = clone $this;
        $self->options = $options;

        return $self;
    }

    public function getOptions(): array
    {
        return $this->options;
    }

    public function disableTransactions(): void
    {
    }

    public function getOption(string $key): mixed
    {
        return array_key_exists($key, $this->options) ? $this->options[$key] : throw new InvalidArgumentException(sprintf('Option %s do not exists. Did you forget to set it with %s', $key, self::class));
    }

    /**
     * @deprecated use self::getOption(self::OPTION_INITIALIZE_ON_STARTUP) instead
     */
    public function isInitializedOnStartup(): bool
    {
        return (bool) $this->getOption(self::OPTION_INITIALIZE_ON_STARTUP);
    }

    /**
     * @deprecated use self::getOption(self::OPTION_AMOUNT_OF_CACHED_STREAM_NAMES) instead
     */
    public function getAmountOfCachedStreamNames(): int
    {
        return (int) $this->getOption(self::OPTION_AMOUNT_OF_CACHED_STREAM_NAMES);
    }

    /**
     * @deprecated use self::getOption(self::OPTION_WAIT_BEFORE_CALLING_ES_WHEN_NO_EVENTS_FOUND) instead
     */
    public function getWaitBeforeCallingESWhenNoEventsFound(): int
    {
        return (int) $this->getOption(self::OPTION_WAIT_BEFORE_CALLING_ES_WHEN_NO_EVENTS_FOUND);
    }

    /**
     * @deprecated use self::getOption(self::OPTION_PROJECTION_LOCK_TIMEOUT) instead
     */
    public function getProjectionLockTimeout(): int
    {
        return (int) $this->getOption(self::OPTION_PROJECTION_LOCK_TIMEOUT);
    }

    /**
     * @deprecated use self::getOption(self::DEFAULT_UPDATE_LOCK_TIMEOUT_AFTER) instead
     */
    public function getUpdateLockTimeoutAfter(): int
    {
        return (int) $this->getOption(self::DEFAULT_UPDATE_LOCK_TIMEOUT_AFTER);
    }

    /**
     * @deprecated use self::getOption(self::OPTION_PERSIST_CHANGES_AFTER_AMOUNT_OF_OPERATIONS) instead
     */
    public function getPersistChangesAfterAmountOfOperations(): int
    {
        return (int) $this->getOption(self::OPTION_PERSIST_CHANGES_AFTER_AMOUNT_OF_OPERATIONS);
    }

    /**
     * @deprecated use self::getOption(self::OPTION_LOAD_COUNT) instead
     */
    public function getLoadCount(): ?int
    {
        return $this->getOption(self::OPTION_LOAD_COUNT) !== null ? (int) $this->getOption(self::OPTION_LOAD_COUNT) : null;
    }

    /**
     * @deprecated use self::withOptions or self::withOption(self::OPTION_INITIALIZE_ON_STARTUP, $value) instead
     */
    public function withInitializeOnStartup(bool $initializeOnStartup): static
    {
        return $this->withOption(self::OPTION_INITIALIZE_ON_STARTUP, $initializeOnStartup);
    }

    /**
     * @deprecated use self::withOptions or self::withOption(self::OPTION_AMOUNT_OF_CACHED_STREAM_NAMES, $value) instead
     */
    public function withAmountOfCachedStreamNames(int $amountOfCachedStreamNames): static
    {
        return $this->withOption(self::OPTION_AMOUNT_OF_CACHED_STREAM_NAMES, $amountOfCachedStreamNames);
    }

    /**
     * @param int $waitBeforeCallingESWhenNoEventsFound in milliseconds
     *
     * @deprecated use self::withOptions or self::withOption(self::OPTION_WAIT_BEFORE_CALLING_ES_WHEN_NO_EVENTS_FOUND, $value) instead
     */
    public function withWaitBeforeCallingESWhenNoEventsFound(int $waitBeforeCallingESWhenNoEventsFound): static
    {
        return $this->withOption(self::OPTION_WAIT_BEFORE_CALLING_ES_WHEN_NO_EVENTS_FOUND, $waitBeforeCallingESWhenNoEventsFound);
    }

    /**
     * @param int $projectionLockTimeout in milliseconds
     *
     * @deprecated use self::withOptions or self::withOption(self::OPTION_PROJECTION_LOCK_TIMEOUT, $value) instead
     */
    public function withProjectionLockTimeout(int $projectionLockTimeout): static
    {
        return $this->withOption(self::OPTION_PROJECTION_LOCK_TIMEOUT, $projectionLockTimeout);
    }

    /**
     * @param int $updateLockTimeoutAfter in milliseconds
     *
     * @deprecated use self::withOptions or self::withOption(self::OPTION_UPDATE_LOCK_TIMEOUT_AFTER, $value) instead
     */
    public function withUpdateLockTimeoutAfter(int $updateLockTimeoutAfter): static
    {
        return $this->withOption(self::OPTION_UPDATE_LOCK_TIMEOUT_AFTER, $updateLockTimeoutAfter);
    }

    /**
     * @deprecated use self::withOptions or self::withOption(self::OPTION_LOAD_COUNT, $value) instead
     */
    public function withLoadCount(?int $loadCount): static
    {
        return $this->withOption(self::OPTION_LOAD_COUNT, $loadCount);
    }

    public function withTestingSetup(): static
    {
        $self = $this
            ->withOption(self::OPTION_WAIT_BEFORE_CALLING_ES_WHEN_NO_EVENTS_FOUND, 0)
            ->withOption(self::OPTION_INITIALIZE_ON_STARTUP, true)
            ->withOption(self::OPTION_PROJECTION_LOCK_TIMEOUT, 0)
            ->withOption(self::OPTION_UPDATE_LOCK_TIMEOUT_AFTER, 0)
        ;

        $self->isTestingSetup = true;

        return $self;
    }
}
