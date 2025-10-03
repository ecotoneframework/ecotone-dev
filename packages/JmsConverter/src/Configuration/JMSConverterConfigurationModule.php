<?php

namespace Ecotone\JMSConverter\Configuration;

use ArrayObject;
use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\JMSConverter\ArrayObjectConverter;
use Ecotone\JMSConverter\JMSConverterBuilder;
use Ecotone\JMSConverter\JMSConverterConfiguration;
use Ecotone\JMSConverter\JMSHandlerAdapterBuilder;
use Ecotone\Messaging\Attribute\Converter;
use Ecotone\Messaging\Attribute\ModuleAnnotation;
use Ecotone\Messaging\Config\Annotation\AnnotatedDefinitionReference;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\NoExternalConfigurationModule;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Type;

#[ModuleAnnotation]
/**
 * licence Apache-2.0
 */
class JMSConverterConfigurationModule extends NoExternalConfigurationModule implements AnnotationModule
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

    public function canHandle($extensionObject): bool
    {
        return $extensionObject instanceof ServiceConfiguration
               || $extensionObject instanceof JMSConverterConfiguration;
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::JMS_CONVERTER_PACKAGE;
    }
}
