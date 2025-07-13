<?php

declare(strict_types=1);

namespace Ecotone\Amqp\Configuration;

use Ecotone\Amqp\AmqpInboundChannelAdapterBuilder;
use Ecotone\Amqp\Attribute\RabbitConsumer;
use Ecotone\AnnotationFinder\AnnotatedMethod;
use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Messaging\Attribute\ModuleAnnotation;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\NoExternalConfigurationModule;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\TypeDescriptor;
use Ecotone\Messaging\Support\LicensingException;
use Enqueue\AmqpExt\AmqpConnectionFactory;

/**
 * licence Enterprise
 */
#[ModuleAnnotation]
final class RabbitConsumerModule extends NoExternalConfigurationModule implements AnnotationModule
{
    /**
     * @param AnnotatedMethod[] $amqpConsumersAnnotatedMethods
     */
    private function __construct(
        private array $amqpConsumersAnnotatedMethods,
    ) {
    }

    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        $amqpConsumers = [];
        foreach ($annotationRegistrationService->findAnnotatedMethods(RabbitConsumer::class) as $annotatedMethod) {
            $amqpConsumers[] = $annotatedMethod;
        }

        return new self($amqpConsumers);
    }

    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        if (empty($this->amqpConsumersAnnotatedMethods)) {
            return;
        }

        if (! $messagingConfiguration->isRunningForEnterpriseLicence()) {
            throw LicensingException::create('AmqpConsumer attribute is available only with Ecotone Enterprise licence.');
        }

        foreach ($this->amqpConsumersAnnotatedMethods as $amqpConsumerAnnotatedMethod) {
            /** @var RabbitConsumer $amqpConsumer */
            $amqpConsumer = $amqpConsumerAnnotatedMethod->getAnnotationForMethod();

            $messagingConfiguration->registerConsumer(
                AmqpInboundChannelAdapterBuilder::createWith(
                    $amqpConsumer->getEndpointId(),
                    $amqpConsumer->getQueueName(),
                    $amqpConsumer->getEndpointId(),
                    $amqpConsumer->getConnectionReference(),
                )
                    ->withHeaderMapper("*")
                    ->withFinalFailureStrategy($amqpConsumer->getFinalFailureStrategy())
                    ->withDeclareOnStartup(true)
                    ->withEndpointAnnotations($amqpConsumerAnnotatedMethod->getAllAnnotationDefinitions())
            );
        }
    }

    public function canHandle($extensionObject): bool
    {
        return false;
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::AMQP_PACKAGE;
    }
}
