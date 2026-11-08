<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Config\Annotation\ModuleConfiguration;

use Ecotone\AnnotationFinder\AnnotatedFinding;
use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Endpoint\ConsumerLifecycleBuilder;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;

/**
 * Class BaseAnnotationConfiguration
 * @package Ecotone\Messaging\Config\Annotation
 * @author  Dariusz Gafka <support@simplycodedsoftware.com>
 * @internal
 */
/**
 * licence Apache-2.0
 */
abstract class ConsumerRegisterConfiguration extends NoExternalConfigurationModule implements AnnotationModule
{
    private array $messageHandlerBuilders;

    /**
     * AnnotationGatewayConfiguration constructor.
     *
     * @param ConsumerLifecycleBuilder[] $messageHandlerBuilders
     * @param AnnotatedFinding[] $consumerRegistrations
     */
    private function __construct(array $messageHandlerBuilders, private array $consumerRegistrations)
    {
        $this->messageHandlerBuilders = $messageHandlerBuilders;
    }

    /**
     * @return AnnotatedFinding[]
     */
    protected function consumerRegistrations(): array
    {
        return $this->consumerRegistrations;
    }

    /**
     * @inheritDoc
     */
    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        $consumerBuilders = [];
        $consumerRegistrations = $annotationRegistrationService->findAnnotatedMethods(static::getConsumerAnnotation());
        foreach ($consumerRegistrations as $annotationRegistration) {
            $consumerBuilders[] = static::createConsumerFrom($annotationRegistration, $interfaceToCallRegistry);
        }

        /** @phpstan-ignore-next-line */
        return new static($consumerBuilders, $consumerRegistrations);
    }

    /**
     * @return string
     */
    abstract public static function getConsumerAnnotation(): string;

    abstract public static function createConsumerFrom(AnnotatedFinding $annotationRegistration, InterfaceToCallRegistry $interfaceToCallRegistry): ConsumerLifecycleBuilder;

    /**
     * @inheritDoc
     */
    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        foreach ($this->messageHandlerBuilders as $messageHandlerBuilder) {
            $messagingConfiguration->registerConsumer($messageHandlerBuilder);
        }
    }

    /**
     * @inheritDoc
     */
    public function getModulePackageName(): string
    {
        return ModulePackageList::CORE_PACKAGE;
    }
}
