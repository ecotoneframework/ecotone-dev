<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\InMemory;

use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\Lite\EcotoneLite;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Test\Ecotone\EventSourcing\Fixture\ValueObjectIdentifier\ArticleEventConverter;
use Test\Ecotone\EventSourcing\Fixture\ValueObjectIdentifier\PublishArticle;

/**
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
final class ValueObjectIdentifierTest extends TestCase
{
    public function test_handling_events_and_commands_with_value_object_identifiers(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            containerOrAvailableServices: [new ArticleEventConverter(), DbalConnectionFactory::class => EcotoneLiteEventSourcingTest::getConnectionFactory()],
            enabledModules: [ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE, ],
            pathToRootCatalog: __DIR__ . '/../../',
            namespaces: ['Test\Ecotone\EventSourcing\Fixture\ValueObjectIdentifier', ],
            environment: 'prod',
        );

        $articleId = Uuid::fromString('fc6023e7-1d48-4f59-abc9-72a087787d3e');
        $content = 'Good Book';

        $ecotone->sendCommand(new PublishArticle($articleId, $content));
        self::assertEquals(
            $content,
            $ecotone->sendQueryWithRouting('article.getContent', metadata: ['aggregate.id' => $articleId])
        );
    }
}
