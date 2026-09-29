<?php

declare(strict_types=1);

namespace Test\Ecotone\Modelling\DecisionModel;

use Ecotone\Api\Attribute\AggregateType;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\DecisionModel;
use Ecotone\Api\Attribute\EventSourcingAggregate;
use Ecotone\Api\Attribute\EventSourcingHandler;
use Ecotone\Api\Attribute\EventTag;
use Ecotone\Api\Attribute\Fetch;
use Ecotone\Api\Attribute\Identifier;
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

    public function test_fetch_expression_naming_an_unknown_service_names_the_place_and_the_expression(): void
    {
        $ecotone = $this->bootstrap(UnknownServiceHandlerForExpressionFailureTest::class);

        $this->expectException(ExpressionEvaluationException::class);
        $this->expectExceptionMessage(
            '#[Fetch] on $activity in ' . UnknownServiceHandlerForExpressionFailureTest::class . '::count failed.'
            . " Expression: reference('missingMapper').map(payload.reference)."
            . ' Reference missingMapper was not found in definitions'
        );

        $ecotone->sendCommand(new CountActivityForExpressionFailureTest('acc-1'));
    }

    public function test_fetch_expression_resolving_no_value_for_a_single_tag_model_names_the_model_the_tag_and_what_came_back(): void
    {
        $ecotone = $this->bootstrap(MissingHeaderHandlerForExpressionFailureTest::class);

        $this->expectException(ExpressionEvaluationException::class);
        $this->expectExceptionMessage(
            '#[Fetch] on $activity in ' . MissingHeaderHandlerForExpressionFailureTest::class . '::count failed.'
            . " Expression: headers['tenant'] ?? null."
            . ' DecisionModel ' . ActivityForExpressionFailureTest::class . " did not resolve tag 'account'."
            . ' The expression returned null.'
            . ' A single-tag model needs a scalar or Stringable value; declare the parameter nullable to let it contribute nothing.'
        );

        $ecotone->sendCommand(new CountActivityForExpressionFailureTest('acc-1'));
    }

    public function test_fetch_expression_returning_a_list_for_a_multi_tag_model_names_the_tags_it_must_be_keyed_by(): void
    {
        $handler = new ListForMultiTagHandlerForExpressionFailureTest();

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class, CouponUseForExpressionFailureTest::class, CouponRedeemedForExpressionFailureTest::class],
            containerOrAvailableServices: [$handler],
            configuration: ServiceConfiguration::createWithDefaults()->withExtensionObjects([DynamicConsistencyBoundaryConfiguration::createWithDefaults()]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $this->expectException(ExpressionEvaluationException::class);
        $this->expectExceptionMessage(
            '#[Fetch] on $usage in ' . ListForMultiTagHandlerForExpressionFailureTest::class . '::redeem failed.'
            . ' Expression: [payload.customerId, payload.couponCode].'
            . ' DecisionModel ' . CouponUseForExpressionFailureTest::class . " did not resolve tag 'customer'."
            . ' The expression returned a list of 2 values.'
            . " This model is scoped by 'customer' and 'coupon'; the expression must return an array keyed by those names."
        );

        $ecotone->sendCommand(new RedeemCouponForExpressionFailureTest('alice', 'SUMMER24'));
    }

    public function test_fetch_expression_returning_a_boolean_is_rejected_as_a_tag_value(): void
    {
        $ecotone = $this->bootstrap(BooleanTagHandlerForExpressionFailureTest::class);

        $this->expectException(ExpressionEvaluationException::class);
        $this->expectExceptionMessage(
            '#[Fetch] on $activity in ' . BooleanTagHandlerForExpressionFailureTest::class . '::count failed.'
            . ' Expression: true.'
            . ' DecisionModel ' . ActivityForExpressionFailureTest::class . " cannot use the value resolved for tag 'account':"
            . " Tag 'account' value must be a string, int, float or Stringable, got bool."
            . ' Did you mean to compare instead of return?'
        );

        $ecotone->sendCommand(new CountActivityForExpressionFailureTest('acc-1'));
    }

    public function test_fetch_expression_on_an_aggregate_backed_model_names_the_aggregate_and_what_came_back(): void
    {
        $handler = new AggregateBackedHandlerForExpressionFailureTest();

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class, WalletForExpressionFailureTest::class, WalletBalanceForExpressionFailureTest::class, WalletCreditedForExpressionFailureTest::class],
            containerOrAvailableServices: [$handler],
            configuration: ServiceConfiguration::createWithDefaults()->withExtensionObjects([DynamicConsistencyBoundaryConfiguration::createWithDefaults()]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $this->expectException(ExpressionEvaluationException::class);
        $this->expectExceptionMessage(
            '#[Fetch] on $balance in ' . AggregateBackedHandlerForExpressionFailureTest::class . '::credit failed.'
            . " Expression: headers['tenant'] ?? null."
            . ' DecisionModel ' . WalletBalanceForExpressionFailureTest::class
            . ' did not resolve an identifier of aggregate ' . WalletForExpressionFailureTest::class . '.'
            . ' The expression returned null.'
        );

        $ecotone->sendCommand(new CreditWalletForExpressionFailureTest('w-1'));
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

final class UnknownServiceHandlerForExpressionFailureTest
{
    #[CommandHandler]
    public function count(
        CountActivityForExpressionFailureTest $command,
        #[Fetch("reference('missingMapper').map(payload.reference)")] ActivityForExpressionFailureTest $activity,
    ): array {
        return [];
    }
}

final class MissingHeaderHandlerForExpressionFailureTest
{
    #[CommandHandler]
    public function count(
        CountActivityForExpressionFailureTest $command,
        #[Fetch("headers['tenant'] ?? null")] ActivityForExpressionFailureTest $activity,
    ): array {
        return [];
    }
}

final class BooleanTagHandlerForExpressionFailureTest
{
    #[CommandHandler]
    public function count(
        CountActivityForExpressionFailureTest $command,
        #[Fetch('true')] ActivityForExpressionFailureTest $activity,
    ): array {
        return [];
    }
}

final readonly class RedeemCouponForExpressionFailureTest
{
    public function __construct(
        public string $customerId,
        public string $couponCode,
    ) {
    }
}

final readonly class CouponRedeemedForExpressionFailureTest
{
    public function __construct(
        #[EventTag('customer')] public string $customerId,
        #[EventTag('coupon')] public string $couponCode,
    ) {
    }
}

#[DecisionModel(tags: ['customer', 'coupon'])]
final class CouponUseForExpressionFailureTest
{
    private bool $used = false;

    #[EventSourcingHandler]
    public function redeemed(CouponRedeemedForExpressionFailureTest $event): void
    {
        $this->used = true;
    }

    public function alreadyUsed(): bool
    {
        return $this->used;
    }
}

final class ListForMultiTagHandlerForExpressionFailureTest
{
    #[CommandHandler]
    public function redeem(
        RedeemCouponForExpressionFailureTest $command,
        #[Fetch('[payload.customerId, payload.couponCode]')] CouponUseForExpressionFailureTest $usage,
    ): array {
        return [];
    }
}

final readonly class CreditWalletForExpressionFailureTest
{
    public function __construct(
        public string $walletId,
    ) {
    }
}

final readonly class WalletCreditedForExpressionFailureTest
{
    public function __construct(
        public string $walletId,
    ) {
    }
}

#[EventSourcingAggregate]
#[AggregateType('WalletForExpressionFailure')]
final class WalletForExpressionFailureTest
{
    #[Identifier]
    public string $walletId;

    #[EventSourcingHandler]
    public function credited(WalletCreditedForExpressionFailureTest $event): void
    {
        $this->walletId = $event->walletId;
    }
}

#[DecisionModel(aggregate: WalletForExpressionFailureTest::class)]
final class WalletBalanceForExpressionFailureTest
{
    private int $credits = 0;

    #[EventSourcingHandler]
    public function credited(WalletCreditedForExpressionFailureTest $event): void
    {
        $this->credits++;
    }

    public function credits(): int
    {
        return $this->credits;
    }
}

final class AggregateBackedHandlerForExpressionFailureTest
{
    #[CommandHandler]
    public function credit(
        CreditWalletForExpressionFailureTest $command,
        #[Fetch("headers['tenant'] ?? null")] WalletBalanceForExpressionFailureTest $balance,
    ): array {
        return [];
    }
}
