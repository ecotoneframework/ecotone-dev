<?php

declare(strict_types=1);

namespace Ecotone\GDPR\Config;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\EventSourcing\EventStore;
use Ecotone\GDPR\PersonalData\PersonalDataEncryptionConfiguration;
use Ecotone\GDPR\PersonalData\PersonalDataEncryptionInterceptorBuilder;
use Ecotone\Messaging\Attribute\ModuleAnnotation;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ExtensionObjectResolver;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\NoExternalConfigurationModule;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Handler\Gateway\GatewayProxyBuilder;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;

/**
 * licence Apache-2.0
 */
#[ModuleAnnotation]
final class PersonalDataModule extends NoExternalConfigurationModule
{
    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        return new self();
    }

    public function prepare(
        Configuration $messagingConfiguration,
        array $extensionObjects,
        ModuleReferenceSearchService $moduleReferenceSearchService,
        InterfaceToCallRegistry $interfaceToCallRegistry
    ): void {
        if (! ExtensionObjectResolver::contains(PersonalDataEncryptionConfiguration::class, $extensionObjects)) {
            return;
        }

        /** @var PersonalDataEncryptionConfiguration $personalDataEncryptionConfiguration */
        $personalDataEncryptionConfiguration = ExtensionObjectResolver::resolveUnique(PersonalDataEncryptionConfiguration::class, $extensionObjects, PersonalDataEncryptionConfiguration::createWithDefaults());

        $filter = static fn (GatewayProxyBuilder $gateway) => $gateway->getInterfaceName() === EventStore::class
            && in_array($gateway->getRelatedMethodName(), ['appendTo', 'load'], true)
            && $personalDataEncryptionConfiguration->isEnabledFor($gateway->getReferenceName())
        ;

        $eventStoreGateways = array_filter($messagingConfiguration->getRegisteredGateways(), $filter);

        foreach ($eventStoreGateways as $gateway) {
            if ($gateway->getRelatedMethodName() === 'appendTo') {
                $gateway->addBeforeInterceptor(PersonalDataEncryptionInterceptorBuilder::appendToInterceptorBuilder());
            }
            if ($gateway->getRelatedMethodName() === 'load') {
                $gateway->addAfterInterceptor(PersonalDataEncryptionInterceptorBuilder::loadInterceptorBuilder());
            }
        }
    }

    public function canHandle($extensionObject): bool
    {
        return $extensionObject instanceof PersonalDataEncryptionConfiguration;
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::GDPR_PACKAGE;
    }
}
