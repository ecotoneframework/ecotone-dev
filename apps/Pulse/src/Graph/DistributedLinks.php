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
                    if ($receiver->name !== $sent['targetService']) {
                        continue;
                    }
                    foreach ($receiver->distributedReceivers() as $received) {
                        if ($received['routingKey'] === $sent['routingKey']) {
                            $links[] = new DistributedLink($sender->pulseName, $sent['node']->id, $receiver->pulseName, $received['node']->id, 'command', $sent['routingKey']);
                        }
                    }
                }
            }
            foreach ($sender->publishedEvents() as $published) {
                if (! $sender->publishesEventTo($published['routingKey'])) {
                    continue;
                }
                foreach ($flows as $receiver) {
                    if ($receiver === $sender) {
                        continue;
                    }
                    foreach ($receiver->distributedReceivers() as $received) {
                        if ($received['routingKey'] === $published['routingKey']) {
                            $links[] = new DistributedLink($sender->pulseName, $published['node']->id, $receiver->pulseName, $received['node']->id, 'event', $published['routingKey']);
                        }
                    }
                }
            }
        }

        return $links;
    }
}
