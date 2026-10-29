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
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\NoExternalConfigurationModule;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\ModuleDefaultSerializationMediaType;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Type;
use Ecotone\Modelling\MessageHandling\Distribution\Pulse\PulseFeature;

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
        $jmsConverterConfiguration = JMSConverterConfiguration::createWithDefaults();
        foreach ($extensionObjects as $extensionObject) {
            if ($extensionObject instanceof JMSConverterConfiguration) {
                $jmsConverterConfiguration = $extensionObject;
            }
        }

        $messagingConfiguration->registerConverter(new JMSConverterBuilder($this->jmsHandlerAdapters, $jmsConverterConfiguration));
    }

    public function getModuleExtensions(ServiceConfiguration $serviceConfiguration, array $serviceExtensions): array
    {
        if (! PulseFeature::isReportedFor($serviceExtensions)) {
            return [new ModuleDefaultSerializationMediaType(MediaType::APPLICATION_JSON)];
        }
        $configuration = JMSConverterConfiguration::createWithDefaults();
        foreach ($serviceExtensions as $serviceExtension) {
            if ($serviceExtension instanceof JMSConverterConfiguration) {
                $configuration = $serviceExtension;
            }
        }

        return [new ModuleDefaultSerializationMediaType(MediaType::APPLICATION_JSON), new PulseFeature(
            package: ModulePackageList::JMS_CONVERTER_PACKAGE,
            name: 'JMS serialization',
            enabled: true,
            settings: [
                'Naming strategy' => $configuration->getNamingStrategy(),
                'Null values' => $configuration->getDefaultNullSerialization() ? 'serialized' : 'left out',
                'Enums' => PulseFeature::onOff($configuration->isEnumSupportEnabled()),
            ],
            handlers: [],
            hint: sprintf(
                'JMS Serializer converts objects to and from JSON, which it makes the default serialization media type unless ServiceConfiguration::withDefaultSerializationMediaType names another: property names are kept %s, null values are %s and enums are %s.',
                $configuration->getNamingStrategy() === JMSConverterConfiguration::IDENTICAL_PROPERTY_NAMING_STRATEGY ? 'as they are' : 'converted to ' . $configuration->getNamingStrategy(),
                $configuration->getDefaultNullSerialization() ? 'serialized' : 'left out',
                $configuration->isEnumSupportEnabled() ? 'converted to their values' : 'not converted',
            ),
            configuration: 'JMSConverterConfiguration::createWithDefaults()->withNamingStrategy(JMSConverterConfiguration::CAMEL_CASE_PROPERTY_NAMING_STRATEGY)->withDefaultNullSerialization(false)',
        )];
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::JMS_CONVERTER_PACKAGE;
    }
}
