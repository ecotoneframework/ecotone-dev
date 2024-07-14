<?php

namespace Test\Ecotone\Modelling\Unit\Config;

use Ecotone\Messaging\Handler\Logger\StubLoggingGateway;
use Ecotone\Messaging\Handler\TypeDescriptor;
use Ecotone\Messaging\Support\MessageBuilder;
use Ecotone\Modelling\Config\EventBusRouter;
use PHPUnit\Framework\TestCase;
use stdClass;
use Test\Ecotone\Messaging\Fixture\Conversion\AbstractSuperAdmin;
use Test\Ecotone\Messaging\Fixture\Conversion\Admin;
use Test\Ecotone\Messaging\Fixture\Conversion\Email;
use Test\Ecotone\Messaging\Fixture\Conversion\SuperAdmin;

/**
 * Class EventBusRouterTest
 * @package Test\Ecotone\Modelling\Unit\Config
 * @author  Dariusz Gafka <support@simplycodedsoftware.com>
 *
 * @internal
 */
/**
 * licence Apache-2.0
 */
class EventBusRouterTest extends TestCase
{
    public function test_routing_by_class()
    {
        $classNameToChannelNameMapping = [stdClass::class => [stdClass::class]];

        $this->assertEquals(
            [stdClass::class],
            $this->createEventRouter($classNameToChannelNameMapping)->routeByObject(new stdClass(), MessageBuilder::withPayload('a')->build())
        );
    }

    public function test_routing_by_object_type_hint()
    {
        $classNameToChannelNameMapping = [TypeDescriptor::OBJECT => [stdClass::class]];

        $this->assertEquals(
            [stdClass::class],
            $this->createEventRouter($classNameToChannelNameMapping)->routeByObject(new stdClass(), MessageBuilder::withPayload('a')->build())
        );
    }

    public function test_routing_by_abstract_class()
    {
        $classNameToChannelNameMapping = [
            stdClass::class => ['some'],
            AbstractSuperAdmin::class => ['abstractSuperAdmin'],
            SuperAdmin::class => ['superAdmin'],
        ];

        $this->assertEquals(
            ['abstractSuperAdmin', 'superAdmin'],
            $this->createEventRouter($classNameToChannelNameMapping)->routeByObject(new SuperAdmin(), MessageBuilder::withPayload('a')->build())
        );
    }

    public function test_routing_by_interface()
    {
        $classNameToChannelNameMapping = [
            stdClass::class => ['some'],
            Admin::class => ['admin'],
            SuperAdmin::class => ['superAdmin'],
            Email::class => ['email'],
        ];

        $this->assertEquals(
            ['admin', 'email', 'superAdmin'],
            $this->createEventRouter($classNameToChannelNameMapping)->routeByObject(new SuperAdmin(), MessageBuilder::withPayload('a')->build())
        );
    }

    public function test_routing_by_channel_name()
    {
        $classNameToChannelNameMapping = ['createOffer' => ['channel']];

        $this->assertEquals(
            ['channel'],
            $this->createEventRouter($classNameToChannelNameMapping)->routeByName('createOffer', MessageBuilder::withPayload('a')->build())
        );
    }

    public function test_routing_by_expression()
    {
        $classNameToChannelNameMapping = ['input.*' => ['someId']];

        $this->assertEquals(
            ['someId'],
            $this->createEventRouter($classNameToChannelNameMapping)->routeByName('input.test', MessageBuilder::withPayload('a')->build())
        );
    }

    public function test_merging_multiple_endpoints()
    {
        $classNameToChannelNameMapping = ['input.*' => ['someId1'], '*.test' => ['someId2'], 'test' => ['someId3'], 'input' => ['someId4']];

        $this->assertEquals(
            ['someId1', 'someId2'],
            $this->createEventRouter($classNameToChannelNameMapping)->routeByName('input.test', MessageBuilder::withPayload('a')->build())
        );
    }

    /**
     * @param array $classNameToChannelNameMapping
     *
     * @return EventBusRouter
     */
    private function createEventRouter(array $classNameToChannelNameMapping): EventBusRouter
    {
        return new EventBusRouter($classNameToChannelNameMapping, new StubLoggingGateway());
    }
}
