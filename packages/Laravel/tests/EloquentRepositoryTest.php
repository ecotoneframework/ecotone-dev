<?php

namespace Test\Ecotone\Laravel;

use Ecotone\Api\Attribute\Aggregate;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Header;
use Ecotone\Api\Attribute\Identifier;
use Ecotone\Api\Attribute\IdentifierMethod;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Attribute\Repository;
use Ecotone\Api\Attribute\Version;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Messaging\ConcurrencyException;
use Ecotone\Api\Modelling\StateStoredRepository;
use Ecotone\Api\Modelling\WithAggregateVersioning;
use Ecotone\Laravel\EloquentRepositoryBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;

/**
 * licence Apache-2.0
 * @internal
 */
class EloquentRepositoryTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'eloquent_repository');
        $app['config']->set('database.connections.eloquent_repository', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('tickets', function (Blueprint $table) {
            $table->string('ticket_id')->primary();
            $table->string('title');
            $table->integer('version');
        });
        Schema::create('notes', function (Blueprint $table) {
            $table->string('note_id')->primary();
            $table->string('text');
        });
    }

    public function test_a_versioned_eloquent_aggregate_loaded_twice_refuses_the_second_save_naming_the_way_out(): void
    {
        $ticket = $this->versionedTicket();
        $ecotone = $this->bootstrapWith($ticket::class);
        $ecotone->sendCommandWithRouting('ticket.open', metadata: ['ticketId' => 't-1']);

        try {
            $ecotone->sendCommandWithRouting('ticket.renameWhileAnotherRenameLands', metadata: ['aggregate.id' => 't-1', 'title' => 'Overwritten']);
            $this->fail('Expected the second save of the ticket to be refused');
        } catch (ConcurrencyException $exception) {
            $this->assertStringContainsString('Aggregate ' . $ticket::class . ' t-1 was loaded at version 1, but it is at version 2 now', $exception->getMessage());
            $this->assertStringContainsString('Load it again and retry the command', $exception->getMessage());
        }

        $this->assertSame('Landed first', $ecotone->sendQueryWithRouting('ticket.title', metadata: ['aggregate.id' => 't-1']));
        $this->assertSame(2, $ecotone->sendQueryWithRouting('ticket.version', metadata: ['aggregate.id' => 't-1']));
    }

    public function test_sequential_commands_on_a_versioned_eloquent_aggregate_each_save_and_move_its_version(): void
    {
        $ecotone = $this->bootstrapWith($this->versionedTicket()::class);

        $ecotone->sendCommandWithRouting('ticket.open', metadata: ['ticketId' => 't-1']);
        $this->assertSame(1, $ecotone->sendQueryWithRouting('ticket.version', metadata: ['aggregate.id' => 't-1']));

        $ecotone->sendCommandWithRouting('ticket.rename', metadata: ['aggregate.id' => 't-1', 'title' => 'First']);
        $this->assertSame(2, $ecotone->sendQueryWithRouting('ticket.version', metadata: ['aggregate.id' => 't-1']));

        $ecotone->sendCommandWithRouting('ticket.rename', metadata: ['aggregate.id' => 't-1', 'title' => 'Second']);
        $this->assertSame(3, $ecotone->sendQueryWithRouting('ticket.version', metadata: ['aggregate.id' => 't-1']));
        $this->assertSame('Second', $ecotone->sendQueryWithRouting('ticket.title', metadata: ['aggregate.id' => 't-1']));
    }

    public function test_a_handler_chained_after_creating_a_versioned_eloquent_aggregate_saves_it_again(): void
    {
        $ecotone = $this->bootstrapWith($this->versionedTicket()::class);

        $ecotone->sendCommandWithRouting('ticket.openTitled', metadata: ['ticketId' => 't-1', 'title' => 'Titled']);

        $this->assertSame('Titled', $ecotone->sendQueryWithRouting('ticket.title', metadata: ['aggregate.id' => 't-1']));
        $this->assertSame(2, $ecotone->sendQueryWithRouting('ticket.version', metadata: ['aggregate.id' => 't-1']));
    }

    public function test_creating_a_versioned_eloquent_aggregate_under_an_id_that_is_already_stored_is_refused(): void
    {
        $ticket = $this->versionedTicket();
        $ecotone = $this->bootstrapWith($ticket::class);
        $ecotone->sendCommandWithRouting('ticket.open', metadata: ['ticketId' => 't-1']);
        $ecotone->sendCommandWithRouting('ticket.rename', metadata: ['aggregate.id' => 't-1', 'title' => 'Kept']);

        try {
            $ecotone->sendCommandWithRouting('ticket.open', metadata: ['ticketId' => 't-1']);
            $this->fail('Expected the stored ticket to be kept');
        } catch (ConcurrencyException $exception) {
            $this->assertStringContainsString('Aggregate ' . $ticket::class . ' t-1 was loaded at version 0, but it is at version 2 now', $exception->getMessage());
        }

        $this->assertSame('Kept', $ecotone->sendQueryWithRouting('ticket.title', metadata: ['aggregate.id' => 't-1']));
    }

    public function test_an_eloquent_aggregate_versioned_through_the_trait_loaded_twice_refuses_the_second_save(): void
    {
        $ticket = new #[Aggregate] class () extends Model {
            use WithAggregateVersioning;

            protected $table = 'tickets';
            protected $primaryKey = 'ticket_id';
            protected $keyType = 'string';
            public $incrementing = false;
            public $timestamps = false;

            #[CommandHandler('ticket.open')]
            public static function open(#[Header('ticketId')] string $ticketId): self
            {
                $ticket = new self();
                $ticket->ticket_id = $ticketId;
                $ticket->title = 'Untitled';

                return $ticket;
            }

            #[CommandHandler('ticket.rename')]
            public function rename(#[Header('title')] string $title): void
            {
                $this->title = $title;
            }

            #[CommandHandler('ticket.renameWhileAnotherRenameLands')]
            public function renameWhileAnotherRenameLands(#[Header('title')] string $title, CommandBus $commandBus): void
            {
                $commandBus->sendWithRouting('ticket.rename', metadata: ['aggregate.id' => $this->ticket_id, 'title' => 'Landed first']);
                $this->title = $title;
            }

            #[QueryHandler('ticket.version')]
            public function version(): int
            {
                return $this->getVersion();
            }

            #[IdentifierMethod('ticketId')]
            public function ticketId(): string
            {
                return $this->getKey();
            }
        };
        $ecotone = $this->bootstrapWith($ticket::class);
        $ecotone->sendCommandWithRouting('ticket.open', metadata: ['ticketId' => 't-1']);

        try {
            $ecotone->sendCommandWithRouting('ticket.renameWhileAnotherRenameLands', metadata: ['aggregate.id' => 't-1', 'title' => 'Overwritten']);
            $this->fail('Expected the second save of the ticket to be refused');
        } catch (ConcurrencyException $exception) {
            $this->assertStringContainsString('Aggregate ' . $ticket::class . ' t-1 was loaded at version 1, but it is at version 2 now', $exception->getMessage());
        }

        $this->assertSame(2, $ecotone->sendQueryWithRouting('ticket.version', metadata: ['aggregate.id' => 't-1']));
    }

    public function test_a_versioned_eloquent_aggregate_fires_its_model_events_on_every_save(): void
    {
        $ticket = new #[Aggregate] class () extends Model {
            public static array $events = [];

            protected $table = 'tickets';
            protected $primaryKey = 'ticket_id';
            protected $keyType = 'string';
            public $incrementing = false;
            public $timestamps = false;

            #[Version]
            private int $version;

            protected static function booted(): void
            {
                static::creating(fn (self $ticket) => static::$events[] = 'creating ' . $ticket->title);
                static::updating(fn (self $ticket) => static::$events[] = 'updating ' . $ticket->title);
                static::saved(fn (self $ticket) => static::$events[] = 'saved ' . $ticket->title);
            }

            #[CommandHandler('ticket.open')]
            public static function open(#[Header('ticketId')] string $ticketId): self
            {
                $ticket = new self();
                $ticket->ticket_id = $ticketId;
                $ticket->title = 'Untitled';

                return $ticket;
            }

            #[CommandHandler('ticket.rename')]
            public function rename(#[Header('title')] string $title): void
            {
                $this->title = $title;
            }

            #[IdentifierMethod('ticketId')]
            public function ticketId(): string
            {
                return $this->getKey();
            }
        };
        $ecotone = $this->bootstrapWith($ticket::class);

        $ecotone->sendCommandWithRouting('ticket.open', metadata: ['ticketId' => 't-1']);
        $ecotone->sendCommandWithRouting('ticket.rename', metadata: ['aggregate.id' => 't-1', 'title' => 'Renamed']);

        $this->assertSame(['creating Untitled', 'saved Untitled', 'updating Renamed', 'saved Renamed'], $ticket::$events);
    }

    public function test_an_eloquent_aggregate_without_a_version_saves_the_last_write_as_before(): void
    {
        $note = new #[Aggregate] class () extends Model {
            protected $table = 'notes';
            protected $primaryKey = 'note_id';
            protected $keyType = 'string';
            public $incrementing = false;
            public $timestamps = false;

            #[CommandHandler('note.open')]
            public static function open(#[Header('noteId')] string $noteId): self
            {
                $note = new self();
                $note->note_id = $noteId;
                $note->text = 'Empty';

                return $note;
            }

            #[CommandHandler('note.write')]
            public function write(#[Header('text')] string $text): void
            {
                $this->text = $text;
            }

            #[CommandHandler('note.writeWhileAnotherWriteLands')]
            public function writeWhileAnotherWriteLands(#[Header('text')] string $text, CommandBus $commandBus): void
            {
                $commandBus->sendWithRouting('note.write', metadata: ['aggregate.id' => $this->note_id, 'text' => 'Landed first']);
                $this->text = $text;
            }

            #[QueryHandler('note.text')]
            public function text(): string
            {
                return $this->text;
            }

            #[IdentifierMethod('noteId')]
            public function noteId(): string
            {
                return $this->getKey();
            }
        };
        $ecotone = $this->bootstrapWith($note::class);
        $ecotone->sendCommandWithRouting('note.open', metadata: ['noteId' => 'n-1']);

        $ecotone->sendCommandWithRouting('note.writeWhileAnotherWriteLands', metadata: ['aggregate.id' => 'n-1', 'text' => 'Written last']);

        $this->assertSame('Written last', $ecotone->sendQueryWithRouting('note.text', metadata: ['aggregate.id' => 'n-1']));
    }

    public function test_an_application_repository_for_an_eloquent_model_is_chosen_over_eloquent_and_given_the_version_it_loaded(): void
    {
        $ticket = new #[Aggregate] class () extends Model {
            #[Identifier]
            public string $ticketId = 'ticket-1';

            #[Version]
            public int $version = 0;

            #[CommandHandler('ticket.close')]
            public function close(): void
            {
            }
        };
        $repository = new #[Repository] class ($ticket::class) implements StateStoredRepository {
            public array $saved = [];

            public function __construct(private string $ticketClass)
            {
            }

            public function canHandle(string $aggregateClassName): bool
            {
                return $aggregateClassName === $this->ticketClass;
            }

            public function findBy(string $aggregateClassName, array $identifiers): ?object
            {
                $ticket = new $this->ticketClass();
                $ticket->version = 7;

                return $ticket;
            }

            public function save(array $identifiers, object $aggregate, array $metadata, ?int $versionBeforeHandling): void
            {
                $this->saved[] = ['versionBeforeHandling' => $versionBeforeHandling, 'version' => $aggregate->version];
            }
        };

        EcotoneLite::bootstrapFlowTesting(
            [$ticket::class, $repository::class],
            [$repository::class => $repository],
            extensionObjects: [new EloquentRepositoryBuilder()],
            enabledModules: [],
        )->sendCommandWithRouting('ticket.close', metadata: ['aggregate.id' => 'ticket-1']);

        $this->assertSame([['versionBeforeHandling' => 7, 'version' => 8]], $repository->saved);
    }

    private function bootstrapWith(string $aggregateClass): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTesting(
            [$aggregateClass],
            extensionObjects: [new EloquentRepositoryBuilder()],
            enabledModules: [],
            addInMemoryStateStoredRepository: false,
        );
    }

    private function versionedTicket(): Model
    {
        return new #[Aggregate] class () extends Model {
            protected $table = 'tickets';
            protected $primaryKey = 'ticket_id';
            protected $keyType = 'string';
            public $incrementing = false;
            public $timestamps = false;

            #[Version]
            private int $version;

            #[CommandHandler('ticket.open')]
            public static function open(#[Header('ticketId')] string $ticketId): self
            {
                $ticket = new self();
                $ticket->ticket_id = $ticketId;
                $ticket->title = 'Untitled';

                return $ticket;
            }

            #[CommandHandler(routingKey: 'ticket.openTitled', outputChannelName: 'ticket.retitle', identifierMetadataMapping: ['ticketId' => 'ticketId'])]
            public static function openTitled(#[Header('ticketId')] string $ticketId): self
            {
                return self::open($ticketId);
            }

            #[CommandHandler('ticket.retitle')]
            public function retitle(#[Header('title')] string $title): void
            {
                $this->title = $title;
            }

            #[CommandHandler('ticket.rename')]
            public function rename(#[Header('title')] string $title): void
            {
                $this->title = $title;
            }

            #[CommandHandler('ticket.renameWhileAnotherRenameLands')]
            public function renameWhileAnotherRenameLands(#[Header('title')] string $title, CommandBus $commandBus): void
            {
                $commandBus->sendWithRouting('ticket.rename', metadata: ['aggregate.id' => $this->ticket_id, 'title' => 'Landed first']);
                $this->title = $title;
            }

            #[QueryHandler('ticket.title')]
            public function title(): string
            {
                return $this->title;
            }

            #[QueryHandler('ticket.version')]
            public function version(): int
            {
                return $this->version;
            }

            #[IdentifierMethod('ticketId')]
            public function ticketId(): string
            {
                return $this->getKey();
            }
        };
    }
}
