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
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Laravel\LaravelConnectionReference;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Messaging\ConcurrencyException;
use Ecotone\Api\Modelling\StateStoredRepository;
use Ecotone\Api\Modelling\WithAggregateVersioning;
use Ecotone\Laravel\EloquentRepositoryBuilder;
use Ecotone\Laravel\EloquentSaveCancelled;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use RuntimeException;

/**
 * licence Apache-2.0
 * @internal
 */
class EloquentRepositoryTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $dsn = (string) getenv('DATABASE_DSN');
        $app['config']->set('database.default', 'eloquent_repository');
        $app['config']->set('database.connections.eloquent_repository', match (true) {
            str_starts_with($dsn, 'pgsql') => ['driver' => 'pgsql', 'url' => $dsn, 'prefix' => '', 'search_path' => 'public'],
            str_starts_with($dsn, 'mysql') => ['driver' => 'mysql', 'url' => $dsn, 'prefix' => ''],
            default => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('eloquent_repository_tickets');
        Schema::create('eloquent_repository_tickets', function (Blueprint $table) {
            $table->string('ticket_id')->primary();
            $table->string('title');
            $table->string('slug')->nullable()->unique();
            $table->integer('version');
        });
        Schema::dropIfExists('eloquent_repository_notes');
        Schema::create('eloquent_repository_notes', function (Blueprint $table) {
            $table->string('note_id')->primary();
            $table->string('text');
        });
    }

    public function test_a_versioned_eloquent_aggregate_loaded_twice_refuses_the_second_save_naming_the_way_out(): void
    {
        $ecotone = $this->bootstrapWith(EloquentRepositoryTicket::class);
        $ecotone->sendCommandWithRouting('ticket.open', metadata: ['ticketId' => 't-1']);

        try {
            $ecotone->sendCommandWithRouting('ticket.renameWhileAnotherRenameLands', metadata: ['aggregate.id' => 't-1', 'title' => 'Overwritten']);
            $this->fail('Expected the second save of the ticket to be refused');
        } catch (ConcurrencyException $exception) {
            $this->assertStringContainsString('Aggregate ' . EloquentRepositoryTicket::class . ' t-1 was loaded at version 1, but it is at version 2 now', $exception->getMessage());
            $this->assertStringContainsString('Load it again and retry the command', $exception->getMessage());
        }

        $this->assertSame('Landed first', $ecotone->sendQueryWithRouting('ticket.title', metadata: ['aggregate.id' => 't-1']));
        $this->assertSame(2, $ecotone->sendQueryWithRouting('ticket.version', metadata: ['aggregate.id' => 't-1']));
    }

    public function test_sequential_commands_on_a_versioned_eloquent_aggregate_each_save_and_move_its_version(): void
    {
        $ecotone = $this->bootstrapWith(EloquentRepositoryTicket::class);

        $ecotone->sendCommandWithRouting('ticket.open', metadata: ['ticketId' => 't-1']);
        $this->assertSame(1, $ecotone->sendQueryWithRouting('ticket.version', metadata: ['aggregate.id' => 't-1']));

        $ecotone->sendCommandWithRouting('ticket.rename', metadata: ['aggregate.id' => 't-1', 'title' => 'First']);
        $this->assertSame(2, $ecotone->sendQueryWithRouting('ticket.version', metadata: ['aggregate.id' => 't-1']));

        $ecotone->sendCommandWithRouting('ticket.rename', metadata: ['aggregate.id' => 't-1', 'title' => 'Second']);
        $this->assertSame(3, $ecotone->sendQueryWithRouting('ticket.version', metadata: ['aggregate.id' => 't-1']));
        $this->assertSame('Second', $ecotone->sendQueryWithRouting('ticket.title', metadata: ['aggregate.id' => 't-1']));
    }

    public function test_a_versioned_eloquent_aggregate_saves_inside_the_command_bus_transaction_on_the_laravel_connection(): void
    {
        $ecotone = $this->bootstrapWithCommandBusTransactionOnTheLaravelConnection(EloquentRepositoryTicket::class);

        $ecotone->sendCommandWithRouting('ticket.open', metadata: ['ticketId' => 't-1']);
        $ecotone->sendCommandWithRouting('ticket.rename', metadata: ['aggregate.id' => 't-1', 'title' => 'Renamed']);

        $this->assertSame('Renamed', $ecotone->sendQueryWithRouting('ticket.title', metadata: ['aggregate.id' => 't-1']));
        $this->assertSame(2, $ecotone->sendQueryWithRouting('ticket.version', metadata: ['aggregate.id' => 't-1']));
    }

    public function test_creating_a_versioned_eloquent_aggregate_under_a_stored_id_inside_the_command_bus_transaction_is_refused(): void
    {
        $ecotone = $this->bootstrapWithCommandBusTransactionOnTheLaravelConnection(EloquentRepositoryTicket::class);
        $ecotone->sendCommandWithRouting('ticket.open', metadata: ['ticketId' => 't-1']);
        $ecotone->sendCommandWithRouting('ticket.rename', metadata: ['aggregate.id' => 't-1', 'title' => 'Kept']);

        try {
            $ecotone->sendCommandWithRouting('ticket.open', metadata: ['ticketId' => 't-1']);
            $this->fail('Expected the stored ticket to be kept');
        } catch (ConcurrencyException $exception) {
            $this->assertStringContainsString('Aggregate ' . EloquentRepositoryTicket::class . ' t-1 was loaded at version 0, but it is at version 2 now', $exception->getMessage());
        }

        $this->assertSame('Kept', $ecotone->sendQueryWithRouting('ticket.title', metadata: ['aggregate.id' => 't-1']));
    }

    public function test_a_handler_chained_after_creating_a_versioned_eloquent_aggregate_saves_it_again(): void
    {
        $ecotone = $this->bootstrapWith(EloquentRepositoryTicket::class);

        $ecotone->sendCommandWithRouting('ticket.openTitled', metadata: ['ticketId' => 't-1', 'title' => 'Titled']);

        $this->assertSame('Titled', $ecotone->sendQueryWithRouting('ticket.title', metadata: ['aggregate.id' => 't-1']));
        $this->assertSame(2, $ecotone->sendQueryWithRouting('ticket.version', metadata: ['aggregate.id' => 't-1']));
    }

    public function test_creating_a_versioned_eloquent_aggregate_under_an_id_that_is_already_stored_is_refused(): void
    {
        $ecotone = $this->bootstrapWith(EloquentRepositoryTicket::class);
        $ecotone->sendCommandWithRouting('ticket.open', metadata: ['ticketId' => 't-1']);
        $ecotone->sendCommandWithRouting('ticket.rename', metadata: ['aggregate.id' => 't-1', 'title' => 'Kept']);

        try {
            $ecotone->sendCommandWithRouting('ticket.open', metadata: ['ticketId' => 't-1']);
            $this->fail('Expected the stored ticket to be kept');
        } catch (ConcurrencyException $exception) {
            $this->assertStringContainsString('Aggregate ' . EloquentRepositoryTicket::class . ' t-1 was loaded at version 0, but it is at version 2 now', $exception->getMessage());
        }

        $this->assertSame('Kept', $ecotone->sendQueryWithRouting('ticket.title', metadata: ['aggregate.id' => 't-1']));
    }

    public function test_a_loaded_versioned_eloquent_aggregate_saved_through_a_repository_interface_is_stored_and_moves_its_version(): void
    {
        $ecotone = $this->bootstrapWith(EloquentRepositoryTicket::class, EloquentRepositoryTicketRepository::class);
        $ecotone->sendCommandWithRouting('ticket.open', metadata: ['ticketId' => 't-1']);
        $ecotone->sendCommandWithRouting('ticket.rename', metadata: ['aggregate.id' => 't-1', 'title' => 'Renamed']);
        $repository = $ecotone->getGateway(EloquentRepositoryTicketRepository::class);

        $ticket = $repository->get('t-1');
        $ticket->title = 'Through the repository';
        $repository->save($ticket);

        $this->assertSame('Through the repository', $ecotone->sendQueryWithRouting('ticket.title', metadata: ['aggregate.id' => 't-1']));
        $this->assertSame(3, $ecotone->sendQueryWithRouting('ticket.version', metadata: ['aggregate.id' => 't-1']));
    }

    public function test_a_versioned_eloquent_aggregate_loaded_twice_through_a_repository_interface_refuses_the_second_save(): void
    {
        $ecotone = $this->bootstrapWith(EloquentRepositoryTicket::class, EloquentRepositoryTicketRepository::class);
        $ecotone->sendCommandWithRouting('ticket.open', metadata: ['ticketId' => 't-1']);
        $repository = $ecotone->getGateway(EloquentRepositoryTicketRepository::class);
        $first = $repository->get('t-1');
        $second = $repository->get('t-1');
        $first->title = 'Saved first';
        $repository->save($first);
        $second->title = 'Saved second';

        try {
            $repository->save($second);
            $this->fail('Expected the second save of the ticket to be refused');
        } catch (ConcurrencyException $exception) {
            $this->assertStringContainsString('Aggregate ' . EloquentRepositoryTicket::class . ' t-1 was loaded at version 1, but it is at version 2 now', $exception->getMessage());
        }

        $this->assertSame('Saved first', $ecotone->sendQueryWithRouting('ticket.title', metadata: ['aggregate.id' => 't-1']));
        $this->assertSame(2, $ecotone->sendQueryWithRouting('ticket.version', metadata: ['aggregate.id' => 't-1']));
    }

    public function test_a_new_versioned_eloquent_aggregate_saved_through_a_repository_interface_is_stored_at_version_one(): void
    {
        $ecotone = $this->bootstrapWith(EloquentRepositoryTicket::class, EloquentRepositoryTicketRepository::class);

        $ecotone->getGateway(EloquentRepositoryTicketRepository::class)->save(EloquentRepositoryTicket::open('t-1'));

        $this->assertSame('Untitled', $ecotone->sendQueryWithRouting('ticket.title', metadata: ['aggregate.id' => 't-1']));
        $this->assertSame(1, $ecotone->sendQueryWithRouting('ticket.version', metadata: ['aggregate.id' => 't-1']));
    }

    public function test_creating_a_versioned_eloquent_aggregate_under_a_stored_id_inside_an_application_transaction_is_refused(): void
    {
        $ecotone = $this->bootstrapWith(EloquentRepositoryTicket::class);
        $ecotone->sendCommandWithRouting('ticket.open', metadata: ['ticketId' => 't-1']);

        try {
            DB::transaction(fn () => $ecotone->sendCommandWithRouting('ticket.open', metadata: ['ticketId' => 't-1']));
            $this->fail('Expected the stored ticket to be kept');
        } catch (ConcurrencyException $exception) {
            $this->assertStringContainsString('Aggregate ' . EloquentRepositoryTicket::class . ' t-1 was loaded at version 0, but it is at version 1 now', $exception->getMessage());
        }

        $this->assertSame(1, $ecotone->sendQueryWithRouting('ticket.version', metadata: ['aggregate.id' => 't-1']));
    }

    public function test_creating_a_versioned_eloquent_aggregate_that_collides_on_another_unique_column_raises_the_database_error(): void
    {
        $ecotone = $this->bootstrapWith(EloquentRepositoryTicket::class);
        $ecotone->sendCommandWithRouting('ticket.openWithSlug', metadata: ['ticketId' => 't-1', 'slug' => 'same']);

        $this->expectException(UniqueConstraintViolationException::class);

        $ecotone->sendCommandWithRouting('ticket.openWithSlug', metadata: ['ticketId' => 't-9', 'slug' => 'same']);
    }

    public function test_updating_a_versioned_eloquent_aggregate_that_collides_on_another_unique_column_raises_the_database_error(): void
    {
        $ecotone = $this->bootstrapWith(EloquentRepositoryTicket::class);
        $ecotone->sendCommandWithRouting('ticket.openWithSlug', metadata: ['ticketId' => 't-1', 'slug' => 'taken']);
        $ecotone->sendCommandWithRouting('ticket.open', metadata: ['ticketId' => 't-2']);

        try {
            $ecotone->sendCommandWithRouting('ticket.changeSlug', metadata: ['aggregate.id' => 't-2', 'slug' => 'taken']);
            $this->fail('Expected the taken slug to be refused by the database');
        } catch (UniqueConstraintViolationException) {
        }

        $this->assertSame(1, $ecotone->sendQueryWithRouting('ticket.version', metadata: ['aggregate.id' => 't-2']));
    }

    public function test_a_versioned_eloquent_aggregate_whose_handler_changes_its_key_is_saved_under_the_new_key(): void
    {
        $ecotone = $this->bootstrapWith(EloquentRepositoryTicket::class);
        $ecotone->sendCommandWithRouting('ticket.open', metadata: ['ticketId' => 't-1']);

        $ecotone->sendCommandWithRouting('ticket.changeId', metadata: ['aggregate.id' => 't-1', 'newTicketId' => 't-2']);

        $this->assertSame(2, $ecotone->sendQueryWithRouting('ticket.version', metadata: ['aggregate.id' => 't-2']));
    }

    public function test_an_eloquent_aggregate_versioned_through_the_trait_loaded_twice_refuses_the_second_save(): void
    {
        $ticket = new #[Aggregate] class () extends Model {
            use WithAggregateVersioning;

            protected $table = 'eloquent_repository_tickets';
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

            protected $table = 'eloquent_repository_tickets';
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

    public function test_a_save_cancelled_by_a_model_listener_is_refused_and_leaves_the_version_where_it_was(): void
    {
        $ticket = $this->ticketWithAnUpdatingListener();
        $ecotone = $this->bootstrapWith($ticket::class);
        $ecotone->sendCommandWithRouting('ticket.open', metadata: ['ticketId' => 't-1']);

        try {
            $ecotone->sendCommandWithRouting('ticket.rename', metadata: ['aggregate.id' => 't-1', 'title' => 'Cancelled']);
            $this->fail('Expected the cancelled save to be refused');
        } catch (EloquentSaveCancelled $exception) {
            $this->assertStringContainsString('Eloquent model ' . $ticket::class . ' t-1 was not saved: one of its saving, creating or updating listeners returned false', $exception->getMessage());
            $this->assertStringContainsString('let the listener throw an exception that names why', $exception->getMessage());
        }

        $this->assertSame('Untitled', $ecotone->sendQueryWithRouting('ticket.title', metadata: ['aggregate.id' => 't-1']));
        $this->assertSame(1, $ecotone->sendQueryWithRouting('ticket.version', metadata: ['aggregate.id' => 't-1']));
    }

    public function test_a_save_failing_in_a_model_listener_leaves_the_version_where_it_was(): void
    {
        $ticket = $this->ticketWithAnUpdatingListener();
        $ecotone = $this->bootstrapWith($ticket::class);
        $ecotone->sendCommandWithRouting('ticket.open', metadata: ['ticketId' => 't-1']);

        try {
            $ecotone->sendCommandWithRouting('ticket.rename', metadata: ['aggregate.id' => 't-1', 'title' => 'Broken']);
            $this->fail('Expected the failing save to be raised');
        } catch (RuntimeException $exception) {
            $this->assertSame('The ticket cannot be broken', $exception->getMessage());
        }

        $this->assertSame('Untitled', $ecotone->sendQueryWithRouting('ticket.title', metadata: ['aggregate.id' => 't-1']));
        $this->assertSame(1, $ecotone->sendQueryWithRouting('ticket.version', metadata: ['aggregate.id' => 't-1']));
    }

    public function test_an_eloquent_aggregate_without_a_version_saves_the_last_write_as_before(): void
    {
        $note = new #[Aggregate] class () extends Model {
            protected $table = 'eloquent_repository_notes';
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

    private function ticketWithAnUpdatingListener(): Model
    {
        return new #[Aggregate] class () extends Model {
            protected $table = 'eloquent_repository_tickets';
            protected $primaryKey = 'ticket_id';
            protected $keyType = 'string';
            public $incrementing = false;
            public $timestamps = false;

            #[Version]
            private int $version;

            protected static function booted(): void
            {
                static::updating(function (self $ticket) {
                    if ($ticket->title === 'Broken') {
                        throw new RuntimeException('The ticket cannot be broken');
                    }

                    return $ticket->title !== 'Cancelled';
                });
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

    private function bootstrapWithCommandBusTransactionOnTheLaravelConnection(string ...$classes): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTesting(
            $classes,
            extensionObjects: [
                new EloquentRepositoryBuilder(),
                LaravelConnectionReference::defaultConnection('eloquent_repository'),
                DbalConfiguration::createWithDefaults()->withTransactionOnCommandBus(true),
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE, ModulePackageList::LARAVEL_PACKAGE],
            addInMemoryStateStoredRepository: false,
        );
    }

    private function bootstrapWith(string ...$classes): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTesting(
            $classes,
            extensionObjects: [new EloquentRepositoryBuilder()],
            enabledModules: [],
            addInMemoryStateStoredRepository: false,
        );
    }
}

#[Aggregate]
class EloquentRepositoryTicket extends Model
{
    protected $table = 'eloquent_repository_tickets';
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

    #[CommandHandler('ticket.openWithSlug')]
    public static function openWithSlug(#[Header('ticketId')] string $ticketId, #[Header('slug')] string $slug): self
    {
        $ticket = self::open($ticketId);
        $ticket->slug = $slug;

        return $ticket;
    }

    #[CommandHandler('ticket.changeId')]
    public function changeId(#[Header('newTicketId')] string $newTicketId): void
    {
        $this->ticket_id = $newTicketId;
    }

    #[CommandHandler('ticket.changeSlug')]
    public function changeSlug(#[Header('slug')] string $slug): void
    {
        $this->slug = $slug;
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
}

interface EloquentRepositoryTicketRepository
{
    #[Repository]
    public function get(string $ticketId): ?EloquentRepositoryTicket;

    #[Repository]
    public function save(EloquentRepositoryTicket $ticket): void;
}
