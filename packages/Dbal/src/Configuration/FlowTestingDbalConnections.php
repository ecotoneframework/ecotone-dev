<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Configuration;

use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\ExtensionObject\DbalConnectionReference;
use Ecotone\Api\Dbal\ExtensionObject\MultiTenantConfiguration;
use Ecotone\Lite\Test\Configuration\ProvidedReferences;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ExtensionObjectResolver;
use Ecotone\Messaging\Config\ConfigurationException;

/**
 * licence Apache-2.0
 */
final class FlowTestingDbalConnections
{
    /**
     * @param object[] $extensionObjects
     */
    public function __construct(private ProvidedReferences $providedReferences, private array $extensionObjects)
    {
    }

    public function has(string $connectionReferenceName): bool
    {
        if ($this->providedReferences->has($connectionReferenceName)) {
            return true;
        }

        foreach (ExtensionObjectResolver::resolve(MultiTenantConfiguration::class, $this->extensionObjects) as $multiTenantConfiguration) {
            if ($multiTenantConfiguration->getReferenceName() === $connectionReferenceName) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return object[]
     */
    public function resolveExtensionObjects(): array
    {
        $this->refuseDbalChannelsWithoutTheirConnection();

        $dbalConfiguration = ExtensionObjectResolver::resolveUnique(DbalConfiguration::class, $this->extensionObjects, DbalConfiguration::createWithDefaults());

        return [
            ...array_filter($this->extensionObjects, fn (object $extensionObject) => ! $extensionObject instanceof DbalConfiguration),
            $this->inMemoryWhereTheConnectionIsMissing($dbalConfiguration),
        ];
    }

    private function inMemoryWhereTheConnectionIsMissing(DbalConfiguration $dbalConfiguration): DbalConfiguration
    {
        foreach ($dbalConfiguration->defaultConnectionReferenceNames ?: [DbalConnectionReference::DEFAULT] as $connectionReferenceName) {
            if (! $this->has($connectionReferenceName)) {
                $dbalConfiguration = $dbalConfiguration
                    ->withTransactionOnCommandBus(false)
                    ->withTransactionOnAsynchronousEndpoints(false)
                    ->withTransactionOnConsoleCommands(false)
                    ->withClearAndFlushObjectManagerOnCommandBus(false)
                    ->withClearAndFlushObjectManagerOnAsynchronousEndpoints(false)
                    ->withClearAndFlushObjectManagerOnProjectionBatch(false);
            }
        }

        if (! $dbalConfiguration->deduplicationInMemory && ! $this->has($dbalConfiguration->getDeduplicationConnectionReference())) {
            $dbalConfiguration = $dbalConfiguration->withDeduplication(
                $dbalConfiguration->deduplicatedEnabled,
                $dbalConfiguration->getDeduplicationConnectionReference(),
                $dbalConfiguration->minimumTimeToRemoveMessageInMilliseconds,
                $dbalConfiguration->deduplicationRemovalBatchSize,
                inMemory: true,
            );
        }

        if (! $dbalConfiguration->deadLetterInMemory && ! $this->has($dbalConfiguration->getDeadLetterConnectionReference())) {
            $dbalConfiguration = $dbalConfiguration->withDeadLetter(
                $dbalConfiguration->deadLetterEnabled,
                $dbalConfiguration->getDeadLetterConnectionReference(),
                inMemory: true,
            );
        }

        if (! $dbalConfiguration->inMemoryDocumentStore && ! $this->has($dbalConfiguration->documentStoreConnectionReference)) {
            $dbalConfiguration = $dbalConfiguration->withDocumentStore(
                $dbalConfiguration->enableDbalDocumentStore,
                inMemoryDocumentStore: true,
                reference: $dbalConfiguration->dbalDocumentStoreReference,
                enableDocumentStoreStateStoredRepository: $dbalConfiguration->enableDocumentStoreStateStoredRepository,
                connectionReference: $dbalConfiguration->documentStoreConnectionReference,
                documentStoreRelatedAggregates: $dbalConfiguration->documentStoreRelatedAggregates,
            );
        }

        return $dbalConfiguration;
    }

    private function refuseDbalChannelsWithoutTheirConnection(): void
    {
        foreach (ExtensionObjectResolver::resolve(DbalBackedMessageChannelBuilder::class, $this->extensionObjects) as $channelBuilder) {
            if ($this->has($channelBuilder->connectionReferenceName)) {
                continue;
            }

            $channelName = $channelBuilder->getMessageChannelName();
            throw ConfigurationException::create(
                "Message Channel `{$channelName}` is a DbalBackedMessageChannelBuilder on connection `{$channelBuilder->connectionReferenceName}`, but this flow test was given no such connection, so the channel would have nowhere to store its messages. "
                . "Either pass the connection: EcotoneLite::bootstrapFlowTesting(containerOrAvailableServices: ['{$channelBuilder->connectionReferenceName}' => new DbalConnectionFactory(\$dsn)]), "
                . "or keep the channel in memory for this test by registering SimpleMessageChannelBuilder::createQueueChannel('{$channelName}') instead of the DbalBackedMessageChannelBuilder."
            );
        }
    }
}
