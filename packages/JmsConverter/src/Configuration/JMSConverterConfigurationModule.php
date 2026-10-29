<?php

namespace Ecotone\JMSConverter\Configuration;

use ArrayObject;
use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Api\Attribute\Converter;
use Ecotone\Api\Attribute\ModuleAnnotation;
use Ecotone\Api\ExtensionObject\MediaType;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\JMSConverter\JMSConverterConfiguration;
use Ecotone\JMSConverter\ArrayObjectConverter;
use Ecotone\JMSConverter\JMSConverterBuilder;
use Ecotone\JMSConverter\JMSHandlerAdapterBuilder;
use Ecotone\Messaging\Config\Annotation\AnnotatedDefinitionReference;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ExtensionObjectResolver;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\NoExternalConfigurationModule;
use Ecotone\Messaging\Config\BootstrapConfiguration;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\ModuleDefaultSerializationMediaType;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Type;

#[ModuleAnnotation]
/**
 * licence Apache-2.0
 */
final class JMSConverterConfigurationModule extends NoExternalConfigurationModule implements AnnotationModule
{
    /**
     * @param JMSHandlerAdapterBuilder[] $jmsHandlerAdapters
     */
    public function __construct(private array $jmsHandlerAdapters)
    {
    }


    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        $registrations = $annotationRegistrationService->findAnnotatedMethods(Converter::class);

        $converters = [];
        foreach ($registrations as $registration) {
            $interfaceToCall = $interfaceToCallRegistry->getFor($registration->getClassName(), $registration->getMethodName());

            $fromTypes = $interfaceToCall->getFirstParameter()->getTypeDescriptor();
            $fromTypes = $fromTypes->isUnionType() ? $fromTypes->getUnionTypes() : [$fromTypes];

            $toTypes = $interfaceToCall->getReturnType();
            $toTypes = $toTypes->isUnionType() ? $toTypes->getUnionTypes() : [$toTypes];

            foreach ($fromTypes as $fromType) {
                foreach ($toTypes as $toType) {
                    if (! $fromType->isClassOrInterface() && ! $toType->isClassOrInterface()) {
                        continue;
                    }
                    if ($fromType->isClassOrInterface() && $toType->isClassOrInterface()) {
                        continue;
                    }

                    $converters[] = new JMSHandlerAdapterBuilder(
                        $fromType,
                        $toType,
                        $interfaceToCall->isStaticallyCalled() ? $interfaceToCall->getInterfaceName() : Reference::to(AnnotatedDefinitionReference::getReferenceFor($registration)),
                        $registration->getMethodName(),
                    );
                }
            }
        }

        $converters[] = new JMSHandlerAdapterBuilder(
            Type::object(ArrayObject::class),
            Type::array(),
            new Definition(ArrayObjectConverter::class),
            'from'
        );
        $converters[] = new JMSHandlerAdapterBuilder(
            Type::array(),
            Type::object(ArrayObject::class),
            new Definition(ArrayObjectConverter::class),
            'to'
        );

        return new self($converters);
    }

    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        $jmsConverterConfiguration = $this->resolveConfiguration(ExtensionObjectResolver::resolve(JMSConverterConfiguration::class, $extensionObjects));
        $bootstrapConfiguration = ExtensionObjectResolver::resolveUnique(BootstrapConfiguration::class, $extensionObjects, BootstrapConfiguration::createWithDefaults());

        $messagingConfiguration->registerConverter(new JMSConverterBuilder(
            $this->jmsHandlerAdapters,
            $jmsConverterConfiguration,
            checkMetadataCacheFreshness: $jmsConverterConfiguration->metadataCacheFreshnessCheck ?? ! $bootstrapConfiguration->isProductionConfiguration(),
        ));
    }

    /**
     * @param JMSConverterConfiguration[] $configurations
     */
    private function resolveConfiguration(array $configurations): JMSConverterConfiguration
    {
        if (count($configurations) > 1) {
            throw ConfigurationException::create(
                'Ecotone was given more than one JMSConverterConfiguration: '
                . implode(', and ', array_map(fn (JMSConverterConfiguration $configuration): string => 'one with ' . $this->describe($configuration), $configurations))
                . '. Ecotone reads exactly one, so it cannot tell which of them to apply. '
                . 'Keep one #[ServiceContext] method returning JMSConverterConfiguration::createWithDefaults() and move every with*() call onto it, or pass a single JMSConverterConfiguration in extensionObjects.'
            );
        }

        return $configurations[0] ?? JMSConverterConfiguration::createWithDefaults();
    }

    private function describe(JMSConverterConfiguration $configuration): string
    {
        return sprintf(
            'naming strategy %s, null serialization %s, enum support %s and the metadata cache freshness check %s',
            $configuration->namingStrategy,
            $configuration->defaultNullSerialization ? 'on' : 'off',
            $configuration->enableEnumSupport ? 'on' : 'off',
            match ($configuration->metadataCacheFreshnessCheck) {
                null => 'following the environment',
                true => 'on',
                false => 'off',
            },
        );
    }

    public function getModuleExtensions(ServiceConfiguration $serviceConfiguration, array $serviceExtensions): array
    {
        return [new ModuleDefaultSerializationMediaType(MediaType::APPLICATION_JSON)];
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::JMS_CONVERTER_PACKAGE;
    }
}
