<?php

declare(strict_types=1);

namespace App\Graph;

final class DistributedLinks
{
    /**
     * @param list<ServiceFlow> $flows
     * @return list<DistributedLink>
     */
    public static function between(array $flows): array
    {
        $links = [];
        foreach ($flows as $sender) {
            foreach ($sender->sentCommands() as $sent) {
                foreach ($flows as $receiver) {
                    foreach ($receiver->distributedReceivers() as $received) {
                        if ($receiver->name === $sent['targetService'] && $received['routingKey'] === $sent['routingKey']) {
                            $links[] = new DistributedLink($sender->name, $sent['node']->id, $receiver->name, $received['node']->id, 'command', $sent['routingKey']);
                        }
                    }
                }
            }
            foreach ($sender->sentMessages() as $sent) {
                foreach ($flows as $receiver) {
                    if ($receiver->name !== $sent['targetService']) {
                        continue;
                    }
                    $replyTarget = $sender->replyTargetOf($sent['handler'], $sent['replyChannel']);
                    foreach ($receiver->distributedChannelHandlers() as $received) {
                        if ($received['channel'] !== $sent['targetChannel']) {
                            continue;
                        }
                        $links[] = new DistributedLink($sender->name, $sent['handler']->id, $receiver->name, $received['handler']->id, 'message', $sent['targetChannel']);
                        if ($received['replies']) {
                            $links[] = new DistributedLink($receiver->name, $received['handler']->id, $sender->name, $replyTarget, 'reply', $sent['targetChannel']);
                        }
                    }
                    $commandReceived = false;
                    foreach ($receiver->distributedCommandHandlers() as $received) {
                        if ($received['routingKey'] !== $sent['targetChannel']) {
                            continue;
                        }
                        if (! $commandReceived) {
                            $links[] = new DistributedLink($sender->name, $sent['handler']->id, $receiver->name, $received['command']->id, 'message', $sent['targetChannel']);
                            $commandReceived = true;
                        }
                        if ($received['replies']) {
                            $links[] = new DistributedLink($receiver->name, $received['handler']->id, $sender->name, $replyTarget, 'reply', $sent['targetChannel']);
                        }
                    }
                }
            }
            foreach ($sender->publishedEvents() as $published) {
                foreach ($flows as $receiver) {
                    if ($receiver === $sender || ! $sender->publishesToChannel($published['routingKey'], $receiver->channel)) {
                        continue;
                    }
                    foreach ($receiver->distributedReceivers() as $received) {
                        if (RoutingKeyPattern::matches($received['routingKey'], $published['routingKey'])) {
                            $links[] = new DistributedLink($sender->name, $published['node']->id, $receiver->name, $received['node']->id, 'event', $published['routingKey']);
                        }
                    }
                }
            }
        }

        return $links;
    }
}
