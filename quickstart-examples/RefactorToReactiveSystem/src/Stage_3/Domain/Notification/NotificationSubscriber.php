<?php

declare(strict_types=1);

namespace App\ReactiveSystem\Stage_3\Domain\Notification;

use App\ReactiveSystem\Stage_3\Domain\Order\Event\OrderWasPlaced;
use App\ReactiveSystem\Stage_3\Domain\Order\OrderRepository;
use App\ReactiveSystem\Stage_3\Domain\User\UserRepository;
use App\ReactiveSystem\Stage_3\Infrastructure\Messaging\MessageChannelConfiguration;
use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Modelling\Attribute\EventHandler;

final class NotificationSubscriber
{
    public function __construct(private readonly OrderRepository $orderRepository,
        private readonly UserRepository $userRepository, private readonly NotificationSender $notificationSender)
    {}

    #[Asynchronous(MessageChannelConfiguration::ASYNCHRONOUS_CHANNEL)]
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