# Expression failure context — implementation report

Branch: `dgafka/implement-expression-failure-context`, based on `dgafka/ecotone-2-0-dcb-design` at `6ca72c2d`.
Direction: "Let's make the expression failure handling for all expressions. Meaning we mention specific place where
it happened, method, attribute, expression, and it works for normal expressions too."

## What was built

One value object, `Ecotone\Messaging\Handler\ExpressionLocation`, is built at container build time next to every
expression and carries four strings — the attribute (or step) name, the target it fills, the owning `Class::method`
(or endpoint), and the expression text, or `closure`. One exception,
`Ecotone\Messaging\Handler\ExpressionEvaluationException`, extends the runtime `MessagingException` (error code
`WRONG_EXPRESSION_TO_EVALUATE`, previously unused) and renders one template:

```
<attribute> on <target> in <Class::method> failed. Expression: <expression>. <cause>
```

`ExpressionEvaluationException::wrapping()` returns an already-wrapped exception untouched, so an inner executor's
failure is never wrapped twice; `::because()` builds the same message from a cause Ecotone states itself.
`Ecotone\Messaging\Handler\ExpressionResult::describe()` renders what an expression returned
(`null`, `bool true`, `int 7`, `string 'abc'`, `a list of 2 values`, `an array with keys 'a', 'b'`, or the type).

## The commits

| Commit | Step |
|---|---|
| `c45cf02b` | `ExpressionLocation`, `ExpressionEvaluationException`, the `AttributeExpressionExecutor` boundary and its compiler wiring |
| `47a5ab86` | string expressions on `#[Payload]`, `#[Header]`, `#[Reference]` and the gateway payload converter |
| `7bccfd4e` | enricher, transformer and the `AttributeExpressionContextExecutor` (`#[DbalParameter]`) |
| `9b131f9c` | DCB tag values, aggregate-backed identifiers, fetched aggregates, and the `bool` rejection |
| `84623fa0` | `#[Deduplicated]` test under `packages/Dbal/tests` |
| `2e62bc68` | `upgrade-2.0.md` section and the DCB cross-reference |
| `8f065ed9` | docblocks dropped, `describe()` made private |

## The exact message per producer

Every line below is asserted verbatim by a test; class names are shortened for reading.

| Producer | Message |
|---|---|
| `#[Fetch]` model, syntax error | `#[Fetch] on $activity in SyntaxErrorHandler::count failed. Expression: payload..broken(((. Unclosed "(" around position 17 for expression `payload..broken(((`.` |
| `#[Fetch]` model, unknown `reference()` | `#[Fetch] on $activity in UnknownServiceHandler::count failed. Expression: reference('missingMapper').map(payload.reference). Reference missingMapper was not found in definitions` |
| `#[Fetch]` model, header absent, non-nullable | `#[Fetch] on $activity in MissingHeaderHandler::count failed. Expression: headers['tenant'] ?? null. DecisionModel Activity did not resolve tag 'account'. The expression returned null. A single-tag model needs a scalar or Stringable value; declare the parameter nullable to let it contribute nothing.` |
| `#[Fetch]` model, list for a multi-tag model | `#[Fetch] on $usage in ListForMultiTagHandler::redeem failed. Expression: [payload.customerId, payload.couponCode]. DecisionModel CouponUse did not resolve tag 'customer'. The expression returned a list of 2 values. This model is scoped by 'customer' and 'coupon'; the expression must return an array keyed by those names.` |
| `#[Fetch]` model, `bool` result (G7) | `#[Fetch] on $activity in BooleanTagHandler::count failed. Expression: true. DecisionModel Activity cannot use the value resolved for tag 'account': Tag 'account' value must be a string, int, float or Stringable, got bool. Did you mean to compare instead of return?` |
| aggregate-backed model identifier | `#[Fetch] on $balance in AggregateBackedHandler::credit failed. Expression: headers['tenant'] ?? null. DecisionModel WalletBalance did not resolve an identifier of aggregate Wallet. The expression returned null.` |
| `#[Fetch]` aggregate | `#[Fetch] on $user in ComplexService::handleComplexCommand failed. Expression: reference('identifierMapper').map(payload.email). Aggregate User cannot be fetched: the expression returned null. Declare the parameter nullable to accept a missing identifier.` |
| `#[Payload(expression)]` | `#[Payload] on $value in ExpressionFailureService::withBrokenPayloadExpression failed. Expression: payload..broken(((. Unclosed "(" around position 17 for expression `payload..broken(((`.` |
| `#[Payload(expression)]`, service throws | `#[Payload] on $mapped in ExpressionFailureService::withThrowingService failed. Expression: reference('expressionFailureMapper').blowUp(payload). mapper blew up` (original kept as `$previous`) |
| `#[Header(expression)]` | `#[Header] on $token in ExpressionFailureService::withUnknownReferenceInHeaderExpression failed. Expression: reference('missingMapper').map(value). Reference missingMapper was not found in definitions` |
| `#[Header(expression)]`, header absent | same prefix, then `Header 'token' is not available in the message, and the parameter does not allow null.` |
| `#[Reference(expression)]` | `#[Reference] on $mapper in ExpressionFailureService::withBrokenReferenceExpression failed. Expression: service.notThere(payload). …` |
| `#[AddHeader(expression)]` | `#[AddHeader] in ExpressionFailureService::withBrokenAddHeaderExpression failed. Expression: payload..broken(((. Unclosed "(" …` |
| `#[Deduplicated(expression)]` | `#[Deduplicated] in BrokenDeduplicationExpressionHandler::handle failed. Expression: payload..broken(((. Unclosed "(" …` |
| PHP 8.5 closure | `#[Payload] on $orderId in FailingClosureExpressionService::withThrowingClosure failed. Expression: closure. closure blew up for order-1` |
| transformer | `Transformer in endpoint 'transformerInputChannel' failed. Expression: payload..broken(((. Unclosed "(" …` |
| enricher, payload path | `Enricher on payload path 'token' failed. Expression: payload..broken(((. Unclosed "(" …` |
| enricher, header path | `Enricher on header path 'token' failed. Expression: reference('missingMapper').map(payload). Reference missingMapper was not found in definitions` |

A handler *parameter* still surfaces to the caller as `MethodInvocationException` ("Cannot resolve parameter 'x'
while calling …"), as every parameter converter always has; the expression failure is its `$previous` and its message
is quoted under `Reason:`. A `#[Fetch]`-ed decision model loads in a before-interceptor, outside that loop, so its
`ExpressionEvaluationException` reaches the caller directly.

## Behaviour changes beyond the exception class and message

Three catches move, all documented in `upgrade-2.0.md` §14:

- a `#[Fetch]` tag value or identifier that did not resolve or normalise: `ConfigurationException` →
  `ExpressionEvaluationException`;
- a `#[Fetch]`-ed aggregate whose expression resolved no identifier: `AggregateNotFoundException` →
  `ExpressionEvaluationException` (a genuine not-found still throws `AggregateNotFoundException`);
- a required header absent for an expression-backed `#[Header]`: `InvalidArgumentException` →
  `ExpressionEvaluationException`.

And G7: a `bool` is rejected as a tag value on both sides of the boundary. `#[Fetch('true')]` silently produced `"1"`
and `#[Fetch('false')]` produced a confusing "value cannot be empty"; both now say what a tag value may be and ask
whether a comparison was meant.

Values, evaluation count and ordering are unchanged — asserted by
`ExpressionFailureContextTest::test_working_expression_keeps_its_value_and_is_evaluated_once_per_parameter`, which
sends a command through a working `reference()` expression and checks both the returned value and that the mapper was
called exactly once.

## What the direction did not settle, and how it was resolved

1. **`ClosureExpressionParameterConverter` has nothing left to catch.** The brief named it as a boundary. Its whole
   body is `AdditionalContextResolver::resolve()` (which cannot throw) followed by `AttributeExpressionExecutor::execute()`,
   which is the boundary. A nested converter failing inside `ClosureParameterResolver::resolve()` already runs inside
   `execute()`. A second `try`/`catch` there would be unreachable, so it was not added.
2. **`MessageAggregateIdentifierResolver` has no expression and never throws.** It returns `null`; the convention-path
   failure is raised by `AggregateBackedDecisionModelLoader::identifiersFromPayload()` as a `ConfigurationException`.
   Per "unresolvable by convention stays `ConfigurationException`" that was left alone; only the expression branch of
   the same loader now throws `ExpressionEvaluationException`. Confirmed with the coordinator.
3. **`#[Deduplicated]` lives in `packages/Dbal`, which the brief excluded.** No production change was needed — its
   expression already flows through the shared `AttributeExpressionExecutor` via `#[ExecutorFor]`. The coordinator
   confirmed the exclusion covered production code only, so a test-only file was added under `packages/Dbal/tests`.
4. **Not every expression has a `Class::method`.** The enricher and the expression transformer are channel-level
   configuration with no attribute and no owning method. `ExpressionLocation` degrades honestly: it drops the target
   for a class- or method-level attribute (`#[AddHeader] in C::m`) and drops the owner for a property editor
   (`Enricher on payload path 'token'`); the transformer names its input channel instead of a method.
5. **The brief said every expression passes through `AttributeExpressionExecutorCompiler`.** That holds for closures
   and for `#[Fetch]`, but string expressions on `#[Payload]`, `#[Header]` and `#[Reference]` compile to
   `PayloadExpressionConverter`, `HeaderExpressionConverter` and `ReferenceConverter`, which never touch the executor.
   Each of those was given the same location and the same boundary; the gateway's
   `GatewayPayloadExpressionConverter` was included for the same reason.
6. **Left out, deliberately, and still raw:** the aggregate `identifierMapping` expression
   (`AggregateIdentifierRetrevingService`), the `#[Poller]` cron / fixed-rate expressions
   (`InterceptedConsumerRunner`) and the Kafka topic expression (`KafkaAdmin`). None is on the brief's producer list
   and none is a DCB path; they would each need their own location source. Worth a follow-up if "all expressions"
   should mean literally all.

## Verification

Run inside the compose `app` container (PHP 8.5.3, PHPUnit 12.5.36), one package at a time, on a clean tree.

| Check | Result |
|---|---|
| `packages/Ecotone` phpunit | 1578 tests, 2643 assertions, 4 failures |
| `packages/Ecotone` phpstan | 37 errors |
| `packages/PdoEventSourcing` phpunit, PostgreSQL | 384 tests, 900 assertions, 13 skipped, green |
| `packages/PdoEventSourcing` phpunit, MySQL 8.0 | 384 tests, 899 assertions, 11 skipped, green |
| `packages/PdoEventSourcing` phpstan | green |
| `packages/Symfony` full suite (incl. `DcbSmokeTest`) | 51 tests, 98 assertions, green |
| `packages/Laravel` full suite (incl. `DcbSmokeTest`) | 44 tests, 88 assertions, green (20 pre-existing "risky" error-handler notices) |
| `packages/Dbal` business-method + new deduplication test, root vendor | green |
| `php bin/check-licence.php` | no output |
| `php-cs-fixer` on every changed file | clean |

**The two failing counts are pre-existing and unrelated.** The 4 `ConsoleInvocationResolverTest` failures come from
`ConsoleInvocationResolver::isPackageActive()` calling `class_exists()` on Symfony/Laravel/Tempest module classes that
the isolated `packages/Ecotone/vendor` install does not contain. The 37 phpstan errors are all `class.notFound` for
optional sibling packages (`EcotoneLite.php`, `ModuleClassList.php`, `CrossConnectionDecisionModelGuard.php`) — none in
a file this branch touches. Both are artefacts of per-package installs, not of this change.
