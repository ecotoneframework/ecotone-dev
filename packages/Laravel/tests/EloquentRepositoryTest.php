<?php

namespace Test\Ecotone\Laravel;

use DateTime;
use Ecotone\Api\Attribute\Aggregate;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Identifier;
use Ecotone\Api\Attribute\Repository;
use Ecotone\Api\Attribute\Version;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Laravel\EloquentRepository;
use Ecotone\Laravel\EloquentRepositoryBuilder;
use Ecotone\Modelling\StateStoredRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
use Orchestra\Testbench\TestCase;

/**
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
class EloquentRepositoryTest extends TestCase
{
    public function test_it_does_not_support_non_models()
    {
        $repository = new EloquentRepository();

        $this->assertFalse($repository->canHandle(DateTime::class));
    }

    public function test_it_does_support_models()
    {
        $repository = new EloquentRepository();

        $this->assertTrue($repository->canHandle(User::class));
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
            ServiceConfiguration::createWithDefaults()
                ->withModulePackages([])
                ->withExtensionObjects([new EloquentRepositoryBuilder()]),
        )->sendCommandWithRouting('ticket.close', metadata: ['aggregate.id' => 'ticket-1']);

        $this->assertSame([['versionBeforeHandling' => 7, 'version' => 8]], $repository->saved);
    }
}
