<?php

namespace Ecotone\JMSConverter\Configuration;

use ArrayObject;
use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\JMSConverter\ArrayObjectConverter;
use Ecotone\JMSConverter\JMSConverterBuilder;
use Ecotone\JMSConverter\JMSConverterConfiguration;
use Ecotone\JMSConverter\JMSHandlerAdapter;
use Ecotone\Messaging\Attribute\Converter;
use Ecotone\Messaging\Attribute\ModuleAnnotation;
use Ecotone\Messaging\Config\Annotation\AnnotatedDefinitionReference;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\NoExternalConfigurationModule;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\TypeDescriptor;

#[ModuleAnnotation]
class JMSConverterConfigurationModule extends NoExternalConfigurationModule implements AnnotationModule
{
    /**
     * @var JMSHandlerAdapter[]
     */
    private $jmsHandlerAdapters;

    /**
     * JMSConverterConfiguration constructor.
     * @param JMSHandlerAdapter[] $jmsHandlerAdapters
     */
    public function __construct(array $jmsHandlerAdapters)
    {
        $this->jmsHandlerAdapters = $jmsHandlerAdapters;
    }


    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        $registrations = $annotationRegistrationService->findAnnotatedMethods(Converter::class);

        $converters = [];
        foreach ($registrations as $registration) {
            $reference = AnnotatedDefinitionReference::getReferenceFor($registration);
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

                    $converters[] = JMSHandlerAdapter::create(
                        $fromType,
                        $toType,
                        $reference,
                        $registration->getMethodName(),
                    );
                }
            }
        }

        $converters[] = JMSHandlerAdapter::createWithDirectObject(
            TypeDescriptor::create(ArrayObject::class),
            TypeDescriptor::createArrayType(),
            new ArrayObjectConverter(),
            'from'
        );
        $converters[] = JMSHandlerAdapter::createWithDirectObject(
            TypeDescriptor::createArrayType(),
            TypeDescriptor::create(ArrayObject::class),
            new ArrayObjectConverter(),
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
        $cacheDirectoryPath = null;
        foreach ($extensionObjects as $extensionObject) {
            if ($extensionObject instanceof ServiceConfiguration) {
                $cacheDirectoryPath = $extensionObject->getCacheDirectoryPath();
            }
        }

        $messagingConfiguration->registerConverter(new JMSConverterBuilder($this->jmsHandlerAdapters, $jmsConverterConfiguration, $cacheDirectoryPath));
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
