## 10. Tests validate at the userland level only

**The single most-corrected rule in this repository** — a whole worktree, 22 of `implement-blackbox-tests`'s 22
own commits, did nothing but pull tests back to the public surface, with no new coverage in any of them, and two
of the 22 (`f2ef0469a`, `f383622f4`) walk back the rewrite's own over-correction. Whatever the test needs to know,
it asks the public API.

Never, in a test:

- read Ecotone's own tables with SQL
- reach a service by its internal reference
- use reflection
- count SQL statements
- name an internal collaborator class in the test's own name
- construct or call any class outside `Api/` — a store, recorder, ledger, module, extension object or channel
  interceptor from `src/` — even to prove two implementations of a seam behave alike
- drop, alter or write Ecotone's tables to simulate a failure

The test of a test: rewrite every class under `src/` the feature touches, keep the behaviour, and the test must not
change. A contract suite that constructs `InMemoryTraceStore` and `DbalTraceStore` and calls `append()` fails that test
the moment the store's methods are renamed, while the behaviour it guards — a trace completes once and notifies once —
is unchanged. Prove it instead through the application: boot it once per storage and drive both through the bus and
the `TraceCompletion` gateway.

A failure the test needs comes through a seam the application owns. A database outage right after a commit is a
Doctrine connection the application hands Ecotone, whose statements start failing when an `#[Around]` interceptor
just outside the transaction says so — not a `DROP TABLE` on Ecotone's tables. If a path cannot be reached from
userland at all, the application cannot observe it: remove the code instead of testing it from the inside.

```php
use Ecotone\Api\EventSourcing\EventStore;

// wrong — bypasses the gateway to reach the implementation
$store = $ecotone->getServiceFromContainer(EventStore::RAW_REFERENCE);

// right
$store = $ecotone->getGateway(EventStore::class);
```

```php
// wrong — asserts on Ecotone's internal tables
$indexRows = (int) $connection->executeQuery(
    "SELECT COUNT(*) FROM ecotone_tagged_events WHERE tag_name = 'coupon' AND tag_value = 'SUMMER24'"
)->fetchOne();
self::assertSame(2, $indexRows);

// right — the behaviour already proves it
$this->expectExceptionMessage('Coupon SUMMER24 is exhausted');
$commandBus->send(new RedeemCoupon('SUMMER24'));
```

The *why*, from `3c5bdf306`'s own message: "The exhaustion exception on the third redeem already proves both prior
events were correctly tagged and folded into the decision model — the raw counts were a redundant internal check
on top of that." If the observable behaviour is right, the internals are right; asserting both only guarantees the
test breaks the next time the internals are refactored.

**What to do instead**, in order of preference:

1. Assert on what the application observes: a query handler's answer, a published event, an exception and its
   message.
2. If you need the store, the projection manager or the dead letter, fetch it as a **gateway**:
   `$ecotone->getGateway(EventStore::class)`, and read through its public methods
   (`loadByCriteria()`, `popRecordedEvents()`).
3. For a console command, assert on its console output (`9e1d89d43`, `70dad03b8`).
4. Name the test after the behaviour, not the class that implements it (`294c0a3d4`).

**A message channel is a transport, not a surface.** Fetching a channel and calling `send()` and `receive()` on it
proves what the channel did, not what the application saw, and it skips every interceptor the application's own path
runs through, so it stays green while the real path breaks. `FlowTestSupport` therefore has no `getMessageChannel()`,
`receiveMessageFrom()` or `sendMessageDirectToChannel()` any more; Ecotone's own channel and interceptor suites reach
a channel through the internal `Ecotone\Test\ChannelTestSupport`, and nothing else does. The 2.0.0-beta.2 clock
regression is the evidence: three `DbalBackedMessageChannelTest` delayed-message tests drove the channel directly
and passed, while a command sent through the bus — the transaction interceptor first, then the delayed handler —
never became due on the test clock. Drive the channel the way the application does, and assert on the handler:

```php
// wrong — proves the channel, not the application (the method no longer exists on FlowTestSupport)
$channel = $ecotone->getMessageChannel('orders');
$channel->send(MessageBuilder::withPayload('some')->setHeader(MessageHeaders::DELIVERY_DELAY, 2000)->build());
$ecotone->advanceTimeBy(Duration::seconds(3));
self::assertNotNull($channel->receive());

// right — the #[Asynchronous('orders')] handler is what the application observes
$ecotone->sendCommandWithRouting('order.confirm', 'order-1');
$ecotone->run('orders');
self::assertSame([], $ecotone->sendQueryWithRouting('order.confirmed'));
$ecotone->advanceTimeBy(Duration::seconds(3))->run('orders');
self::assertSame(['order-1'], $ecotone->sendQueryWithRouting('order.confirmed'));
```

Evidence: `12d960553` (the `implement-blackbox-tests` worktree), `3c5bdf306`, `d15d2627d`, `8065c0b19`,
`50354a085`, `d3920e4f2`, `f000f86ef`, `191178d36`, `e7e2c11a8`, `cb1ca48be`, `4a2acf01d`, `9e1d89d43`,
`70dad03b8`, `c11b863dc`, `2f54403fe`, `8b7fec7f7`, `04e831b55`, `294c0a3d4`.

A performance or statement-count claim is **review-protected, not test-protected** — `04e831b55` deleted a
statement-counting test rather than keep it. State the claim in the spec and let review hold it. The DCB effort
proved this twice over one claim: `6b30eb5cd` added a `QueryCountingDbalConnection` to assert one `loadByCriteria()`
per handler however many decision models it injects, and `04e831b55` deleted it again — "one-load-per-handler is
review-protected". What a test *can* hold is the behaviour: the N>1 case has to work, and that is rule 10a.

### 10a. A guarantee is proved on every path that has to hold it

Ecotone reaches the same behaviour several ways — a direct call, `CommandBus::send()` resolving by payload class,
`CommandBus::sendWithRouting()`, a gateway, a console command — and a change that updates one of them looks
finished. Two of the three DCB friction reports found this shape five times between them:

| The guarantee | The path that was missed | Commit |
|---|---|---|
| A tagged append is guarded by the tag versions captured inside the transaction | aggregate saves through `SaveAggregateService` bumped tag counters unconditionally, so a commit competing with a loaded aggregate was not caught | `c09bac1e0` |
| Filter-only tags are indexed | the live append path indexed them, the backfill that reconstructs the index did not, so a backfilled index silently disagreed with a live one — fixed by sharing one `EventsTags::sequencedBy` | `0548c4130` |
| `#[WithoutDatabaseTransaction]` is honoured | fixed for the `CommandBus` gateway wrapper; still broken for a class-routed `CommandBus::send()`, which resolves routing through `BusRoutingKeyResolver` instead | `57c076084`, then `9ae4e3fd3` |
| `loadByCriteria()` is on the unified `EventStore` | never registered as a gateway action in `EventSourcingModule`, so an `EventStore` from the container or the gateway did not expose it | `ade73e6c5`, then `99daa69ba` |

Before the commit that adds a method to an interface, an attribute to a handler, or behaviour to a write path:
enumerate the entry points it must work through, and write one test per entry point. The live path and the
reconstruction path of one concept count as two of them — and they should share one method rather than be
maintained as two.

---

## 11. Test shape

Every test bootstraps Ecotone and drives it as a user would.

```php
<?php

declare(strict_types=1);

namespace Test\Ecotone\Modelling\AggregateBoundary;

use Ecotone\Api\Attribute\Aggregate;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Identifier;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Lite\EcotoneLite;
use PHPUnit\Framework\TestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class BasketTest extends TestCase
{
    public function test_adding_an_item_is_visible_to_the_query_side(): void
    {
        $basket = new #[Aggregate] class () {
            #[Identifier]
            private string $basketId = '';

            private int $items = 0;

            #[CommandHandler('basket.open')]
            public static function open(string $basketId): self
            {
                $basket = new self();
                $basket->basketId = $basketId;

                return $basket;
            }

            #[CommandHandler('basket.add')]
            public function add(): void
            {
                $this->items++;
            }

            #[QueryHandler('basket.items')]
            public function items(): int
            {
                return $this->items;
            }
        };

        $ecotone = EcotoneLite::bootstrapFlowTesting([$basket::class]);
        $ecotone->sendCommandWithRouting('basket.open', 'b-1');

        $ecotone->sendCommandWithRouting('basket.add', metadata: ['aggregate.id' => 'b-1']);

        $this->assertSame(1, $ecotone->sendQueryWithRouting('basket.items', metadata: ['aggregate.id' => 'b-1']));
    }
}
```

- **`EcotoneLite::bootstrapFlowTesting()`** for everything. A test that needs the real event store or another DBAL
  seam passes the connection; one that passes none runs them in memory.
- **`snake_case` method names**, enforced by php-cs-fixer. Name after the behaviour, not the implementation:
  `test_adding_an_item_is_visible_to_the_query_side`, not `test_basket_repository_saves`.
- **`final class`**, with `/** licence Apache-2.0 @internal */`. php-cs-fixer adds `@internal`.
- **No comments, no docblocks, no assertion messages.** The method name is the description.
- **Fixtures live in the test file, as inline anonymous classes.** Never a shared `Fixture/` directory — and not a
  named class below the `TestCase` where an anonymous one does the job. Aggregates included: the example above is
  one, and an anonymous class is registered by the generated name its own instance reports, which is all
  `classesToResolve` needs. `packages/Ecotone/tests/Modelling/Unit/MessageBusTest.php:452` and
  `packages/Ecotone/tests/Modelling/Unit/Causation/AggregateNotFoundCausationTest.php:25` are the committed
  precedents: anonymous aggregates with `#[Identifier]` properties, static `#[CommandHandler]` factories, query
  handlers, and the service handlers around them, all registered through `$fixture::class`.

  **A large share of the existing tests do not follow this.** 92 of the 141 test files added since 1.x declare
  named fixtures below the `TestCase`. That is drift to be reduced, not a convention to copy: a new test uses an
  anonymous class unless one of the four exceptions below applies to that particular class.

  **The four exceptions.** Each is a hard limit of PHP or of a storage engine rather than a preference, and nothing
  outside them qualifies:

  1. **A class used as a type declaration** — every command, event and query, and any object a handler,
     `#[Apply]` or converter names in a parameter or return type. PHP has no syntax for naming an
     anonymous class in a type. `AggregateNotFoundCausationTest` is the shape to copy: `OrderCreated` is a named
     class below the `TestCase`, while both aggregates and the event handler that pass it around are anonymous.
  2. **A class named inside another fixture's attribute argument** — an attribute argument is a constant
     expression, so `$fixture::class` cannot appear in one and the referenced class needs a name written in
     source: `#[FromAggregateStream(EventSourcedBasket::class)]`
     (`packages/JmsConverter/tests/Integration/InterfaceTypedPayloadTest.php:68`, where the projection carrying the
     attribute is itself anonymous) and `#[DecisionModel(aggregate: WalletForAggregateBackedTest::class)]`
     (`packages/Ecotone/tests/Modelling/DecisionModel/AggregateBackedDecisionModelTest.php:370`).
  3. **An event-sourced aggregate on DBAL that relies on its default aggregate type.** An anonymous class's name
     carries a NUL byte (`class@anonymous\0/path/File.php:LINE$0`), and the aggregate type defaults to the class
     name and is written into the event `metadata` column. PostgreSQL's `jsonb` refuses the `\u0000`
     (`SQLSTATE[22P05]: Untranslatable character`), and SQLite stores it but cannot find the aggregate again
     (`AggregateNotFoundException: Aggregate class@anonymous\u{0000}/…`); MySQL and MariaDB pass. It is the
     **aggregate type**, not the stream name: with `#[AggregateType('…')]` the same anonymous aggregate passes on
     all four engines, with the default stream and with an explicit `#[Stream]`. So the limit is narrow — such an
     aggregate can be anonymous only by gaining `#[AggregateType]`, and where the test is about the default type it
     cannot be anonymous at all. Evidence:
     `docs/superpowers/research/rule-11-named-fixtures/nul-byte-experiment.php.txt`, four variants on four engines.
  4. **Syntax newer than the PHP 8.2 floor.** PHPUnit parses a test file whether or not it then skips the test, so a
     fixture using newer syntax is a parse error on 8.2 even under `#[RequiresPhp]`. It lives in a file of its own
     that only the `#[RequiresPhp('>= 8.5.0')]` test loads:
     `packages/Ecotone/tests/Messaging/Fixture/Handler/ClosureInAttribute/FailingClosureExpressionService.php`,
     a closure in an attribute argument, loaded only by `ClosureExpressionFailureTest`.

  Two softer limits, which are notes rather than exceptions: a `#[MessageGateway]` is an interface, and PHP has no
  anonymous interface; and `get_debug_type()` reports any anonymous object as `class@anonymous`, so a test that
  asserts a payload's type as observable data needs a named payload — interpolating `::class` covers an exception
  message, not that.

  Three things that look like exceptions and are not:

  - **`#[AggregateType]`** takes a string name, not a class-string
    (`packages/Ecotone/Api/Attribute/AggregateType.php`), so it forces nothing — an anonymous aggregate carries it.
  - **`EventCriteria`** takes its class-strings at runtime — `EventCriteria::aggregate(string $aggregateClass, ...)`,
    `ofTypes(string ...$eventTypes)` — where `$basket::class` works. The event types it names are already named
    under exception 1, never for `EventCriteria`'s sake.
  - **An exception message containing the class name.** The test holds the instance, so interpolate it:
    `$this->expectExceptionMessage($service::class . '::handle')`. Three test files do exactly that, in five places —
    `packages/Dbal/tests/Integration/MultiTenant/WithTenantResolverPlacementValidationTest.php:47`,
    `WithTenantResolverLicensingTest.php:38`, and `AggregateNotFoundCausationTest.php:60`, which builds an entire
    expected message out of `$wallet::class` and `$walletCharging::class`. Better still, assert the part of the
    message that is the instruction and leave the class name out of it.

  Where an exception does apply, suffix the named fixture per test file (`UnlockedBasketForWithoutCounterTag`) so two
  test files in one namespace cannot collide.
  `packages/Ecotone/tests/Modelling/AggregateBoundary/WithoutCounterTagTest.php` is a full example of the named
  form — including, at line 81, an exception-message assertion that could have interpolated `::class` instead.
- **No static properties or static methods** in a test class, for the same reason as rule 3.
- A private `bootstrap()` helper on the test class is fine and common when several tests need the same wiring.
- **An Enterprise feature is tested with `licenceKey: LicenceTesting::VALID_LICENCE`** (`Ecotone\Test\LicenceTesting`),
  the key this repository's suites share. It is internal to them: the skills and the published documentation show an
  application passing its own key from the test environment instead.

**Write the test first.** RED, then GREEN, then refactor, one increment per commit, and prove the test is red
against the base commit before making it pass. This was in every DCB task brief. `424220979` is a RED commit
landed on its own.

---

