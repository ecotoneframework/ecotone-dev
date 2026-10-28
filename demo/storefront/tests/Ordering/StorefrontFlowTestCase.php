<?php

declare(strict_types=1);

namespace Tests\Ordering;

use App\Customer\Customer;
use App\Customer\CustomerDirectory;
use App\Customer\RegisterCustomer;
use App\Infrastructure\EcotoneConfiguration;
use App\Ordering\Order;
use App\Ordering\OrderQueries;
use App\Ordering\OrderRepository;
use App\Ordering\RequestSeat;
use App\Ordering\StartOrder;
use App\Ordering\TicketingReplies;
use App\Ordering\TicketingRequests;
use App\Payment\PaymentAttempts;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Distributed;
use Ecotone\Api\Attribute\Header;
use Ecotone\Api\Attribute\Repository;
use Ecotone\Api\Dbal\Gateway\DeadLetterGateway;
use Ecotone\Api\ExtensionObject\DistributedServiceMap;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Laravel\LaravelConnectionReference;
use Ecotone\Api\Laravel\LaravelQueueMessageChannelBuilder;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Modelling\StateStoredRepository;
use Ecotone\Test\LicenceTesting;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

abstract class StorefrontFlowTestCase extends TestCase
{
    protected const CONCERT_ID = '0199f3a8-6f2a-7c1e-9a51-3d2f6c1b8e40';
    protected const EXPIRES_AT = '2026-10-25T12:02:00+00:00';

    private const STOREFRONT_SERVICES = [EcotoneConfiguration::class, Order::class, OrderRepository::class, Customer::class, OrderQueries::class, CustomerDirectory::class, TicketingRequests::class, TicketingReplies::class];

    private array $channels;

    protected FlowTestSupport $storefront;

    protected FlowTestSupport $ticketing;

    protected object $ticketingService;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('orders')->delete();
        DB::table('customers')->delete();
        DB::table('concerts')->delete();
        DB::table('jobs')->delete();
        $this->channels = [
            SimpleMessageChannelBuilder::createQueueChannel('storefront'),
            SimpleMessageChannelBuilder::createQueueChannel('ticketing'),
            SimpleMessageChannelBuilder::createQueueChannel('ecotone_pulse'),
        ];
        $this->storefront = $this->storefront();
        $this->storefront->getGateway(DeadLetterGateway::class)->deleteAll();
        $this->ticketing = $this->ticketing();
    }

    protected function storefrontServices(): array
    {
        return [];
    }

    protected function startOrderOf(string $name, string $email): string
    {
        $customerId = $this->storefront->getCommandBus()->send(new RegisterCustomer(name: $name, email: $email));

        return $this->storefront->getCommandBus()->send(new StartOrder(customerId: $customerId, concertId: self::CONCERT_ID));
    }

    protected function seatIsHeld(string $orderId, string $seatId): void
    {
        $this->storefront->getCommandBus()->send(new RequestSeat(orderId: $orderId, seatId: $seatId, holdId: 'hold-1'));
        $this->ticketingReplies();
    }

    protected function order(string $orderId): array
    {
        return $this->storefront->sendQueryWithRouting('order.detail', $orderId);
    }

    protected function ticketingReceives(): array
    {
        $this->storefrontAsyncRuns();
        $this->ticketing->run('ticketing', ExecutionPollingMetadata::createWithTestingSetup());
        $received = $this->ticketingService->received;
        $this->ticketingService->received = [];

        return $received;
    }

    protected function storefrontAsyncRuns(): void
    {
        $this->storefront->run('storefront_async', ExecutionPollingMetadata::createWithTestingSetup());
    }

    protected function storefrontConsumes(): void
    {
        $this->storefront->run('storefront', ExecutionPollingMetadata::createWithTestingSetup());
    }

    protected function ticketingReplies(): void
    {
        $this->ticketingReceives();
        $this->storefrontConsumes();
    }

    private function storefront(): FlowTestSupport
    {
        $repository = new #[Repository] class () implements StateStoredRepository {
            public function canHandle(string $aggregateClassName): bool
            {
                return $aggregateClassName === Customer::class;
            }

            public function findBy(string $aggregateClassName, array $identifiers): ?object
            {
                return $aggregateClassName::query()->find(array_pop($identifiers));
            }

            public function save(array $identifiers, object $aggregate, array $metadata, ?int $versionBeforeHandling): void
            {
                $aggregate->save();
            }
        };
        $this->app->instance($repository::class, $repository);
        $services = array_values(array_unique([...self::STOREFRONT_SERVICES, ...$this->storefrontServices()]));
        foreach ($services as $service) {
            if (interface_exists($service)) {
                $this->app->bind($service, fn () => $this->storefront->getGateway($service));
            } else {
                $this->app->singleton($service);
            }
        }

        return EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [...$services, $repository::class],
            containerOrAvailableServices: $this->app,
            extensionObjects: [
                ...$this->channels,
                LaravelQueueMessageChannelBuilder::create('storefront_async', 'database'),
                LaravelConnectionReference::defaultConnection('pgsql'),
                LaravelConnectionReference::create('payment_provider', PaymentAttempts::CONNECTION),
            ],
            pathToRootCatalog: dirname(__DIR__, 2),
            addInMemoryStateStoredRepository: false,
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    private function ticketing(): FlowTestSupport
    {
        $this->ticketingService = new class () {
            public array $received = [];

            public array $holdReplies = [];

            public array $confirmReplies = [];

            public int $holdsToRefuse = 0;

            #[Distributed]
            #[CommandHandler('tickets.hold')]
            public function hold(array $command, #[Header('concertId')] string $concertId, #[Header('orderId')] string $orderId): array
            {
                $this->received[] = ['tickets.hold', $command, $concertId, $orderId];
                if ($this->holdsToRefuse > 0) {
                    $this->holdsToRefuse--;

                    return [...$command, 'outcome' => 'refused', 'reason' => 'someone was faster: seat ' . substr($command['seatId'], strrpos($command['seatId'], '/') + 1), 'expiresAt' => null];
                }

                return array_shift($this->holdReplies) ?? [...$command, 'outcome' => 'held', 'reason' => null, 'expiresAt' => '2026-10-25T12:02:00+00:00'];
            }

            #[Distributed]
            #[CommandHandler('tickets.release')]
            public function release(array $command, #[Header('concertId')] string $concertId, #[Header('orderId')] string $orderId): void
            {
                $this->received[] = ['tickets.release', $command, $concertId, $orderId];
            }

            #[Distributed]
            #[CommandHandler('tickets.confirm')]
            public function confirm(array $command, #[Header('concertId')] string $concertId, #[Header('orderId')] string $orderId): array
            {
                $this->received[] = ['tickets.confirm', $command, $concertId, $orderId];

                return array_shift($this->confirmReplies) ?? [...$command, 'outcome' => 'sold', 'reason' => null];
            }
        };

        return EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$this->ticketingService::class],
            containerOrAvailableServices: [$this->ticketingService],
            extensionObjects: [
                ...$this->channels,
                ServiceConfiguration::createWithDefaults()->withServiceName('ticketing'),
                DistributedServiceMap::initialize()
                    ->withCommandMapping(targetServiceName: 'storefront', channelName: 'storefront')
                    ->withEventMapping(channelName: 'storefront', subscriptionKeys: ['tickets.*', 'concert.*']),
            ],
            enabledModules: [ModulePackageList::JMS_CONVERTER_PACKAGE],
            pathToRootCatalog: dirname(__DIR__, 2),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }
}
