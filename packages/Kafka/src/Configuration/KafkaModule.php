<?php

declare(strict_types=1);

namespace Ecotone\Kafka\Configuration;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Messaging\Attribute\ModuleAnnotation;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\NoExternalConfigurationModule;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Support\LicensingException;

/**
 * licence Enterprise
 */
#[ModuleAnnotation]
final class KafkaModule extends NoExternalConfigurationModule implements AnnotationModule
{
    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        return new self();
    }

    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        if (!$messagingConfiguration->isRunningForEnterpriseLicence()) {
            if (count($extensionObjects) > 0) {
                throw LicensingException::create("Kafka module is available only with Ecotone Enterprise licence.");
            }

            return;
        }

        $consumerConfigurations = [];
        $topicConfigurations = [];
        foreach ($extensionObjects as $extensionObject) {
            if ($extensionObject instanceof ConsumerConfiguration) {
                $consumerConfigurations[$extensionObject->getEndpointId()] = $consumerConfigurations;
            } else if ($extensionObject instanceof TopicConfiguration) {
                $topicConfigurations[$extensionObject->getTopicName()] = $topicConfigurations;
            }
        }

        $messagingConfiguration->registerServiceDefinition(
            KafkaAdmin::class,
            Definition::createFor(KafkaAdmin::class, [
                $consumerConfigurations,
                $topicConfigurations,
            ])
        );
    }

    public function canHandle($extensionObject): bool
    {
        return $extensionObject instanceof ConsumerConfiguration || $extensionObject instanceof TopicConfiguration;
    }

    public function getModulePackageName(): string
    {
        return "kafka";
    }
}