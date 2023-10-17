<?php

namespace Ecotone\Messaging\Endpoint;

use Ecotone\Messaging\Handler\Recoverability\RetryTemplateBuilder;

class CompilationPollingMetadata
{
    public function __construct(
        private string $endpointId,
//        private string $cron,
        private string $errorChannelName,
        private bool $isErrorChannelEnabled,
        private int $fixedRateInMilliseconds,
        private int $initialDelayInMilliseconds,
//        private int $handledMessageLimit,
//        private int $memoryLimitInMegabytes,
        private int $executionAmountLimit,
        private int $maxMessagePerPoll,
//        private int $executionTimeLimitInMilliseconds,
        private ?RetryTemplateBuilder $connectionRetryTemplate,
        private bool $withSignalInterceptors,
        private string $triggerReferenceName,
        private string $taskExecutorName,
//        private bool $stopOnError,
//        private bool $finishWhenNoMessages,
    )
    {
    }

    public static function fromPollingMetadata(PollingMetadata $pollingMetadata): self
    {
        return new self(
            $pollingMetadata->getEndpointId(),
//            $pollingMetadata->getCron(),
            $pollingMetadata->getErrorChannelName(),
            $pollingMetadata->isErrorChannelEnabled(),
            $pollingMetadata->getFixedRateInMilliseconds(),
            $pollingMetadata->getInitialDelayInMilliseconds(),
//            $pollingMetadata->getHandledMessageLimit(),
//            $pollingMetadata->getMemoryLimitInMegabytes(),
            $pollingMetadata->getExecutionAmountLimit(),
            $pollingMetadata->getMaxMessagePerPoll(),
//            $pollingMetadata->getExecutionTimeLimitInMilliseconds(),
            $pollingMetadata->getConnectionRetryTemplate(),
            $pollingMetadata->isWithSignalInterceptors(),
            $pollingMetadata->getTriggerReferenceName(),
            $pollingMetadata->getTaskExecutorName(),
        );
    }

    /**
     * @return string
     */
    public function getEndpointId(): string
    {
        return $this->endpointId;
    }

    /**
     * @return string
     */
    public function getErrorChannelName(): string
    {
        return $this->errorChannelName;
    }

    /**
     * @return bool
     */
    public function isErrorChannelEnabled(): bool
    {
        return $this->isErrorChannelEnabled;
    }

    /**
     * @return int
     */
    public function getFixedRateInMilliseconds(): int
    {
        return $this->fixedRateInMilliseconds;
    }

    /**
     * @return int
     */
    public function getInitialDelayInMilliseconds(): int
    {
        return $this->initialDelayInMilliseconds;
    }

    /**
     * @return int
     */
    public function getExecutionAmountLimit(): int
    {
        return $this->executionAmountLimit;
    }

    /**
     * @return int
     */
    public function getMaxMessagePerPoll(): int
    {
        return $this->maxMessagePerPoll;
    }

    /**
     * @return RetryTemplateBuilder|null
     */
    public function getConnectionRetryTemplate(): ?RetryTemplateBuilder
    {
        return $this->connectionRetryTemplate;
    }

    /**
     * @return bool
     */
    public function isWithSignalInterceptors(): bool
    {
        return $this->withSignalInterceptors;
    }

    /**
     * @return string
     */
    public function getTriggerReferenceName(): string
    {
        return $this->triggerReferenceName;
    }

    /**
     * @return string
     */
    public function getTaskExecutorName(): string
    {
        return $this->taskExecutorName;
    }
}