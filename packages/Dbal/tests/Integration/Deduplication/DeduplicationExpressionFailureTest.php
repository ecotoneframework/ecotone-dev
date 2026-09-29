<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration\Deduplication;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Deduplicated;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Dbal\Connection\DbalConnectionFactory;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Handler\ExpressionEvaluationException;
use Test\Ecotone\Dbal\DbalMessagingTestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class DeduplicationExpressionFailureTest extends DbalMessagingTestCase
{
    public function test_deduplicated_expression_syntax_error_names_the_attribute_the_method_and_the_expression(): void
    {
        $ecotoneLite = $this->bootstrapFlowTesting(
            classesToResolve: [BrokenDeduplicationExpressionHandler::class],
            containerOrAvailableServices: [new BrokenDeduplicationExpressionHandler(), DbalConnectionFactory::class => $this->getConnectionFactory(true)],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withModulePackages([ModulePackageList::DBAL_PACKAGE])
        );

        $this->expectException(ExpressionEvaluationException::class);
        $this->expectExceptionMessage(
            '#[Deduplicated] in ' . BrokenDeduplicationExpressionHandler::class . '::handle failed.'
            . ' Expression: payload..broken(((.'
            . ' Unclosed "(" around position 17 for expression `payload..broken(((`.'
        );

        $ecotoneLite->sendCommandWithRouting('deduplicationExpressionFailure.handle', 'order-1');
    }
}

final class BrokenDeduplicationExpressionHandler
{
    #[Deduplicated(expression: 'payload..broken(((')]
    #[CommandHandler('deduplicationExpressionFailure.handle')]
    public function handle(): void
    {
    }
}
