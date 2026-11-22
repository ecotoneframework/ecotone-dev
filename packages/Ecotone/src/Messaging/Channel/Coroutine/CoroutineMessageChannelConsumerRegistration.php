<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Channel\Coroutine;

use Ecotone\Api\ExtensionObject\PollingMetadata;
use Ecotone\Messaging\Config\Container\ChannelReference;
use Ecotone\Messaging\Config\Container\Compiler\CompilerPass;
use Ecotone\Messaging\Config\Container\ContainerBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\EndpointRunnerReference;
use Ecotone\Messaging\Config\Container\PollingMetadataReference;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Endpoint\PollingConsumer\AsyncHandlerAnnotationRegistry;
use Ecotone\Messaging\Handler\Recoverability\DeadLetterChannels;
use Ecotone\Messaging\Support\MessageBuilder;

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
final class CoroutineMessageChannelConsumerRegistration implements CompilerPass
{
    public function __construct(private array $channelNames)
    {
    }

    public function process(ContainerBuilder $builder): void
    {
        if (! $builder->has(CoroutineMessageChannel::SLEEP_SERVICE_ID)) {
            $builder->register(CoroutineMessageChannel::SLEEP_SERVICE_ID, new Definition(extension_loaded('openswoole') ? CoroutineMessageChannelOpenSwooleSleep::class : CoroutineMessageChannelSwooleSleep::class));
        }
        $builder->register(CoroutineMessageChannelConsumer::REPLAY_HANDLER_ANNOTATIONS, new Definition(className: AsyncHandlerAnnotationRegistry::class, arguments: [new Reference(DeadLetterChannels::class)], factory: [AsyncHandlerAnnotationRegistry::class, 'withoutDelayedRetries']));
        foreach ($this->channelNames as $channelName) {
            $pollingMetadata = new PollingMetadataReference($channelName);
            $reference = (string) new EndpointRunnerReference($channelName);
            if ($builder->has($reference)) {
                $builder->replace($reference, $builder->register(CoroutineMessageChannelConsumer::SERVICE_ID_PREFIX . $channelName, new Definition(CoroutineMessageChannelConsumer::class, [
                    $channelName,
                    $builder->getDefinition($reference),
                    new ChannelReference($channelName),
                    new Reference(MessageBuilder::class),
                    new Definition(className: PollingMetadata::class, arguments: [$pollingMetadata], factory: [CoroutineMessageChannelConsumer::class, 'goingOnAfterAFailure']),
                    new Reference(AsyncHandlerAnnotationRegistry::class),
                    new Definition(className: PollingMetadata::class, arguments: [$pollingMetadata, new Reference(DeadLetterChannels::class)], factory: [CoroutineMessageChannelConsumer::class, 'withoutDelayedRetries']),
                    new Reference(CoroutineMessageChannelConsumer::REPLAY_HANDLER_ANNOTATIONS),
                    new Reference(CoroutineMessageChannelFailureLogger::class),
                ])));
            }
        }
    }
}
