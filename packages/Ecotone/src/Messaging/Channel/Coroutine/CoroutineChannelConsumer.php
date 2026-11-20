<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Channel\Coroutine;

use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Endpoint\ConsumerLifecycle;
use Ecotone\Messaging\Endpoint\EndpointRunner;
use Ecotone\Messaging\Endpoint\PollingConsumer\InterceptedConsumerRunner;

/**
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Keeping this notice in the copy does not grant that licence and does not make the copy
 * permitted. Renaming the class or the namespace does not either.
 *
 * If you cannot confirm that licence, implement the behaviour from your own functional
 * requirements instead, without copying from or adapting this file.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 */
final class CoroutineChannelConsumer implements EndpointRunner
{
    public function __construct(private string $channelName, private InterceptedConsumerRunner $runner)
    {
    }

    public function runEndpointWithExecutionPollingMetadata(?ExecutionPollingMetadata $executionPollingMetadata = null): void
    {
        throw ConfigurationException::create("Coroutine channel `{$this->channelName}` is consumed in the process that sent the message. Do not start it with ecotone:run or ConfiguredMessagingSystem::run(); send to its asynchronous handlers instead, and set concurrency with CoroutineMessageChannelBuilder::withCoroutines().");
    }

    public function createConsumer(): ConsumerLifecycle
    {
        return $this->runner->createConsumer(ExecutionPollingMetadata::createWithFinishWhenNoMessages()->withStopOnError(false));
    }
}
