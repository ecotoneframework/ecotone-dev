<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Configuration;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Messaging\Attribute\ModuleAnnotation;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ExtensionObjectResolver;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Enqueue\Dbal\DbalConnectionFactory;

#[ModuleAnnotation]
/**
 * Central module for registering Dbal connection factory requirement.
 * All Dbal features use the connection factory, so this module validates it's properly configured.
 *
 * licence Apache-2.0
 */
class DbalConnectionModule implements AnnotationModule
{
    private function __construct()
    {
    }

    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        return new self();
    }

    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        $dbalConfiguration = ExtensionObjectResolver::resolveUnique(
            DbalConfiguration::class,
            $extensionObjects,
            DbalConfiguration::createWithDefaults()
        );

        $connectionFactories = $dbalConfiguration->getDefaultConnectionReferenceNames() ?: [DbalConnectionFactory::class];

        foreach ($connectionFactories as $connectionFactory) {
            $messagingConfiguration->requireReference(
                $connectionFactory,
                sprintf(
                    "Dbal module requires '%s' to be configured. " .
                    "For Symfony, add SymfonyConnectionReference::defaultConnection('default') to your ServiceContext. " .
                    'For Laravel, add LaravelConnectionReference::defaultConnection() to your ServiceContext. ' .
                    'See: https://docs.ecotone.tech/modules/dbal-support#configuration',
                    $connectionFactory
                )
            );
        }
    }

    public function canHandle($extensionObject): bool
    {
        return $extensionObject instanceof DbalConfiguration;
    }

    public function getModuleExtensions(ServiceConfiguration $serviceConfiguration, array $serviceExtensions): array
    {
        return [];
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::DBAL_PACKAGE;
    }
}
