<?php

namespace Test\Ecotone\AnnotationFinder\Unit;

use Ecotone\AnnotationFinder\AnnotatedDefinition;
use Ecotone\AnnotationFinder\AnnotatedMethod;
use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\AnnotationFinder\AnnotationResolver;
use Ecotone\AnnotationFinder\ConfigurationException;
use Ecotone\AnnotationFinder\InMemory\InMemoryAnnotationFinder;
use Ecotone\Api\Attribute\SendsDistributedMessage;
use PHPUnit\Framework\TestCase;
use Test\Ecotone\AnnotationFinder\Fixture\Usage\Attribute\Annotation\MessageEndpoint;
use Test\Ecotone\AnnotationFinder\Fixture\Usage\Attribute\Annotation\SomeGatewayExample;
use Test\Ecotone\AnnotationFinder\Fixture\Usage\Attribute\Annotation\SomeHandlerAnnotation;
use Test\Ecotone\AnnotationFinder\Fixture\Usage\Attribute\MessageEndpoint\Gateway\FileSystem\GatewayWithReplyChannelExample;
use Test\Ecotone\AnnotationFinder\Fixture\Usage\Attribute\MessageEndpoint\SplitterOnMethod\SplitterOnMethodExample;

/**
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
class InMemoryAttributeAnnotationFinderTest extends TestCase
{
    public function getAnnotationResolver(): AnnotationResolver
    {
        return new AnnotationResolver\AttributeResolver();
    }

    public function test_retrieving_annotation_registration_for_application_context()
    {
        $gatewayAnnotation = new SomeGatewayExample();
        $messageEndpoint   = new MessageEndpoint();
        $this->assertEquals(
            [
                AnnotatedDefinition::create(
                    $messageEndpoint,
                    $gatewayAnnotation,
                    GatewayWithReplyChannelExample::class,
                    'buy',
                    [$messageEndpoint],
                    [$gatewayAnnotation]
                ),
            ],
            $this->createAnnotationRegistrationService([GatewayWithReplyChannelExample::class])
                ->findCombined(MessageEndpoint::class, SomeGatewayExample::class)
        );
    }

    public function test_sends_distributed_message_on_a_method_that_is_not_public_is_refused()
    {
        $service = new class () {
            #[SendsDistributedMessage(targetServiceName: 'ticketing', routingKey: 'tickets.hold')]
            protected function send(): void
            {
            }
        };

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(sprintf(
            '#[SendsDistributedMessage] on %s::send() is on a method that is not public, but the attribute is read only from public message handler methods, so the route it declares would never be recorded. Put the attribute on the public handler method that calls send().',
            $service::class,
        ));

        InMemoryAnnotationFinder::createFrom([$service::class])->findAnnotatedMethods(SendsDistributedMessage::class);
    }

    public function test_retrieving_method_annotations()
    {
        $gatewayAnnotation = new SomeGatewayExample();

        $this->assertEquals(
            [
                $gatewayAnnotation,
            ],
            $this->createAnnotationRegistrationService([GatewayWithReplyChannelExample::class])
                ->getAnnotationsForMethod(GatewayWithReplyChannelExample::class, 'buy')
        );
    }

    public function test_retrieving_class_annotations()
    {
        $this->assertEquals(
            [
                new MessageEndpoint(),
            ],
            $this->createAnnotationRegistrationService([GatewayWithReplyChannelExample::class])
                ->getAnnotationsForClass(GatewayWithReplyChannelExample::class)
        );
    }

    public function test_finding_annotated_classes()
    {
        $this->assertEquals(
            [
                GatewayWithReplyChannelExample::class,
            ],
            $this->createAnnotationRegistrationService([GatewayWithReplyChannelExample::class])
                ->findAnnotatedClasses(MessageEndpoint::class)
        );
    }

    public function test_retrieving_by_only_method_annotation()
    {
        $annotation = new SomeHandlerAnnotation();

        $this->assertEquals(
            [
                AnnotatedMethod::create(
                    $annotation,
                    SplitterOnMethodExample::class,
                    'split',
                    [],
                    [$annotation]
                ),
            ],
            $this->createAnnotationRegistrationService([SplitterOnMethodExample::class])
                ->findAnnotatedMethods(SomeHandlerAnnotation::class)
        );
    }

    private function createAnnotationRegistrationService(array $classes): AnnotationFinder
    {
        return InMemoryAnnotationFinder::createFrom($classes);
    }
}
