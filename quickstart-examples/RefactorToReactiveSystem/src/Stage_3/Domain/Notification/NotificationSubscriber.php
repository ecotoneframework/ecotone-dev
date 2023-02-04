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
    #[Asynchronous(MessageChannelConfiguration::ASYNCHRONOUS_CHANNEL)]
    #[EventHandler(endpointId: "notifyWhenOrderWasPlaced")]
    public function whenOrderWasPlaced(OrderWasPlaced $event, OrderRepository $orderRepository, UserRepository $userRepository, NotificationSender $notificationSender): void
    {
        /** Sending order confirmation notification */
        $order = $orderRepository->getBy($event->orderId);
        $user = $userRepository->getBy($order->getUserId());

        $notificationSender->send(new OrderConfirmationNotification($user->getFullName(), $order->getOrderId(), $order->getProductsDetails(), $order->getTotalPrice()));
    }
}