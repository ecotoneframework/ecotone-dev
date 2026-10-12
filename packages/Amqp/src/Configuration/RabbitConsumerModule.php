<?php

declare(strict_types=1);

namespace Ecotone\Amqp\Configuration;

use Ecotone\Amqp\AmqpInboundChannelAdapterBuilder;
use Ecotone\AnnotationFinder\AnnotatedMethod;
use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Api\Amqp\RabbitConsumer;
use Ecotone\Api\Attribute\ModuleAnnotation;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\NoExternalConfigurationModule;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Support\LicensingException;

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
            throw LicensingException::create('AmqpConsumer attribute is available only with Ecotone Enterprise licence. See https://docs.ecotone.tech/enterprise for details.');
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
                    ->withHeaderMapper('*')
                    ->withFinalFailureStrategy($amqpConsumer->getFinalFailureStrategy())
                    ->withDeclareOnStartup(true)
                    ->withEndpointAnnotations($amqpConsumerAnnotatedMethod->getAllAnnotationDefinitions())
            );
        }
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::AMQP_PACKAGE;
    }
}
