<?php

declare(strict_types=1);

namespace Ecotone\Api\Messaging\Flow;

/**
 * licence Apache-2.0
 */
enum FlowNodeKind: string
{
    case COMMAND = 'command';
    case EVENT = 'event';
    case QUERY = 'query';
    case COMMAND_HANDLER = 'commandHandler';
    case EVENT_HANDLER = 'eventHandler';
    case QUERY_HANDLER = 'queryHandler';
    case INTERNAL_HANDLER = 'internalHandler';
    case ROUTER = 'router';
    case AGGREGATE = 'aggregate';
    case SAGA = 'saga';
    case PROJECTION = 'projection';
    case GATEWAY = 'gateway';
    case CHANNEL = 'channel';
    case SCHEDULED = 'scheduled';

    public function isMessage(): bool
    {
        return in_array($this, [self::COMMAND, self::EVENT, self::QUERY], true);
    }

    public function isHandler(): bool
    {
        return in_array($this, [self::COMMAND_HANDLER, self::EVENT_HANDLER, self::QUERY_HANDLER, self::INTERNAL_HANDLER, self::ROUTER], true);
    }

    public function isOwner(): bool
    {
        return in_array($this, [self::AGGREGATE, self::SAGA, self::PROJECTION], true);
    }
}
