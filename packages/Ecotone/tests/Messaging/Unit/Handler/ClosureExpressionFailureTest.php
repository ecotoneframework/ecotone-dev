<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Unit\Handler;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Handler\ExpressionEvaluationException;
use Ecotone\Messaging\Handler\MethodInvocationException;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\Attributes\RequiresPhp;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Test\Ecotone\Messaging\Fixture\Handler\ClosureInAttribute\FailingClosureExpressionService;

/**
 * licence Enterprise
 * @internal
 */
#[RequiresPhp('>= 8.5.0')]
final class ClosureExpressionFailureTest extends TestCase
{
    public function test_closure_expression_throwing_names_attribute_parameter_handler_and_reports_a_closure(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [FailingClosureExpressionService::class],
            [new FailingClosureExpressionService()],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        try {
            $ecotone->sendCommandWithRouting('closureExpressionFailure.throwing', 'order-1');
            $this->fail('Expected expression evaluation to fail');
        } catch (MethodInvocationException $exception) {
            $expressionFailure = $exception->getPrevious();
            $this->assertInstanceOf(ExpressionEvaluationException::class, $expressionFailure);
            $this->assertSame(
                '#[Payload] on $orderId in ' . FailingClosureExpressionService::class . '::withThrowingClosure failed.'
                . ' Expression: closure.'
                . ' closure blew up for order-1',
                $expressionFailure->getMessage(),
            );
            $this->assertInstanceOf(RuntimeException::class, $expressionFailure->getPrevious());
        }
    }
}
