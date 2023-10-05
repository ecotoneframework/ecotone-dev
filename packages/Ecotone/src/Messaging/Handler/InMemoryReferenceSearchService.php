<?php

namespace Ecotone\Messaging\Handler;

use Ecotone\Messaging\Config\ServiceCacheConfiguration;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Conversion\AutoCollectionConversionService;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Handler\Gateway\ProxyFactory;
use Ecotone\Messaging\Handler\Logger\LoggingHandlerBuilder;
use Ecotone\Messaging\MessagingException;
use Ecotone\Messaging\Support\Assert;
use Ecotone\Modelling\EventBus;
use Psr\Log\NullLogger;

/**
 * Class InMemoryReferenceSearchService
 * @package Ecotone\Messaging\Handler
 * @author  Dariusz Gafka <dgafka.mail@gmail.com>
 */
class InMemoryReferenceSearchService implements ReferenceSearchService
{
    /**
     * @var object[]
     */
    private ?array $objectsToResolve = null;
    private ?ReferenceSearchService $referenceSearchService = null;

    /**
     * @param array|object[]              $objectsToResolve
     */
    private function __construct(array $objectsToResolve, ?ReferenceSearchService $referenceSearchService, ServiceConfiguration $serviceConfiguration)
    {
        if (! array_key_exists(ExpressionEvaluationService::REFERENCE, $objectsToResolve)) {
            $objectsToResolve[ExpressionEvaluationService::REFERENCE] = SymfonyExpressionEvaluationAdapter::create();
        }
        if (! array_key_exists(InterfaceToCallRegistry::REFERENCE_NAME, $objectsToResolve)) {
            $objectsToResolve[InterfaceToCallRegistry::REFERENCE_NAME] = InterfaceToCallRegistry::createEmpty();
        }
        if (! array_key_exists(ConversionService::REFERENCE_NAME, $objectsToResolve)) {
            $objectsToResolve[ConversionService::REFERENCE_NAME] = AutoCollectionConversionService::createEmpty();
        }
        if (! array_key_exists(ServiceCacheConfiguration::REFERENCE_NAME, $objectsToResolve) && !self::hasInOriginalReferenceService(ServiceCacheConfiguration::REFERENCE_NAME, $referenceSearchService)) {
            $objectsToResolve[ServiceCacheConfiguration::REFERENCE_NAME] = new ServiceCacheConfiguration(
                $serviceConfiguration->getCacheDirectoryPath(),
                false
            );
        }
        if (! array_key_exists(LoggingHandlerBuilder::LOGGER_REFERENCE, $objectsToResolve) && !self::hasInOriginalReferenceService(LoggingHandlerBuilder::LOGGER_REFERENCE, $referenceSearchService)) {
            $objectsToResolve[LoggingHandlerBuilder::LOGGER_REFERENCE] = new NullLogger();
        }
        $this->referenceSearchService = $referenceSearchService;

        $this->initialize($objectsToResolve);
    }

    /**
     * @param array|object[] $objects
     *
     * @return InMemoryReferenceSearchService
     * @throws MessagingException
     */
    public static function createWith(array $objects): self
    {
        return new self($objects, null, ServiceConfiguration::createWithDefaults());
    }

    /**
     * @return InMemoryReferenceSearchService
     * @throws MessagingException
     */
    public static function createEmpty(): self
    {
        return new self([], null, ServiceConfiguration::createWithDefaults());
    }

    /**
     * @param ReferenceSearchService $referenceSearchService
     * @param array                  $objects
     *
     * @return InMemoryReferenceSearchService
     * @throws MessagingException
     */
    public static function createWithReferenceService(ReferenceSearchService $referenceSearchService, array $objects, ServiceConfiguration $serviceConfiguration): self
    {
        return new self($objects, $referenceSearchService, $serviceConfiguration);
    }

    public function registerReferencedObject(string $referenceName, object $object): void
    {
        Assert::isObject($object, "Passed reference {$referenceName} must be object");

        $this->objectsToResolve[$referenceName] = $object;
    }

    /**
     * @inheritDoc
     */
    public function get(string $reference): object
    {
        if (array_key_exists($reference, $this->objectsToResolve)) {
            if (is_callable($this->objectsToResolve[$reference])) {
                $constructedObject = $this->objectsToResolve[$reference]($reference);
                $this->objectsToResolve[$reference] = $constructedObject;

                return $constructedObject;
            }

            return $this->objectsToResolve[$reference];
        }

        if ($this->referenceSearchService) {
            return $this->referenceSearchService->get($reference);
        }

        throw ReferenceNotFoundException::create("Reference {$reference} was not found");
    }

    public function has(string $referenceName): bool
    {
        if (array_key_exists($referenceName, $this->objectsToResolve)) {
            return true;
        }

        if ($this->referenceSearchService) {
            return $this->referenceSearchService->has($referenceName);
        }

        return false;
    }

    /**
     * @param array|object[] $objects
     *
     * @throws MessagingException
     */
    private function initialize(array $objects): void
    {
        foreach ($objects as $object) {
            Assert::isObject($object, 'Passed reference is not an object');
        }

        $this->objectsToResolve = $objects;
    }

    private static function hasInOriginalReferenceService(string $reference, ?ReferenceSearchService $referenceSearchService): bool
    {
        return $referenceSearchService !== null && $referenceSearchService->has($reference);
    }
}
