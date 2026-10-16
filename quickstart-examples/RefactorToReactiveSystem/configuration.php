<?php

use App\ReactiveSystem\OrderController;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Messaging\ConfiguredMessagingSystem;
use Ecotone\Dbal\Connection\DbalConnectionFactory;
use Money\Money;
use Ramsey\Uuid\UuidInterface;

/**
 * @return array{ConfiguredMessagingSystem, OrderController}
 */
function getConfiguredMessagingSystem(string $stageToRun, UuidInterface $userId, UuidInterface $tableProductId): array
{
    $namespace = 'App\ReactiveSystem\\' . $stageToRun;
    $class = fn (string $relativeClassName): string => $namespace . '\\' . $relativeClassName;

    $notificationSender = new ($class('Infrastructure\StubNotificationSender'))();
    $shippingService = new ($class('Infrastructure\StubShippingService'))();
    $clock = new ($class('Infrastructure\SystemClock'))();
    $orderRepository = new ($class('Infrastructure\InMemory\InMemoryOrderRepository'))();
    $authenticationService = new ($class('Infrastructure\Authentication\AuthenticationService'))($userId);
    $userRepository = new ($class('Infrastructure\InMemory\InMemoryUserRepository'))([new ($class('Domain\User\User'))($userId, 'John Travolta')]);
    $productRepository = new ($class('Infrastructure\InMemory\InMemoryProductRepository'))([
        new ($class('Domain\Product\Product'))($tableProductId, new ($class('Domain\Product\ProductDetails'))('Table', Money::EUR('100.00'))),
    ]);

    $services = [
        $class('Domain\Notification\NotificationSender') => $notificationSender,
        $class('Domain\Shipping\ShippingService') => $shippingService,
        $class('Domain\Clock') => $clock,
        $class('Domain\User\UserRepository') => $userRepository,
        $class('Domain\Product\ProductRepository') => $productRepository,
        DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN') ? getenv('DATABASE_DSN') : 'pgsql://ecotone:secret@localhost:5432/ecotone'),
    ];
    if ($stageToRun === 'Stage_3') {
        $services[] = $orderRepository;
        $services[] = new ($class('Domain\Notification\NotificationSubscriber'))($userRepository, $notificationSender);
        $services[] = new ($class('Domain\Shipping\ShippingSubscriber'))($shippingService);
    } else {
        $services[$class('Domain\Order\OrderRepository')] = $orderRepository;
    }
    if ($stageToRun === 'Stage_2') {
        $services[] = new ($class('Domain\Notification\NotificationSubscriber'))($orderRepository, $userRepository, $notificationSender);
        $services[] = new ($class('Domain\Shipping\ShippingSubscriber'))($orderRepository, $shippingService);
        $services[] = new ($class('Application\OrderService'))($orderRepository, $productRepository, $clock);
    }
    if ($stageToRun !== 'Stage_1') {
        $services[] = new ($class('Infrastructure\Converter\UuidConverter'))();
        $services[] = new ($class('Infrastructure\Messaging\ErrorHandler'))();
    }

    $messagingSystem = EcotoneLite::bootstrap(
        containerOrAvailableServices: $services,
        pathToRootCatalog: __DIR__,
        namespaces: [$namespace],
    );
    $messagingSystem->runConsoleCommand('ecotone:migration:database:setup', ['initialize' => true]);

    $orderController = $stageToRun === 'Stage_1'
        ? new ($class('UI\OrderController'))(
            $authenticationService,
            new ($class('Application\OrderService'))($orderRepository, $userRepository, $productRepository, $notificationSender, $shippingService, $clock),
        )
        : new ($class('UI\OrderController'))($authenticationService, $messagingSystem->getCommandBus());

    return [$messagingSystem, $orderController];
}
