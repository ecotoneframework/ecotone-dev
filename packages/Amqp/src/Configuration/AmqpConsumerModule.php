<?php

declare(strict_types=1);

namespace Ecotone\Amqp\Configuration;

use Ecotone\Amqp\AmqpInboundChannelAdapterBuilder;
use Ecotone\Amqp\Attribute\AmqpConsumer;
use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Messaging\Attribute\ModuleAnnotation;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\NoExternalConfigurationModule;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Support\LicensingException;
use Enqueue\AmqpExt\AmqpConnectionFactory;

/**
 * licence Enterprise
 */
#[ModuleAnnotation]
final class AmqpConsumerModule extends NoExternalConfigurationModule implements AnnotationModule
{
    /**
     * @param AmqpConsumer[] $amqpConsumers
     */
    private function __construct(
        private array $amqpConsumers,
    ) {
    }

    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        $amqpConsumers = [];
        foreach ($annotationRegistrationService->findAnnotatedMethods(AmqpConsumer::class) as $annotatedMethod) {
            /** @var AmqpConsumer $amqpConsumer */
            $amqpConsumer = $annotatedMethod->getAnnotationForMethod();

            $amqpConsumers[$amqpConsumer->getEndpointId()] = $amqpConsumer;
        }

        return new self($amqpConsumers);
    }

    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        if (empty($this->amqpConsumers)) {
            return;
        }

        if (! $messagingConfiguration->isRunningForEnterpriseLicence()) {
            throw LicensingException::create('AmqpConsumer attribute is available only with Ecotone Enterprise licence.');
        }

        foreach ($this->amqpConsumers as $amqpConsumer) {
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
