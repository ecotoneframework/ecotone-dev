<?php

declare(strict_types=1);

namespace Test\Ecotone\Modelling\DecisionModel;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\DecisionModel;
use Ecotone\Api\Attribute\EventSourcingHandler;
use Ecotone\Api\Attribute\EventTag;
use Ecotone\Api\Attribute\Fetch;
use Ecotone\Api\EventSourcing\DynamicConsistencyBoundaryConfiguration;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Handler\ExpressionEvaluationException;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\TestCase;
use Symfony\Component\ExpressionLanguage\SyntaxError;

/**
 * licence Enterprise
 * @internal
 */
final class DecisionModelExpressionFailureTest extends TestCase
{
    public function test_fetch_expression_syntax_error_names_attribute_parameter_handler_and_expression(): void
    {
        $ecotone = $this->bootstrap(SyntaxErrorHandlerForExpressionFailureTest::class);

        $this->expectException(ExpressionEvaluationException::class);
        $this->expectExceptionMessage(
            '#[Fetch] on $activity in ' . SyntaxErrorHandlerForExpressionFailureTest::class . '::count failed.'
            . ' Expression: payload..broken(((.'
            . ' Unclosed "(" around position 17 for expression `payload..broken(((`.'
        );

        $ecotone->sendCommand(new CountActivityForExpressionFailureTest('acc-1'));
    }

    public function test_fetch_expression_syntax_error_keeps_the_original_failure_as_previous(): void
    {
        $ecotone = $this->bootstrap(SyntaxErrorHandlerForExpressionFailureTest::class);

        try {
            $ecotone->sendCommand(new CountActivityForExpressionFailureTest('acc-1'));
            $this->fail('Expected expression evaluation to fail');
        } catch (ExpressionEvaluationException $exception) {
            $this->assertInstanceOf(SyntaxError::class, $exception->getPrevious());
        }
    }

    private function bootstrap(string $handlerClassName): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handlerClassName, ActivityForExpressionFailureTest::class, MoneyTransferredForExpressionFailureTest::class],
            containerOrAvailableServices: [new $handlerClassName()],
            configuration: ServiceConfiguration::createWithDefaults()->withExtensionObjects([DynamicConsistencyBoundaryConfiguration::createWithDefaults()]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }
}

final readonly class CountActivityForExpressionFailureTest
{
    public function __construct(
        public string $reference,
    ) {
    }
}

final readonly class MoneyTransferredForExpressionFailureTest
{
    public function __construct(
        #[EventTag('account')] public string $accountId,
    ) {
    }
}

#[DecisionModel]
final class ActivityForExpressionFailureTest
{
    private int $transferCount = 0;

    #[EventSourcingHandler]
    public function transferred(MoneyTransferredForExpressionFailureTest $event): void
    {
        $this->transferCount++;
    }

    public function transferCount(): int
    {
        return $this->transferCount;
    }
}

final class SyntaxErrorHandlerForExpressionFailureTest
{
    #[CommandHandler]
    public function count(
        CountActivityForExpressionFailureTest $command,
        #[Fetch('payload..broken(((')] ActivityForExpressionFailureTest $activity,
    ): array {
        return [];
    }
}
