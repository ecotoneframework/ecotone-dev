<?php

declare(strict_types=1);

namespace Ecotone\Amqp\Configuration;

use Ecotone\Amqp\AmqpAdmin;
use Ecotone\Amqp\AmqpBackedMessageChannelBuilder;
use Ecotone\Amqp\AmqpBinding;
use Ecotone\Amqp\AmqpExchange;
use Ecotone\Amqp\AmqpQueue;
use Ecotone\Amqp\AmqpStreamChannelBuilder;
use Ecotone\Amqp\Attribute\RabbitConsumer;
use Ecotone\Amqp\Distribution\AmqpDistributionModule;
use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Messaging\Attribute\ModuleAnnotation;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\Container\DefinitionHelper;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Support\LicensingException;

#[ModuleAnnotation]
/**
 * licence Apache-2.0
 */
class AmqpModule implements AnnotationModule
{
    private AmqpDistributionModule $amqpDistributionModule;

    /**
     * @param AmqpQueue[] $amqpQueuesFromMessageConsumers
     */
    private function __construct(AmqpDistributionModule $amqpDistributionModule, private array $amqpQueuesFromMessageConsumers)
    {
        $this->amqpDistributionModule = $amqpDistributionModule;
    }

    /**
     * @inheritDoc
     */
    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        $amqpQueues = [];
        foreach ($annotationRegistrationService->findAnnotatedMethods(RabbitConsumer::class) as $annotatedMethod) {
            /** @var RabbitConsumer $amqpConsumer */
            $amqpConsumer = $annotatedMethod->getAnnotationForMethod();

            $amqpQueues[] = AmqpQueue::createWith($amqpConsumer->getQueueName());
        }

        return new self(
            AmqpDistributionModule::create(),
            $amqpQueues,
        );
    }

    /**
     * @inheritDoc
     */
    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        $extensionObjects = array_merge($this->amqpDistributionModule->getAmqpConfiguration($extensionObjects), $extensionObjects);

        $amqpExchanges = [];
        $amqpQueues = [];
        $amqpBindings = [];
        $hasAmqpStreamChannelBuilder = false;

        // Validate that message group IDs are unique across channels
        $messageGroupIdToChannelMap = [];
        foreach ($extensionObjects as $extensionObject) {
            if ($extensionObject instanceof AmqpStreamChannelBuilder) {
                $messageGroupId = $extensionObject->getMessageGroupId();
                $channelName = $extensionObject->getMessageChannelName();

                if (isset($messageGroupIdToChannelMap[$messageGroupId])) {
                    throw ConfigurationException::create(
                        "Message group ID '{$messageGroupId}' is already used by channel '{$messageGroupIdToChannelMap[$messageGroupId]}'. " .
                        'Each Message Channel must have a unique message group ID to maintain processing isolation. ' .
                        "Channel '{$channelName}' is trying to use the same message group ID."
                    );
                }

                $messageGroupIdToChannelMap[$messageGroupId] = $channelName;
            }
        }

        // First, collect all explicit AmqpQueue configurations
        $explicitQueueNames = [];
        foreach ($extensionObjects as $extensionObject) {
            if ($extensionObject instanceof AmqpQueue) {
                $amqpQueues[] = $extensionObject;
                $explicitQueueNames[$extensionObject->getQueueName()] = true;
            } elseif ($extensionObject instanceof AmqpExchange) {
                $amqpExchanges[] = $extensionObject;
            } elseif ($extensionObject instanceof AmqpBinding) {
                $amqpBindings[] = $extensionObject;
            }
        }

        // Then, process channel builders and only auto-create queues if not explicitly configured
        foreach ($extensionObjects as $extensionObject) {
            if ($extensionObject instanceof AmqpBackedMessageChannelBuilder) {
                if (! isset($explicitQueueNames[$extensionObject->getQueueName()])) {
                    $amqpQueues[] = AmqpQueue::createWith($extensionObject->getQueueName());
                }
            } elseif ($extensionObject instanceof AmqpStreamChannelBuilder) {
                $hasAmqpStreamChannelBuilder = true;
                if (! isset($explicitQueueNames[$extensionObject->queueName])) {
                    $amqpQueues[] = AmqpQueue::createStreamQueue($extensionObject->queueName);
                }
            }
        }

        if ($hasAmqpStreamChannelBuilder && ! $messagingConfiguration->isRunningForEnterpriseLicence()) {
            throw LicensingException::create('AmqpStreamChannelBuilder is available only with Ecotone Enterprise licence.');
        }

        foreach ($this->amqpQueuesFromMessageConsumers as $amqpQueue) {
            foreach ($amqpQueues as $queue) {
                if ($queue->getQueueName() === $amqpQueue->getQueueName()) {
                    continue 2;
                }
            }
            $amqpQueues[] = $amqpQueue;
        }

        $this->amqpDistributionModule->prepare($messagingConfiguration, $extensionObjects);
        $messagingConfiguration->registerServiceDefinition(AmqpAdmin::REFERENCE_NAME, DefinitionHelper::buildDefinitionFromInstance(
            AmqpAdmin::createWith(
                $amqpExchanges,
                $amqpQueues,
                $amqpBindings
            )
        ));
    }

    /**
     * @inheritDoc
     */
    public function canHandle($extensionObject): bool
    {
        return
            $extensionObject instanceof AmqpBackedMessageChannelBuilder
            || $extensionObject instanceof AmqpStreamChannelBuilder
            || $extensionObject instanceof AmqpExchange
            || $extensionObject instanceof AmqpQueue
            || $extensionObject instanceof AmqpBinding
            || $this->amqpDistributionModule->canHandle($extensionObject);
    }

    public function getModuleExtensions(ServiceConfiguration $serviceConfiguration, array $serviceExtensions): array
    {
        return [];
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::AMQP_PACKAGE;
    }
}
