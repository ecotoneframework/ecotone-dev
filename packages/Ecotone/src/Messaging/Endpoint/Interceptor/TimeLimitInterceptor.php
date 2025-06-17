<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Endpoint\Interceptor;

use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Endpoint\ConsumerInterceptor;
use Ecotone\Messaging\Scheduling\Clock;
use Ecotone\Messaging\Scheduling\Duration;
use Ecotone\Messaging\Scheduling\Timestamp;
use Throwable;

/**
 * Class LimitConsumedMessagesExtension
 * @package Ecotone\Messaging\Endpoint\Extension
 * @author Dariusz Gafka <support@simplycodedsoftware.com>
 */
/**
 * licence Apache-2.0
 */
class TimeLimitInterceptor implements ConsumerInterceptor
{
    private ?Timestamp $startTime;
    private Duration $timeout;

    /**
     * LimitMemoryUsageInterceptor constructor.
     * @param int $milliseconds
     * @throws \Ecotone\Messaging\MessagingException
     */
    public function __construct(private Clock $clock, private int $milliseconds)
    {
        if ($milliseconds <= 0) {
            throw ConfigurationException::create("Time limit is set to incorrect value: {$milliseconds}");
        }
        $this->timeout = Duration::milliseconds($this->milliseconds);
    }

    /**
     * @inheritDoc
     */
    public function onStartup(): void
    {
        $this->startTime = $this->clock->timestamp();
    }

    /**
     * @inheritDoc
     */
    public function shouldBeStopped(): bool
    {
        return $this->clock->timestamp()->diff($this->startTime)->isGreaterThan($this->timeout);
    }

    /**
     * @inheritDoc
     */
    public function preRun(): void
    {
    }

    /**
     * @inheritDoc
     */
    public function shouldBeThrown(Throwable $exception): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public function postRun(): void
    {
    }

    /**
     * @inheritDoc
     */
    public function postSend(): void
    {
    }
}
