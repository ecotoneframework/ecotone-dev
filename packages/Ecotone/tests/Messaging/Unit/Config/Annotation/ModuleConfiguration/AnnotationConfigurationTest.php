<?php

namespace Test\Ecotone\Messaging\Unit\Config\Annotation\ModuleConfiguration;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\AnnotationFinder\InMemory\InMemoryAnnotationFinder;
use Ecotone\Messaging\Config\InMemoryModuleMessaging;
use Ecotone\Messaging\Config\MessagingSystemConfiguration;
use Test\Ecotone\Messaging\Unit\MessagingTest;

/**
 * Class AnnotationConfigurationTest
 * @package Test\Ecotone\Messaging\Unit\Config\Annotation\Annotation
 * @author Dariusz Gafka <support@simplycodedsoftware.com>
 */
/**
 * licence Apache-2.0
 */
abstract class AnnotationConfigurationTest extends MessagingTest
{
    protected function createAnnotationRegistrationService(string $className, string $methodName, $classAnnotationObject, $methodAnnotationObject): AnnotationFinder
    {
        return InMemoryAnnotationFinder::createEmpty()
            ->addAnnotationToClass(
                $className,
                $classAnnotationObject
            )
            ->addAnnotationToClassMethod(
                $className,
                $methodName,
                $methodAnnotationObject
            );
    }

    protected function createMultipleAnnotationRegistrationService(string $className, string $methodName, array $classAnnotations, array $methodAnnotations): AnnotationFinder
    {
        $inMemoryAnnotationRegistrationService = InMemoryAnnotationFinder::createEmpty();

        foreach ($classAnnotations as $classAnnotation) {
            $inMemoryAnnotationRegistrationService
                ->addAnnotationToClass($className, $classAnnotation);
        }
        foreach ($methodAnnotations as $methodAnnotation) {
            $inMemoryAnnotationRegistrationService
                ->addAnnotationToClassMethod($className, $methodName, $methodAnnotation);
        }

        return $inMemoryAnnotationRegistrationService;
    }

    /**
     * @return MessagingSystemConfiguration
     */
    protected function createMessagingSystemConfiguration(): MessagingSystemConfiguration
    {
        return MessagingSystemConfiguration::prepareWithDefaults(InMemoryModuleMessaging::createEmpty());
    }
}
