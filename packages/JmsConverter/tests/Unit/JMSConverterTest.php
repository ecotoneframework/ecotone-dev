<?php

namespace Test\Ecotone\JMSConverter\Unit;

use ArrayObject;
use DateTimeImmutable;
use Ecotone\JMSConverter\ArrayObjectConverter;
use Ecotone\JMSConverter\JMSConverter;
use Ecotone\JMSConverter\JMSConverterConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Conversion\ConversionException;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Handler\Type;
use PHPUnit\Framework\TestCase;
use stdClass;
use Test\Ecotone\JMSConverter\Fixture\Configuration\ArrayConversion\ClassToArrayConverter;
use Test\Ecotone\JMSConverter\Fixture\Configuration\Status\Person;
use Test\Ecotone\JMSConverter\Fixture\Configuration\Status\Status;
use Test\Ecotone\JMSConverter\Fixture\Configuration\Status\StatusConverter;
use Test\Ecotone\JMSConverter\Fixture\ExamplesToConvert\CollectionProperty;
use Test\Ecotone\JMSConverter\Fixture\ExamplesToConvert\Date\ObjectWithDate;
use Test\Ecotone\JMSConverter\Fixture\ExamplesToConvert\Date\YearMonthDayDateConverter;
use Test\Ecotone\JMSConverter\Fixture\ExamplesToConvert\Enum\Account;
use Test\Ecotone\JMSConverter\Fixture\ExamplesToConvert\Enum\AccountStatus;
use Test\Ecotone\JMSConverter\Fixture\ExamplesToConvert\Enum\AccountStatusConverter;
use Test\Ecotone\JMSConverter\Fixture\ExamplesToConvert\PersonAbstractClass;
use Test\Ecotone\JMSConverter\Fixture\ExamplesToConvert\PersonInterface;
use Test\Ecotone\JMSConverter\Fixture\ExamplesToConvert\PropertiesWithDocblockTypes;
use Test\Ecotone\JMSConverter\Fixture\ExamplesToConvert\PropertyWithAnnotationMetadataDefined;
use Test\Ecotone\JMSConverter\Fixture\ExamplesToConvert\PropertyWithMixedArrayType;
use Test\Ecotone\JMSConverter\Fixture\ExamplesToConvert\PropertyWithNullUnionType;
use Test\Ecotone\JMSConverter\Fixture\ExamplesToConvert\PropertyWithTypeAndMetadataType;
use Test\Ecotone\JMSConverter\Fixture\ExamplesToConvert\PropertyWithUnionType;
use Test\Ecotone\JMSConverter\Fixture\ExamplesToConvert\ThreeLevelNestedObjectProperty;
use Test\Ecotone\JMSConverter\Fixture\ExamplesToConvert\TwoLevelNestedCollectionProperty;
use Test\Ecotone\JMSConverter\Fixture\ExamplesToConvert\TwoLevelNestedObjectProperty;
use Test\Ecotone\JMSConverter\Fixture\ExamplesToConvert\TypedProperty;

/**
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
class JMSConverterTest extends TestCase
{
    public function test_converting_with_docblock_types()
    {
        $toSerialize = new PropertiesWithDocblockTypes('Johny', 'Silverhand');
        $expectedSerializationString = '{"name":"Johny","surname":"Silverhand"}';

        $this->assertSerializationAndDeserializationWithJSON($toSerialize, $expectedSerializationString);
    }

    public function test_converting_with_annotation_docblock()
    {
        $toSerialize = new PropertyWithAnnotationMetadataDefined('Johny');
        $expectedSerializationString = '{"naming":"Johny"}';

        $this->assertSerializationAndDeserializationWithJSON($toSerialize, $expectedSerializationString);
    }

    public function test_overriding_type_with_metadata()
    {
        $toSerialize = new PropertyWithTypeAndMetadataType(5);
        $expectedSerializationString = '{"data":5}';

        $this->assertSerializationAndDeserializationWithJSON($toSerialize, $expectedSerializationString);
    }

    public function test_converting_with_typed_property()
    {
        $toSerialize = new TypedProperty(3);
        $expectedSerializationString = '{"data":3}';

        $this->assertSerializationAndDeserializationWithJSON($toSerialize, $expectedSerializationString);
    }

    public function test_converting_with_mixed_scalar_array_type()
    {
        $toSerialize = new PropertyWithMixedArrayType(new ArrayObject([
            'name' => 'Franco',
            'age' => 13,
            'passport' => [
                'id' => 123,
                'valid' => '2022-01-01',
            ],
        ]));
        $expectedSerializationString = '{"data":{"name":"Franco","age":13,"passport":{"id":123,"valid":"2022-01-01"}}}';

        $this->assertSerializationAndDeserializationWithJSON($toSerialize, $expectedSerializationString, [
            new ArrayObjectConverter(),
        ]);
    }

    public function test_two_level_object_nesting()
    {
        $toSerialize = new TwoLevelNestedObjectProperty(new PropertyWithTypeAndMetadataType(3));
        $expectedSerializationString = '{"data":{"data":3}}';

        $this->assertSerializationAndDeserializationWithJSON($toSerialize, $expectedSerializationString);
    }

    public function test_three_level_object_nesting()
    {
        $toSerialize = new ThreeLevelNestedObjectProperty(new TwoLevelNestedObjectProperty(new PropertyWithTypeAndMetadataType(3)));
        $expectedSerializationString = '{"data":{"data":{"data":3}}}';

        $this->assertSerializationAndDeserializationWithJSON($toSerialize, $expectedSerializationString);
    }

    public function test_with_collection_type()
    {
        $toSerialize = new CollectionProperty([new PropertyWithTypeAndMetadataType(3), new PropertyWithTypeAndMetadataType(4)]);
        $expectedSerializationString = '{"collection":[{"data":3},{"data":4}]}';

        $this->assertSerializationAndDeserializationWithJSON($toSerialize, $expectedSerializationString);
    }

    public function test_with_nested_collection_type()
    {
        $toSerialize = new TwoLevelNestedCollectionProperty([
            new CollectionProperty([new PropertyWithTypeAndMetadataType(1), new PropertyWithTypeAndMetadataType(2)]),
            new CollectionProperty([new PropertyWithTypeAndMetadataType(3), new PropertyWithTypeAndMetadataType(4)]),
        ]);
        $expectedSerializationString = '{"collection":[{"collection":[{"data":1},{"data":2}]},{"collection":[{"data":3},{"data":4}]}]}';

        $this->assertSerializationAndDeserializationWithJSON($toSerialize, $expectedSerializationString);
    }

    public function test_skipping_nullable_type()
    {
        $toSerialize = new PropertyWithNullUnionType('100');
        $expectedSerializationString = '{"data":"100"}';

        $this->assertSerializationAndDeserializationWithJSON($toSerialize, $expectedSerializationString);
    }

    public function test_converting_with_ignoring_null()
    {
        $toSerialize = ['test' => null];
        $expectedSerializationString = '[]';

        $this->assertEquals(
            $expectedSerializationString,
            $this->serializeToJson($toSerialize, [], JMSConverterConfiguration::createWithDefaults()->withDefaultNullSerialization(false))
        );
    }

    public function test_converting_with_keeping_null()
    {
        $toSerialize = ['test' => null];
        $expectedSerializationString = '{"test":null}';

        $this->assertEquals(
            $expectedSerializationString,
            $this->serializeToJson($toSerialize, [], JMSConverterConfiguration::createWithDefaults()->withDefaultNullSerialization(true))
        );
    }

    public function test_converting_with_keeping_nulls_and_values()
    {
        $toSerialize = ['test' => null, 'test2' => 1, 'test3' => 'bla'];
        $expectedSerializationString = '{"test":null,"test2":1,"test3":"bla"}';

        $this->assertEquals(
            $expectedSerializationString,
            $this->serializeToJson($toSerialize, [], JMSConverterConfiguration::createWithDefaults()->withDefaultNullSerialization(true))
        );
    }

    public function test_throwing_exception_if_converted_type_is_union_type()
    {
        $toSerialize = new PropertyWithUnionType([]);
        $expectedSerializationString = '{"data":[]}';

        $this->expectException(ConversionException::class);

        $this->assertSerializationAndDeserializationWithJSON($toSerialize, $expectedSerializationString);
    }

    public function test_serializing_with_metadata_cache()
    {
        $toSerialize = new PropertyWithTypeAndMetadataType(5);
        $converter = $this->getJMSConverter([]);

        $serialized = $converter->convert($toSerialize, Type::createFromVariable($toSerialize), MediaType::createApplicationXPHP(), Type::string(), MediaType::createApplicationJson());

        $this->assertEquals(
            $toSerialize,
            $converter->convert($serialized, Type::string(), MediaType::createApplicationJson(), Type::createFromVariable($toSerialize), MediaType::createApplicationXPHP(), Type::createFromVariable($toSerialize))
        );
    }

    public function test_converting_with_jms_handlers_using_simple_type_to_class_mapping()
    {
        $toSerialize = new Person(new Status('active'));
        $expectedSerializationString = '{"status":"active"}';

        $this->assertSerializationAndDeserializationWithJSON($toSerialize, $expectedSerializationString, [
            new StatusConverter(),
        ]);
    }

    public function test_converting_with_jms_handlers_using_array_to_class_mapping()
    {
        $toSerialize = new stdClass();
        $toSerialize->data = 'someInformation';
        $expectedSerializationString = '{"data":"someInformation"}';

        $this->assertSerializationAndDeserializationWithJSON($toSerialize, $expectedSerializationString, [
            new ClassToArrayConverter(),
        ]);
    }

    public function test_converting_array_of_objects_to_array()
    {
        $toSerialize = [new Status('active'), new Status('archived')];
        $expectedSerialized = ['active', 'archived'];

        $this->assertEquals($expectedSerialized, $this->serializeToArray($toSerialize, [new StatusConverter()]));
    }

    public function test_converting_array_of_objects_to_json()
    {
        $toSerialize = [new Status('active'), new Status('archived')];
        $expectedSerializationString = '["active","archived"]';

        $serialized = $this->serializeToJson($toSerialize, [new StatusConverter()]);
        $this->assertEquals($expectedSerializationString, $serialized);
        $this->assertEquals($toSerialize, $this->deserialize($serialized, "array<Test\Ecotone\JMSConverter\Fixture\Configuration\Status\Status>", [
            new StatusConverter(),
        ]));
    }

    public function test_converting_json_to_array()
    {
        $toSerialize = ['name' => 'johny', 'surname' => 'franco'];
        $expectedSerializationString = '{"name":"johny","surname":"franco"}';

        $serialized = $this->getJMSConverter([])->convert($toSerialize, Type::array(), MediaType::createApplicationXPHP(), Type::string(), MediaType::createApplicationJson());
        $this->assertEquals($expectedSerializationString, $serialized);
        $this->assertEquals($toSerialize, $this->getJMSConverter([])->convert($serialized, Type::string(), MediaType::createApplicationJson(), Type::array(), MediaType::createApplicationXPHP()));
    }

    public function test_converting_from_array_to_object()
    {
        $toSerialize = new TwoLevelNestedObjectProperty(new PropertyWithTypeAndMetadataType(3));
        $expectedSerializationObject = ['data' => ['data' => 3]];

        $serialized = $this->getJMSConverter([])->convert($toSerialize, Type::createFromVariable($toSerialize), MediaType::createApplicationXPHP(), Type::array(), MediaType::createApplicationXPHP());
        $this->assertEquals($expectedSerializationObject, $serialized);
        $this->assertEquals($toSerialize, $this->getJMSConverter([])->convert($serialized, Type::array(), MediaType::createApplicationXPHP(), Type::createFromVariable($toSerialize), MediaType::createApplicationXPHP()));
    }

    public function test_converting_with_nulls()
    {
        $toSerialize = new TwoLevelNestedObjectProperty(new PropertyWithTypeAndMetadataType(null));
        $expectedSerializationObject = ['data' => ['data' => null]];

        $serialized = $this->getJMSConverter([])->convert($toSerialize, Type::createFromVariable($toSerialize), MediaType::createApplicationXPHP(), Type::array(), MediaType::createWithParameters('application', 'x-php', [JMSConverter::SERIALIZE_NULL_PARAMETER => 'true']));
        $this->assertEquals($expectedSerializationObject, $serialized);
        $this->assertEquals($toSerialize, $this->getJMSConverter([])->convert($serialized, Type::array(), MediaType::createApplicationXPHP(), Type::createFromVariable($toSerialize), MediaType::createApplicationXPHP()));
    }

    public function test_serializing_class_with_default_enum_converter(): void
    {
        $toSerialize = new Account(AccountStatus::ACTIVE);
        $expectedSerializationString = '{"status":"active"}';

        $this->assertSerializationAndDeserializationWithJSON($toSerialize, $expectedSerializationString, [], JMSConverterConfiguration::createWithDefaults()->withDefaultEnumSupport(true));
    }

    public function test_serializing_class_with_custom_enum_converter(): void
    {
        $toSerialize = new Account(AccountStatus::ACTIVE);
        $expectedSerializationString = '{"status":{"value":"active"}}';

        $this->assertSerializationAndDeserializationWithJSON($toSerialize, $expectedSerializationString, [
            new AccountStatusConverter(),
        ]);
    }

    public function test_serializing_with_default_time_handler(): void
    {
        $toSerialize = new ObjectWithDate(new DateTimeImmutable('2021-01-01 12:00:00'));
        $expectedSerializationString = '{"date":"2021-01-01T12:00:00+00:00"}';

        $this->assertSerializationAndDeserializationWithJSON($toSerialize, $expectedSerializationString);
    }

    public function test_overriding_custom_time_handler(): void
    {
        $toSerialize = new ObjectWithDate(new DateTimeImmutable('2021-01-01 12:00:00'));

        $serialized = $this->serializeToJson($toSerialize, [new YearMonthDayDateConverter()], null);
        $this->assertEquals('{"date":"2021-01-01"}', $serialized);

        $this->assertEquals(
            new ObjectWithDate(new DateTimeImmutable('2021-01-01 00:00:00')),
            $this->deserialize($serialized, get_class($toSerialize), [new YearMonthDayDateConverter()])
        );
    }

    public function test_converting_to_xml()
    {
        $toSerialize = new Person(new Status('active'));
        $expectedSerializationString = '<?xml version="1.0" encoding="UTF-8"?>
<result>
  <status>
    <type><![CDATA[active]]></type>
  </status>
</result>
';

        $serialized = $this->getJMSConverter([])->convert($toSerialize, Type::array(), MediaType::createApplicationXPHP(), Type::string(), MediaType::createApplicationXml());
        $this->assertEquals($expectedSerializationString, $serialized);
        $this->assertEquals($toSerialize, $this->getJMSConverter([])->convert($serialized, Type::string(), MediaType::createApplicationXml(), Type::create(Person::class), MediaType::createApplicationXPHP()));
    }

    public function test_matching_conversion_from_array_to_xml()
    {
        $this->assertTrue(
            $this->getJMSConverter([])->canConvert(Type::array(), MediaType::createApplicationXPHP(), Type::string(), MediaType::createApplicationXml())
        );
        $this->assertTrue(
            $this->getJMSConverter([])->canConvert(Type::string(), MediaType::createApplicationXml(), Type::array(), MediaType::createApplicationXPHP())
        );
    }

    public function test_matching_conversion_from_object_to_xml()
    {
        $this->assertTrue(
            $this->getJMSConverter([])->canConvert(Type::create(Person::class), MediaType::createApplicationXPHP(), Type::string(), MediaType::createApplicationXml())
        );
        $this->assertTrue(
            $this->getJMSConverter([])->canConvert(Type::string(), MediaType::createApplicationXml(), Type::create(Person::class), MediaType::createApplicationXPHP())
        );
    }

    public function test_matching_conversion_from_array_to_json()
    {
        $this->assertTrue(
            $this->getJMSConverter([])->canConvert(Type::array(), MediaType::createApplicationXPHP(), Type::string(), MediaType::createApplicationJson())
        );
        $this->assertTrue(
            $this->getJMSConverter([])->canConvert(Type::string(), MediaType::createApplicationJson(), Type::array(), MediaType::createApplicationXPHP())
        );
    }

    public function test_matching_conversion_from_php_object_to_php_array()
    {
        $this->assertTrue(
            $this->getJMSConverter([])->canConvert(Type::create(Person::class), MediaType::createApplicationXPHP(), Type::array(), MediaType::createApplicationXPHP())
        );
        $this->assertTrue(
            $this->getJMSConverter([])->canConvert(Type::array(), MediaType::createApplicationXPHP(), Type::create(Person::class), MediaType::createApplicationXPHP())
        );
    }

    public function test_matching_conversion_from_json_object_to_php_object()
    {
        $this->assertTrue(
            $this->getJMSConverter([])->canConvert(Type::create(Person::class), MediaType::createApplicationXPHP(), Type::string(), MediaType::createApplicationJson())
        );
        $this->assertTrue(
            $this->getJMSConverter([])->canConvert(Type::string(), MediaType::createApplicationJson(), Type::create(Person::class), MediaType::createApplicationXPHP())
        );
    }

    public function test_matching_conversion_from_php_interface_to_json()
    {
        $this->assertTrue(
            $this->getJMSConverter([])->canConvert(Type::create(PersonInterface::class), MediaType::createApplicationXPHP(), Type::string(), MediaType::createApplicationJson())
        );
        $this->assertTrue(
            $this->getJMSConverter([])->canConvert(Type::string(), MediaType::createApplicationJson(), Type::create(PersonInterface::class), MediaType::createApplicationXPHP())
        );
    }

    public function test_matching_conversion_from_php_abstract_class_to_json()
    {
        $this->assertTrue(
            $this->getJMSConverter([])->canConvert(Type::create(PersonAbstractClass::class), MediaType::createApplicationXPHP(), Type::string(), MediaType::createApplicationJson())
        );
        $this->assertTrue(
            $this->getJMSConverter([])->canConvert(Type::string(), MediaType::createApplicationJson(), Type::create(PersonAbstractClass::class), MediaType::createApplicationXPHP())
        );
    }

    public function test_matching_conversion_from_php_collection_to_array()
    {
        $this->assertTrue(
            $this->getJMSConverter([])->canConvert(Type::createCollection(Person::class), MediaType::createApplicationXPHP(), Type::array(), MediaType::createApplicationXPHP())
        );
        $this->assertTrue(
            $this->getJMSConverter([])->canConvert(Type::array(), MediaType::createApplicationXPHP(), Type::createCollection(Person::class), MediaType::createApplicationXPHP())
        );
    }

    public function test_matching_conversion_from_php_array_to_php_array()
    {
        $this->assertTrue(
            $this->getJMSConverter([])->canConvert(Type::array(), MediaType::createApplicationXPHP(), Type::array(), MediaType::createApplicationXPHP())
        );
        $this->assertTrue(
            $this->getJMSConverter([])->canConvert(Type::array(), MediaType::createApplicationXPHP(), Type::array(), MediaType::createApplicationXPHP())
        );
    }

    public function test_matching_conversion_from_php_collection_of_objects_to_array()
    {
        $this->assertTrue(
            $this->getJMSConverter([])->canConvert(Type::createCollection('object'), MediaType::createApplicationXPHP(), Type::array(), MediaType::createApplicationXPHP())
        );
        $this->assertTrue(
            $this->getJMSConverter([])->canConvert(Type::array(), MediaType::createApplicationXPHP(), Type::createCollection('object'), MediaType::createApplicationXPHP())
        );
    }

    public function test_not_matching_conversion_from_object_to_format_different_than_xml_and_json()
    {
        $this->assertFalse(
            $this->getJMSConverter([])->canConvert(Type::create(Person::class), MediaType::createApplicationXPHP(), Type::string(), MediaType::createApplicationOcetStream())
        );
        $this->assertFalse(
            $this->getJMSConverter([])->canConvert(Type::string(), MediaType::createApplicationOcetStream(), Type::create(Person::class), MediaType::createApplicationXPHP())
        );
    }

    public function test_matching_conversion_from_array_to_object_and_opposite()
    {
        $this->assertTrue(
            $this->getJMSConverter([])->canConvert(Type::array(), MediaType::createApplicationXPHP(), Type::create(stdClass::class), MediaType::createApplicationXPHP())
        );
        $this->assertTrue(
            $this->getJMSConverter([])->canConvert(Type::create(stdClass::class), MediaType::createApplicationXPHP(), Type::array(), MediaType::createApplicationXPHP())
        );
    }

    private function assertSerializationAndDeserializationWithJSON(object|array $toSerialize, string $expectedSerializationString, $jmsHandlerAdapters = [], ?JMSConverterConfiguration $configuration = null): void
    {
        $serialized = $this->serializeToJson($toSerialize, $jmsHandlerAdapters, $configuration);
        $this->assertEquals($expectedSerializationString, $serialized);
        $this->assertEquals($toSerialize, $this->deserialize($serialized, is_array($toSerialize) ? Type::ARRAY : get_class($toSerialize), $jmsHandlerAdapters, $configuration));
    }

    private function serializeToJson($data, array $jmsHandlerAdapters, ?JMSConverterConfiguration $configuration = null)
    {
        return $this->getJMSConverter($jmsHandlerAdapters, $configuration)->convert($data, Type::createFromVariable($data), MediaType::createApplicationXPHP(), Type::string(), MediaType::createApplicationJson());
    }

    private function serializeToArray($data, array $jmsHandlerAdapters)
    {
        return $this->getJMSConverter($jmsHandlerAdapters)->convert($data, Type::createFromVariable($data), MediaType::createApplicationXPHP(), Type::array(), MediaType::createApplicationXPHP());
    }

    private function deserialize(string $data, string $type, array $jmsHandlerAdapters, ?JMSConverterConfiguration $configuration = null)
    {
        return $this->getJMSConverter($jmsHandlerAdapters, $configuration)->convert($data, Type::string(), MediaType::createApplicationJson(), Type::create($type), MediaType::createApplicationXPHP(), Type::create($type));
    }

    /**
     * @param object[] $converters
     */
    private function getJMSConverter(array $converters, ?JMSConverterConfiguration $configuration = null): ConversionService
    {
        return EcotoneLite::bootstrapFlowTesting(
            array_map(fn (object $converter) => $converter::class, $converters),
            $converters,
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::JMS_CONVERTER_PACKAGE]))
                ->withExtensionObjects($configuration ? [$configuration] : [])
        )
            ->getServiceFromContainer(ConversionService::REFERENCE_NAME);
    }
}
