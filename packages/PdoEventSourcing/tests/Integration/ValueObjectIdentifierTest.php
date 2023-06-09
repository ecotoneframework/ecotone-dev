<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Test\Ecotone\EventSourcing\Fixture\ValueObjectIdentifier\ArticleEventConverter;
use Test\Ecotone\EventSourcing\Fixture\ValueObjectIdentifier\PublishArticle;

final class ValueObjectIdentifierTest extends TestCase
{
    public function test_handling_events_and_commands_with_value_objectIdentifiers(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            containerOrAvailableServices: [new ArticleEventConverter()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withSkippedModulePackageNames([ModulePackageList::AMQP_PACKAGE, ModulePackageList::JMS_CONVERTER_PACKAGE])
                ->withNamespaces([
                    'Test\Ecotone\EventSourcing\Fixture\ValueObjectIdentifier',
                ]),
            pathToRootCatalog: __DIR__ . '/../../'
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
