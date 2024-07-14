<?php

declare(strict_types=1);

namespace Ecotone\Amqp\Configuration;

use Ecotone\Amqp\AmqpAdmin;
use Ecotone\Amqp\AmqpBackedMessageChannelBuilder;
use Ecotone\Amqp\AmqpBinding;
use Ecotone\Amqp\AmqpExchange;
use Ecotone\Amqp\AmqpQueue;
use Ecotone\Amqp\Distribution\AmqpDistributionModule;
use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Messaging\Attribute\ModuleAnnotation;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\Container\DefinitionHelper;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;

#[ModuleAnnotation]
/**
 * licence Apache-2.0
 */
class AmqpModule implements AnnotationModule
{
    private AmqpDistributionModule $amqpDistributionModule;

    private function __construct(AmqpDistributionModule $amqpDistributionModule)
    {
        $this->amqpDistributionModule = $amqpDistributionModule;
    }

    /**
     * @inheritDoc
     */
    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        return new self(AmqpDistributionModule::create($annotationRegistrationService, $interfaceToCallRegistry));
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

        foreach ($extensionObjects as $extensionObject) {
            if ($extensionObject instanceof AmqpBackedMessageChannelBuilder) {
                $amqpQueues[] = AmqpQueue::createWith($extensionObject->getMessageChannelName());
            } elseif ($extensionObject instanceof AmqpExchange) {
                $amqpExchanges[] = $extensionObject;
            } elseif ($extensionObject instanceof AmqpQueue) {
                $amqpQueues[] = $extensionObject;
            } elseif ($extensionObject instanceof AmqpBinding) {
                $amqpBindings[] = $extensionObject;
            }
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
            || $extensionObject instanceof AmqpExchange
            || $extensionObject instanceof AmqpQueue
            || $extensionObject instanceof AmqpBinding
            || $this->amqpDistributionModule->canHandle($extensionObject);
    }

    public function getModuleExtensions(array $serviceExtensions): array
    {
        return [];
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::AMQP_PACKAGE;
    }
}
