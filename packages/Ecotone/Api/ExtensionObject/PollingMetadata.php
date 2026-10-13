<?php

declare(strict_types=1);

namespace Ecotone\Api\ExtensionObject;

use Ecotone\Messaging\Config\Container\DefinedObject;
use Ecotone\Messaging\Config\Container\Definition;

/**
 * licence Apache-2.0
 */
final class PollingMetadata implements DefinedObject
{
    public const DEFAULT_MAX_MESSAGES_PER_POLL = 1;

    public const DEFAULT_FIXED_RATE = 1000;

    public const DEFAULT_INITIAL_DELAY = 0;
    public const DEFAULT_MEMORY_LIMIT_MEGABYTES = 1024;
    public const DEFAULT_HANDLED_MESSAGE_LIMIT = 0;
    public const DEFAULT_EXECUTION_LIMIT = 0;
    public const DEFAULT_EXECUTION_TIME_LIMIT_IN_MILLISECONDS = 0;
    public const DEFAULT_STOP_ON_ERROR = false;
    public const DEFAULT_FINISH_WHEN_NO_MESSAGES = false;

    private bool $withSignalInterceptors;
    private ?string $polledChannelName = null;


    public function __construct(
        private string $endpointId,
        private string $cron = '',
        private string $errorChannelName = '',
        private bool $isErrorChannelEnabled = true,
        private int $fixedRateInMilliseconds = self::DEFAULT_FIXED_RATE,
        private int $initialDelayInMilliseconds = self::DEFAULT_INITIAL_DELAY,
        private int $handledMessageLimit = self::DEFAULT_HANDLED_MESSAGE_LIMIT,
        private int $memoryLimitInMegabytes = self::DEFAULT_MEMORY_LIMIT_MEGABYTES,
        private int $executionAmountLimit = self::DEFAULT_EXECUTION_LIMIT,
        private int $maxMessagePerPoll = self::DEFAULT_MAX_MESSAGES_PER_POLL,
        private int $executionTimeLimitInMilliseconds = self::DEFAULT_EXECUTION_TIME_LIMIT_IN_MILLISECONDS,
        private ?RetryTemplateBuilder $connectionRetryTemplate = null,
        ?bool $withSignalInterceptors = null,
        private string $triggerReferenceName = '',
        private string $taskExecutorName = '',
        private bool $stopOnError = self::DEFAULT_STOP_ON_ERROR,
        private bool $finishWhenNoMessages = self::DEFAULT_FINISH_WHEN_NO_MESSAGES,
        private ?string $fixedRateExpression = null,
        private ?string $cronExpression = null,
    ) {
        $this->withSignalInterceptors = $withSignalInterceptors ?? extension_loaded('pcntl');
    }

    public static function create(string $endpointId): self
    {
        return new self($endpointId);
    }

    /**
     * @param int $executionTimeLimitInMilliseconds Maximum execution of running consumer. Take under that while debugging with xdebug it should be set to 0 to avoid exiting consumer to early.
     */
    public function withTestingSetup(int $handledMessageLimit = 1, int $executionTimeLimitInMilliseconds = 100, bool $stopOnError = true): self
    {
        $pollingMetadata = $this
            ->setHandledMessageLimit($handledMessageLimit)
            ->setStopOnError($stopOnError);

        if ($executionTimeLimitInMilliseconds) {
            $pollingMetadata = $pollingMetadata
                ->setExecutionTimeLimitInMilliseconds($executionTimeLimitInMilliseconds);
        }

        return $pollingMetadata;
    }

    public static function createFrom(PollingMetadata $pollingMetadata): self
    {
        return clone $pollingMetadata;
    }

    public function setStopOnError(bool $stopOnError): self
    {
        $copy = $this->createCopy();
        $copy->stopOnError = $stopOnError;

        return $copy;
    }

    public function isStoppedOnError(): bool
    {
        return $this->stopOnError;
    }

    public function setCron(string $cron): PollingMetadata
    {
        $copy = $this->createCopy();
        $copy->cron = $cron;

        return $copy;
    }

    public function setErrorChannelName(string $errorChannelName): PollingMetadata
    {
        $copy = $this->createCopy();
        $copy->errorChannelName = $errorChannelName;

        return $copy;
    }

    public function applyExecutionPollingMetadata(?ExecutionPollingMetadata $executionPollingMetadata): self
    {
        if (! $executionPollingMetadata) {
            return $this;
        }

        $copy = $this->createCopy();

        if (! is_null($executionPollingMetadata->stopOnError)) {
            $copy = $copy->setStopOnError($executionPollingMetadata->stopOnError);
        }
        if (! is_null($executionPollingMetadata->handledMessageLimit)) {
            $copy = $copy->setHandledMessageLimit($executionPollingMetadata->handledMessageLimit);
        }
        if (! is_null($executionPollingMetadata->executionTimeLimitInMilliseconds)) {
            $copy = $copy->setExecutionTimeLimitInMilliseconds($executionPollingMetadata->executionTimeLimitInMilliseconds);
        }
        if (! is_null($executionPollingMetadata->memoryLimitInMegabytes)) {
            $copy = $copy->setMemoryLimitInMegaBytes($executionPollingMetadata->memoryLimitInMegabytes);
        }
        if (! is_null($executionPollingMetadata->cron)) {
            $copy = $copy->setCron($executionPollingMetadata->cron);
        }
        if (! is_null($executionPollingMetadata->finishWhenNoMessages)) {
            $copy = $copy->setFinishWhenNoMessages($executionPollingMetadata->finishWhenNoMessages);
        }
        if (! is_null($executionPollingMetadata->executionAmountLimit)) {
            $copy = $copy->setExecutionAmountLimit($executionPollingMetadata->executionAmountLimit);
        }

        return $copy;
    }

    public function setMaxMessagePerPoll(int $maxMessagePerPoll): PollingMetadata
    {
        $copy = $this->createCopy();
        $copy->maxMessagePerPoll = $maxMessagePerPoll;

        return $copy;
    }

    public function setTriggerReferenceName(string $triggerReferenceName): PollingMetadata
    {
        $copy = $this->createCopy();
        $copy->triggerReferenceName = $triggerReferenceName;

        return $copy;
    }

    public function setTaskExecutorName(string $taskExecutorName): PollingMetadata
    {
        $copy = $this->createCopy();
        $copy->taskExecutorName = $taskExecutorName;

        return $copy;
    }

    public function setEnabledErrorChannel(bool $isErrorChannelEnabled): PollingMetadata
    {
        $copy = $this->createCopy();
        $copy->isErrorChannelEnabled = $isErrorChannelEnabled;

        return $copy;
    }

    public function isErrorChannelEnabled(): bool
    {
        return $this->isErrorChannelEnabled;
    }

    public function setFixedRateInMilliseconds(int $fixedRateInMilliseconds): PollingMetadata
    {
        $copy = $this->createCopy();
        $copy->fixedRateInMilliseconds = $fixedRateInMilliseconds;

        return $copy;
    }

    public function setInitialDelayInMilliseconds(int $initialDelayInMilliseconds): PollingMetadata
    {
        $copy = $this->createCopy();
        $copy->initialDelayInMilliseconds = $initialDelayInMilliseconds;

        return $copy;
    }

    public function setConnectionRetryTemplate(RetryTemplateBuilder $retryTemplateBuilder): PollingMetadata
    {
        $copy = $this->createCopy();
        $copy->connectionRetryTemplate = $retryTemplateBuilder;

        return $copy;
    }

    public function getConnectionRetryTemplate(): ?RetryTemplateBuilder
    {
        return $this->connectionRetryTemplate;
    }

    /**
     * @return int return 0, if no timeout given
     */
    public function getExecutionTimeLimitInMilliseconds(): int
    {
        return $this->executionTimeLimitInMilliseconds;
    }

    public function setExecutionTimeLimitInMilliseconds(int $executionTimeLimitInMilliseconds): PollingMetadata
    {
        $copy = $this->createCopy();
        $copy->executionTimeLimitInMilliseconds = $executionTimeLimitInMilliseconds;

        return $copy;
    }

    public function setHandledMessageLimit(int $handledMessageLimit): PollingMetadata
    {
        $copy = $this->createCopy();
        $copy->handledMessageLimit = $handledMessageLimit;

        return $copy;
    }

    public function setMemoryLimitInMegaBytes(int $memoryLimit): PollingMetadata
    {
        $copy = $this->createCopy();
        $copy->memoryLimitInMegabytes = $memoryLimit;

        return $copy;
    }

    public function setSignalInterceptors(bool $withSignalInterceptors): PollingMetadata
    {
        $copy = $this->createCopy();
        $copy->withSignalInterceptors = $withSignalInterceptors;

        return $copy;
    }

    /**
     * How many times Consumer should be executed before it will be stopped.
     * It takes under consideration polling when no messages are received.
     */
    public function setExecutionAmountLimit(int $executionAmountLimit): PollingMetadata
    {
        $copy = $this->createCopy();
        $copy->executionAmountLimit = $executionAmountLimit;

        return $copy;
    }

    public function setFinishWhenNoMessages(bool $finishWhenNoMessages): PollingMetadata
    {
        $copy = $this->createCopy();
        $copy->finishWhenNoMessages = $finishWhenNoMessages;
        $copy->handledMessageLimit = 0;
        $copy->executionAmountLimit = 0;
        $copy->executionTimeLimitInMilliseconds = 0;

        return $copy;
    }

    /**1
     * @return int
     */
    public function getExecutionAmountLimit(): int
    {
        return $this->executionAmountLimit;
    }

    public function isWithSignalInterceptors(): bool
    {
        return $this->withSignalInterceptors;
    }

    public function getMemoryLimitInMegabytes(): int
    {
        return $this->memoryLimitInMegabytes;
    }

    public function getCron(): string
    {
        return $this->cron;
    }

    public function getErrorChannelName(): string
    {
        return $this->stopOnError ? '' : $this->errorChannelName;
    }

    public function getMaxMessagePerPoll(): int
    {
        return $this->maxMessagePerPoll;
    }

    public function getTriggerReferenceName(): string
    {
        return $this->triggerReferenceName;
    }

    public function getTaskExecutorName(): string
    {
        return $this->taskExecutorName;
    }

    public function getFixedRateInMilliseconds(): int
    {
        return $this->fixedRateInMilliseconds;
    }

    public function getInitialDelayInMilliseconds(): int
    {
        return $this->initialDelayInMilliseconds;
    }

    public function getEndpointId(): string
    {
        return $this->endpointId;
    }

    public function getHandledMessageLimit(): int
    {
        return $this->handledMessageLimit;
    }

    public function finishWhenNoMessages(): bool
    {
        return $this->finishWhenNoMessages;
    }

    public function getFixedRateExpression(): ?string
    {
        return $this->fixedRateExpression;
    }

    public function getCronExpression(): ?string
    {
        return $this->cronExpression;
    }

    public function getPolledChannelName(): ?string
    {
        return $this->polledChannelName;
    }

    public function setPolledChannelName(string $polledChannelName): self
    {
        $copy = $this->createCopy();
        $copy->polledChannelName = $polledChannelName;

        return $copy;
    }

    public function hasFixedRateExpression(): bool
    {
        return $this->fixedRateExpression !== null;
    }

    public function hasCronExpression(): bool
    {
        return $this->cronExpression !== null;
    }

    public function setFixedRateExpression(?string $fixedRateExpression): PollingMetadata
    {
        $copy = $this->createCopy();
        $copy->fixedRateExpression = $fixedRateExpression;

        return $copy;
    }

    public function setCronExpression(?string $cronExpression): PollingMetadata
    {
        $copy = $this->createCopy();
        $copy->cronExpression = $cronExpression;

        return $copy;
    }

    private function createCopy(): self
    {
        return clone $this;
    }

    public function getDefinition(): Definition
    {
        return new Definition(self::class, [
            $this->endpointId,
            $this->cron,
            $this->errorChannelName,
            $this->isErrorChannelEnabled,
            $this->fixedRateInMilliseconds,
            $this->initialDelayInMilliseconds,
            $this->handledMessageLimit,
            $this->memoryLimitInMegabytes,
            $this->executionAmountLimit,
            $this->maxMessagePerPoll,
            $this->executionTimeLimitInMilliseconds,
            $this->connectionRetryTemplate,
            $this->withSignalInterceptors,
            $this->triggerReferenceName,
            $this->taskExecutorName,
            $this->stopOnError,
            $this->finishWhenNoMessages,
            $this->fixedRateExpression,
            $this->cronExpression,
        ]);
    }
}
