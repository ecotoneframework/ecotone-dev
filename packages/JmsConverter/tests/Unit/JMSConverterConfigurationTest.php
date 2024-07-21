<?php

namespace Test\Ecotone\JMSConverter\Unit;

use ArrayObject;
use Ecotone\AnnotationFinder\InMemory\InMemoryAnnotationFinder;
use Ecotone\JMSConverter\ArrayObjectConverter;
use Ecotone\JMSConverter\Configuration\JMSConverterConfigurationModule;
use Ecotone\JMSConverter\JMSConverterBuilder;
use Ecotone\JMSConverter\JMSConverterConfiguration;
use Ecotone\JMSConverter\JMSHandlerAdapterBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\InMemoryModuleMessaging;
use Ecotone\Messaging\Config\MessagingSystemConfiguration;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\TypeDescriptor;
use PHPUnit\Framework\TestCase;
use stdClass;
use Test\Ecotone\JMSConverter\Fixture\Configuration\ArrayConversion\ArrayToArrayConverter;
use Test\Ecotone\JMSConverter\Fixture\Configuration\ArrayConversion\ClassToArrayConverter;
use Test\Ecotone\JMSConverter\Fixture\Configuration\ClassToClass\ClassToClassConverter;
use Test\Ecotone\JMSConverter\Fixture\Configuration\SimpleTypeToSimpleType\SimpleTypeToSimpleType;
use Test\Ecotone\JMSConverter\Fixture\Configuration\Status\Status;
use Test\Ecotone\JMSConverter\Fixture\Configuration\Status\StatusConverter;
use Test\Ecotone\JMSConverter\Fixture\Configuration\UnionConverter\AppointmentType;
use Test\Ecotone\JMSConverter\Fixture\Configuration\UnionConverter\AppointmentTypeConverter;
use Test\Ecotone\JMSConverter\Fixture\Configuration\UnionConverter\StandardAppointmentType;
use Test\Ecotone\JMSConverter\Fixture\Configuration\UnionConverter\TrialAppointmentType;

/**
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
class JMSConverterConfigurationTest extends TestCase
{
    public function test_registering_converter_and_convert()
    {
        $annotationConfiguration = JMSConverterConfigurationModule::create(
            InMemoryAnnotationFinder::createFrom([StatusConverter::class]),
            InterfaceToCallRegistry::createEmpty()
        );

        $configuration = MessagingSystemConfiguration::prepareWithDefaults(InMemoryModuleMessaging::createEmpty());
        $annotationConfiguration->prepare($configuration, [], ModuleReferenceSearchService::createEmpty(), InterfaceToCallRegistry::createEmpty());

        $this->assertEquals(
            MessagingSystemConfiguration::prepareWithDefaults(InMemoryModuleMessaging::createEmpty())
                ->registerConverter(
                    new JMSConverterBuilder(
                        [
                            new JMSHandlerAdapterBuilder(
                                TypeDescriptor::create(Status::class),
                                TypeDescriptor::createStringType(),
                                Reference::to(StatusConverter::class),
                                'convertFrom'
                            ),
                            new JMSHandlerAdapterBuilder(
                                TypeDescriptor::createStringType(),
                                TypeDescriptor::create(Status::class),
                                Reference::to(StatusConverter::class),
                                'convertTo'
                            ),
                            new JMSHandlerAdapterBuilder(
                                TypeDescriptor::create(ArrayObject::class),
                                TypeDescriptor::createArrayType(),
                                new Definition(ArrayObjectConverter::class),
                                'from'
                            ),
                            new JMSHandlerAdapterBuilder(
                                TypeDescriptor::createArrayType(),
                                TypeDescriptor::create(ArrayObject::class),
                                new Definition(ArrayObjectConverter::class),
                                'to'
                            ),
                        ],
                        JMSConverterConfiguration::createWithDefaults(),
                    )
                ),
            $configuration,
        );
    }

    public function test_register_union_converter()
    {
        $annotationConfiguration = JMSConverterConfigurationModule::create(
            InMemoryAnnotationFinder::createFrom([AppointmentTypeConverter::class]),
            InterfaceToCallRegistry::createEmpty()
        );

        $configuration = MessagingSystemConfiguration::prepareWithDefaults(InMemoryModuleMessaging::createEmpty());
        $annotationConfiguration->prepare($configuration, [], ModuleReferenceSearchService::createEmpty(), InterfaceToCallRegistry::createEmpty());

        $this->assertEquals(
            MessagingSystemConfiguration::prepareWithDefaults(InMemoryModuleMessaging::createEmpty())
                ->registerConverter(
                    new JMSConverterBuilder(
                        [
                            new JMSHandlerAdapterBuilder(
                                TypeDescriptor::create(AppointmentType::class),
                                TypeDescriptor::createStringType(),
                                Reference::to(AppointmentTypeConverter::class),
                                'convertFrom'
                            ),
                            new JMSHandlerAdapterBuilder(
                                TypeDescriptor::create(StandardAppointmentType::class),
                                TypeDescriptor::createStringType(),
                                Reference::to(AppointmentTypeConverter::class),
                                'convertFrom'
                            ),
                            new JMSHandlerAdapterBuilder(
                                TypeDescriptor::create(TrialAppointmentType::class),
                                TypeDescriptor::createStringType(),
                                Reference::to(AppointmentTypeConverter::class),
                                'convertFrom'
                            ),
                            new JMSHandlerAdapterBuilder(
                                TypeDescriptor::createStringType(),
                                TypeDescriptor::create(AppointmentType::class),
                                Reference::to(AppointmentTypeConverter::class),
                                'convertTo'
                            ),
                            new JMSHandlerAdapterBuilder(
                                TypeDescriptor::createStringType(),
                                TypeDescriptor::create(StandardAppointmentType::class),
                                Reference::to(AppointmentTypeConverter::class),
                                'convertTo'
                            ),
                            new JMSHandlerAdapterBuilder(
                                TypeDescriptor::createStringType(),
                                TypeDescriptor::create(TrialAppointmentType::class),
                                Reference::to(AppointmentTypeConverter::class),
                                'convertTo'
                            ),
                            new JMSHandlerAdapterBuilder(
                                TypeDescriptor::create(ArrayObject::class),
                                TypeDescriptor::createArrayType(),
                                new Definition(ArrayObjectConverter::class),
                                'from'
                            ),
                            new JMSHandlerAdapterBuilder(
                                TypeDescriptor::createArrayType(),
                                TypeDescriptor::create(ArrayObject::class),
                                new Definition(ArrayObjectConverter::class),
                                'to'
                            ),
                        ],
                        JMSConverterConfiguration::createWithDefaults(),
                    )
                ),
            $configuration,
        );
    }

    public function test_not_registering_converter_from_simple_type_to_simple_type()
    {
        $annotationConfiguration = JMSConverterConfigurationModule::create(
            InMemoryAnnotationFinder::createFrom([SimpleTypeToSimpleType::class]),
            InterfaceToCallRegistry::createEmpty()
        );

        $configuration = MessagingSystemConfiguration::prepareWithDefaults(InMemoryModuleMessaging::createEmpty());
        $annotationConfiguration->prepare($configuration, [], ModuleReferenceSearchService::createEmpty(), InterfaceToCallRegistry::createEmpty());

        $this->assertEquals(
            MessagingSystemConfiguration::prepareWithDefaults(InMemoryModuleMessaging::createEmpty())
                ->registerConverter($this->buildDefaultJmsConverterBuilder()),
            $configuration,
        );
    }

    public function test_always_registering_with_cache()
    {
        $annotationConfiguration = JMSConverterConfigurationModule::create(
            InMemoryAnnotationFinder::createFrom([SimpleTypeToSimpleType::class]),
            InterfaceToCallRegistry::createEmpty()
        );

        $configuration            = MessagingSystemConfiguration::prepareWithDefaults(InMemoryModuleMessaging::createEmpty());
        $applicationConfiguration = ServiceConfiguration::createWithDefaults()
            ->withCacheDirectoryPath('/tmp')
            ->withEnvironment('dev');
        $annotationConfiguration->prepare($configuration, [$applicationConfiguration], ModuleReferenceSearchService::createEmpty(), InterfaceToCallRegistry::createEmpty());

        $this->assertEquals(
            MessagingSystemConfiguration::prepareWithDefaults(InMemoryModuleMessaging::createEmpty())
                ->registerConverter(new JMSConverterBuilder([
                    new JMSHandlerAdapterBuilder(
                        TypeDescriptor::create(ArrayObject::class),
                        TypeDescriptor::createArrayType(),
                        new Definition(ArrayObjectConverter::class),
                        'from'
                    ),
                    new JMSHandlerAdapterBuilder(
                        TypeDescriptor::createArrayType(),
                        TypeDescriptor::create(ArrayObject::class),
                        new Definition(ArrayObjectConverter::class),
                        'to'
                    ),
                ], JMSConverterConfiguration::createWithDefaults(), '/tmp')),
            $configuration,
        );
    }

    public function test_not_registering_converter_from_class_to_class()
    {
        $annotationConfiguration = JMSConverterConfigurationModule::create(
            InMemoryAnnotationFinder::createFrom([ClassToClassConverter::class]),
            InterfaceToCallRegistry::createEmpty()
        );

        $configuration = MessagingSystemConfiguration::prepareWithDefaults(InMemoryModuleMessaging::createEmpty());
        $annotationConfiguration->prepare($configuration, [], ModuleReferenceSearchService::createEmpty(), InterfaceToCallRegistry::createEmpty());

        $this->assertEquals(
            MessagingSystemConfiguration::prepareWithDefaults(InMemoryModuleMessaging::createEmpty())
                ->registerConverter($this->buildDefaultJmsConverterBuilder()),
            $configuration,
        );
    }

    public function test_not_registering_converter_from_iterable_to_iterable()
    {
        $annotationConfiguration = JMSConverterConfigurationModule::create(
            InMemoryAnnotationFinder::createFrom([ArrayToArrayConverter::class]),
            InterfaceToCallRegistry::createEmpty()
        );

        $configuration = MessagingSystemConfiguration::prepareWithDefaults(InMemoryModuleMessaging::createEmpty());
        $annotationConfiguration->prepare($configuration, [], ModuleReferenceSearchService::createEmpty(), InterfaceToCallRegistry::createEmpty());

        $this->assertEquals(
            MessagingSystemConfiguration::prepareWithDefaults(InMemoryModuleMessaging::createEmpty())
                ->registerConverter($this->buildDefaultJmsConverterBuilder()),
            $configuration,
        );
    }

    public function test_registering_converter_from_array_to_class()
    {
        $annotationConfiguration = JMSConverterConfigurationModule::create(
            InMemoryAnnotationFinder::createFrom([ClassToArrayConverter::class]),
            InterfaceToCallRegistry::createEmpty()
        );

        $configuration = MessagingSystemConfiguration::prepareWithDefaults(InMemoryModuleMessaging::createEmpty());
        $annotationConfiguration->prepare($configuration, [], ModuleReferenceSearchService::createEmpty(), InterfaceToCallRegistry::createEmpty());

        $this->assertEquals(
            MessagingSystemConfiguration::prepareWithDefaults(InMemoryModuleMessaging::createEmpty())
                ->registerConverter(
                    new JMSConverterBuilder(
                        [
                            new JMSHandlerAdapterBuilder(
                                TypeDescriptor::createArrayType(),
                                TypeDescriptor::create(stdClass::class),
                                Reference::to(ClassToArrayConverter::class),
                                'convertFrom'
                            ),
                            new JMSHandlerAdapterBuilder(
                                TypeDescriptor::create(stdClass::class),
                                TypeDescriptor::createArrayType(),
                                Reference::to(ClassToArrayConverter::class),
                                'convertTo'
                            ),
                            new JMSHandlerAdapterBuilder(
                                TypeDescriptor::create(ArrayObject::class),
                                TypeDescriptor::createArrayType(),
                                new Definition(ArrayObjectConverter::class),
                                'from'
                            ),
                            new JMSHandlerAdapterBuilder(
                                TypeDescriptor::createArrayType(),
                                TypeDescriptor::create(ArrayObject::class),
                                new Definition(ArrayObjectConverter::class),
                                'to'
                            ),
                        ],
                        JMSConverterConfiguration::createWithDefaults(),
                    )
                ),
            $configuration,
        );
    }

    public function test_configuring_with_different_options()
    {
        $annotationConfiguration = JMSConverterConfigurationModule::create(InMemoryAnnotationFinder::createEmpty(), InterfaceToCallRegistry::createEmpty());

        $configuration = MessagingSystemConfiguration::prepareWithDefaults(InMemoryModuleMessaging::createEmpty());
        $annotationConfiguration->prepare($configuration, [], ModuleReferenceSearchService::createEmpty(), InterfaceToCallRegistry::createEmpty());

        $this->assertEquals(
            MessagingSystemConfiguration::prepareWithDefaults(InMemoryModuleMessaging::createEmpty())
                ->registerConverter(
                    $this->buildDefaultJmsConverterBuilder()
                ),
            $configuration,
        );
    }

    private function buildDefaultJmsConverterBuilder(): JMSConverterBuilder
    {
        return new JMSConverterBuilder([
            new JMSHandlerAdapterBuilder(
                TypeDescriptor::create(ArrayObject::class),
                TypeDescriptor::createArrayType(),
                new Definition(ArrayObjectConverter::class),
                'from'
            ),
            new JMSHandlerAdapterBuilder(
                TypeDescriptor::createArrayType(),
                TypeDescriptor::create(ArrayObject::class),
                new Definition(ArrayObjectConverter::class),
                'to'
            ),
        ], JMSConverterConfiguration::createWithDefaults(), null);
    }
}
