<?php

declare(strict_types=1);

namespace Monorepo\ExampleApp\Common\Domain\Notification;

use Monorepo\ExampleApp\Common\Domain\Order\Event\OrderWasPlaced;
use Monorepo\ExampleApp\Common\Domain\Order\OrderRepository;
use Monorepo\ExampleApp\Common\Domain\User\UserRepository;
use Monorepo\ExampleApp\Common\Infrastructure\Messaging\MessageChannelConfiguration;
use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Modelling\Attribute\EventHandler;

final class NotificationSubscriber
{
    public function __construct(private readonly OrderRepository $orderRepository,
        private readonly UserRepository $userRepository, private readonly NotificationSender $notificationSender)
    {}

    #[EventHandler(endpointId: "notifyWhenOrderWasPlaced")]
    public function whenOrderWasPlaced(OrderWasPlaced $event): void
    {
        $order = $this->orderRepository->getBy($event->orderId);
        $user = $this->userRepository->getBy($order->getUserId());

        $this->notificationSender->send(new OrderConfirmationNotification(
            $user->getFullName(), $order->getOrderId(), $order->getProductDetails(), $order->getTotalPrice())
        );
    }
}