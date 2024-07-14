<?php

namespace Ecotone\Dbal\DocumentStore;

use Ecotone\Dbal\DbalReconnectableConnectionFactory;
use Ecotone\Enqueue\CachedConnectionFactory;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\InterfaceToCallReference;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Handler\InputOutputMessageHandlerBuilder;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\ParameterConverterBuilder;
use Ecotone\Messaging\Handler\ServiceActivator\ServiceActivatorBuilder;
use Ecotone\Messaging\Store\Document\InMemoryDocumentStore;

/**
 * licence Apache-2.0
 */
final class DbalDocumentStoreBuilder extends InputOutputMessageHandlerBuilder
{
    /**
     * @param ParameterConverterBuilder[] $methodParameterConverterBuilders
     */
    public function __construct(protected string $inputMessageChannelName, private string $method, private bool $initializeDocumentStore, private string $connectionReferenceName, private bool $inMemoryEventStore, private InMemoryDocumentStore $inMemoryDocumentStore, private array $methodParameterConverterBuilders)
    {
    }

    public function getInterceptedInterface(InterfaceToCallRegistry $interfaceToCallRegistry): InterfaceToCall
    {
        return $interfaceToCallRegistry->getFor(DbalDocumentStore::class, $this->method);
    }

    public function compile(MessagingContainerBuilder $builder): Definition
    {
        $documentStoreReference = DbalDocumentStore::class.'.'.$this->connectionReferenceName;
        if (! $builder->has($documentStoreReference)) {
            $documentStore = $this->inMemoryEventStore
                ? new Definition(InMemoryDocumentStore::class, [], 'createEmpty')
                : new Definition(DbalDocumentStore::class, [
                    new Definition(CachedConnectionFactory::class, [
                        new Definition(DbalReconnectableConnectionFactory::class, [
                            new Reference($this->connectionReferenceName),
                        ]),
                    ], 'createFor'),
                    $this->initializeDocumentStore,
                    new Reference(ConversionService::REFERENCE_NAME),
                ]);

            $builder->register($documentStoreReference, $documentStore);
        }


        return ServiceActivatorBuilder::create(
            $documentStoreReference,
            new InterfaceToCallReference(DbalDocumentStore::class, $this->method),
        )
            ->withInputChannelName($this->getInputMessageChannelName())
            ->withOutputMessageChannel($this->getOutputMessageChannelName())
            ->withMethodParameterConverters($this->methodParameterConverterBuilders)
            ->compile($builder);
    }
}
