<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Unit\Config\Annotation\ModuleConfiguration;

use Ecotone\AnnotationFinder\InMemory\InMemoryAnnotationFinder;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\MethodInterceptor\MethodInterceptorModule;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\AroundInterceptorBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\AllHeadersBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\HeaderBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\PayloadBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInterceptorBuilder;
use stdClass;
use Test\Ecotone\Messaging\Fixture\Annotation\Interceptor\AroundInterceptorExample;
use Test\Ecotone\Messaging\Fixture\Annotation\Interceptor\AroundInterceptorWithCustomParameterConverters;
use Test\Ecotone\Messaging\Fixture\Annotation\Interceptor\ServiceActivatorInterceptorExample;
use Test\Ecotone\Messaging\Fixture\Annotation\Interceptor\TransformerInterceptorExample;

/**
 * Class MethodInterceptorModuleTest
 * @package Test\Ecotone\Messaging\Unit\Config\Annotation\ModuleConfiguration
 * @author Dariusz Gafka <support@simplycodedsoftware.com>
 *
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
final class MethodInterceptorModuleTest extends AnnotationConfigurationTestCase
{
    public function test_intercepting_with_around_message_endpoint(): void
    {
        $ecootneLite = EcotoneLite::bootstrapFlowTesting(
            [AroundInterceptorExample::class],
            [$service = new AroundInterceptorExample()],
            enableAsynchronousProcessing: [
                SimpleMessageChannelBuilder::createQueueChannel('async'),
            ]
        );

        $ecootneLite->sendCommandWithRoutingKey('doSomethingAsync', new stdClass());
        $this->assertNull($service->payload);
        $this->assertNull($service->consumerName);

        $ecootneLite->run('async');
        $this->assertEquals($service->payload, new stdClass());
        $this->assertEquals($service->consumerName, 'async');
    }
}
