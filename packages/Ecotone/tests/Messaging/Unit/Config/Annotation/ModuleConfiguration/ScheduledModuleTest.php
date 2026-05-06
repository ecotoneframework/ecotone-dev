<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Unit\Config\Annotation\ModuleConfiguration;

use Ecotone\AnnotationFinder\AnnotatedMethod;
use Ecotone\Messaging\Attribute\Scheduled;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ScheduledModule;
use Ecotone\Messaging\Config\Container\AttributeDefinition;
use Ecotone\Messaging\Endpoint\InboundChannelAdapter\InboundChannelAdapterBuilder;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use PHPUnit\Framework\TestCase;
use Test\Ecotone\Messaging\Fixture\Scheduled\ScheduledMarkerAttribute;
use Test\Ecotone\Messaging\Fixture\Scheduled\ScheduledServiceWithMarker;

/**
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
final class ScheduledModuleTest extends TestCase
{
    public function test_propagates_method_attributes_to_channel_adapter_endpoint_annotations(): void
    {
        $scheduled = new Scheduled(requestChannelName: 'scheduledTarget', endpointId: 'scheduledWithMarker');
        $marker = new ScheduledMarkerAttribute('marked');

        $annotatedMethod = AnnotatedMethod::create(
            $scheduled,
            ScheduledServiceWithMarker::class,
            'poll',
            [],
            [$scheduled, $marker]
        );

        $builder = ScheduledModule::createConsumerFrom($annotatedMethod, InterfaceToCallRegistry::createEmpty());

        $this->assertInstanceOf(InboundChannelAdapterBuilder::class, $builder);

        $endpointAttributeClassNames = array_map(
            fn (AttributeDefinition $definition) => $definition->getClassName(),
            $builder->getEndpointAnnotations()
        );

        $this->assertContains(
            ScheduledMarkerAttribute::class,
            $endpointAttributeClassNames,
            'Scheduled method attributes must reach the channel adapter gateway as endpoint annotations so attribute-pointcut interceptors can match them.'
        );
    }
}
