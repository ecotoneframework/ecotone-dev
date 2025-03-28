<?php

namespace Test\Ecotone\Messaging\Unit\Config\Annotation\ModuleConfiguration;

use Ecotone\AnnotationFinder\InMemory\InMemoryAnnotationFinder;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\SplitterModule;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\PayloadBuilder;
use Ecotone\Messaging\Handler\Splitter\SplitterBuilder;
use Exception;
use ReflectionException;
use Test\Ecotone\Messaging\Fixture\Annotation\MessageEndpoint\Splitter\SplitterExample;

/**
 * Class SplitterModuleTest
 * @package Test\Ecotone\Messaging\Unit\Config\Annotation\ModuleConfiguration
 * @author Dariusz Gafka <support@simplycodedsoftware.com>
 *
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
final class SplitterModuleTest extends AnnotationConfigurationTestCase
{
    /**
     * @throws \Doctrine\Common\Annotations\AnnotationException
     * @throws Exception
     * @throws ReflectionException
     * @throws \Ecotone\Messaging\Config\ConfigurationException
     * @throws \Ecotone\Messaging\MessagingException
     */
    public function test_creating_transformer_builder()
    {
        $annotationConfiguration = SplitterModule::create(
            InMemoryAnnotationFinder::createFrom([SplitterExample::class]),
            InterfaceToCallRegistry::createEmpty()
        );

        $configuration = $this->createMessagingSystemConfiguration();
        $annotationConfiguration->prepare($configuration, [], ModuleReferenceSearchService::createEmpty(), InterfaceToCallRegistry::createEmpty());

        $messageHandlerBuilder = SplitterBuilder::create(
            SplitterExample::class,
            InterfaceToCall::create(SplitterExample::class, 'split')
        )
            ->withEndpointId('testId')
            ->withInputChannelName('inputChannel')
            ->withOutputMessageChannel('outputChannel')
            ->withRequiredInterceptorNames(['someReference']);
        $messageHandlerBuilder->withMethodParameterConverters([
            PayloadBuilder::create('payload'),
        ]);

        $this->assertEquals(
            $this->createMessagingSystemConfiguration()
                ->registerMessageHandler($messageHandlerBuilder),
            $configuration
        );
    }
}
