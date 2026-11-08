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
        $messageReceivers = [];
        foreach ($flows as $flow) {
            $messageReceivers[spl_object_id($flow)] = $flow->distributedMessageReceivers();
        }
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
                    $linkedTargets = [];
                    foreach ($messageReceivers[spl_object_id($receiver)] as $received) {
                        if ($received['routingKey'] !== $sent['targetChannel']) {
                            continue;
                        }
                        if (! isset($linkedTargets[$received['target']->id])) {
                            $links[] = new DistributedLink($sender->name, $sent['handler']->id, $receiver->name, $received['target']->id, 'message', $sent['targetChannel']);
                            $linkedTargets[$received['target']->id] = true;
                        }
                        if ($received['replies'] && ! $sent['withoutReply']) {
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
