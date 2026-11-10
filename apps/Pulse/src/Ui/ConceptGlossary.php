<?php

declare(strict_types=1);

namespace App\Ui;

use InvalidArgumentException;

final class ConceptGlossary
{
    private const DOCS = 'https://docs.ecotone.tech';

    /**
     * @var array<string, array{name: string, question: string, what: string, why: string, todo: string, docs?: string}>
     */
    private const TERMS = [
        'service' => [
            'name' => 'Service',
            'question' => 'What is a service?',
            'what' => 'An application running Ecotone with a PulseConfiguration. Pulse sends it a snapshot request over the Distributed Bus, on its request channel, and reads the answer.',
            'why' => 'Every number Pulse shows for it comes from its last answer.',
            'todo' => 'If it stops answering, open the service and follow Diagnose: check that it runs ecotone:run on its request channel.',
        ],
        'severity' => [
            'name' => 'Critical and warning',
            'question' => 'What do critical and warning mean?',
            'what' => 'Critical needs action now, because work is being lost or blocked: a service that does not answer, a channel that is not set up or not draining, failed messages growing since the last snapshot, a failed projection. Warning is something to look at and plan: a failed message that is kept and waits for you to replay it, a queue above its threshold, a projection behind.',
            'why' => 'A kept failed message loses nothing while it waits, so it is a warning; it becomes critical when more keep arriving.',
            'todo' => 'Handle the critical problems first, then open the warnings one by one.',
        ],
        'snapshot' => [
            'name' => 'Snapshot',
            'question' => 'What is a snapshot?',
            'what' => 'The answer a service sends Pulse: its versions, channels with their depth, consumers, packages, flow graph and, per package, projections, dead letters, setup and tracing, at one moment.',
            'why' => '"Last answer N s ago" tells you how fresh the page is; a section that failed to build is reported instead of left out.',
            'todo' => 'Refresh asks every service again; the periodic refresh does it on its own every PULSE_REFRESH_INTERVAL seconds.',
        ],
        'message' => [
            'name' => 'Message',
            'question' => 'What is a message?',
            'what' => 'A command (do something), an event (something happened) or a query (give me data), sent as a payload plus headers.',
            'why' => 'Handlers run in response to messages, so a failure is always the failure of one message in one handler.',
            'todo' => 'The Catalogue lists every message a service knows and who handles it.',
            'docs' => self::DOCS . '/modelling/command-handling',
        ],
        'command' => [
            'name' => 'Command',
            'question' => 'What is a command?',
            'what' => 'A message that asks for something to be done, handled by exactly one #[CommandHandler].',
            'why' => 'A command sent where no service handles it is lost work; Topology reports it as a mismatch.',
            'todo' => 'Find its handler in the Catalogue; follow it on the Flow graph.',
            'docs' => self::DOCS . '/modelling/command-handling',
        ],
        'event' => [
            'name' => 'Event',
            'question' => 'What is an event?',
            'what' => 'A message that says something happened, handled by any number of #[EventHandler] subscribers, including none.',
            'why' => 'Each subscriber reacts on its own, so one failing subscriber does not stop the others.',
            'todo' => 'The Catalogue lists the subscribers of each event.',
            'docs' => self::DOCS . '/modelling/command-handling',
        ],
        'query' => [
            'name' => 'Query',
            'question' => 'What is a query?',
            'what' => 'A message that asks for data and returns it, handled by one #[QueryHandler]; it changes nothing.',
            'why' => 'Queries are usually most of the traffic, so they can hide the slower commands in a list of flow types.',
            'todo' => 'Compare flow types by errors and by the slowest runs, not by how often they run.',
            'docs' => self::DOCS . '/modelling/command-handling',
        ],
        'handler' => [
            'name' => 'Handler',
            'question' => 'What is a handler?',
            'what' => 'The method that handles a message, marked #[CommandHandler], #[EventHandler] or #[QueryHandler].',
            'why' => 'A failing handler is where the code needs fixing; a failed message names it and the line that threw.',
            'todo' => 'Open the failed message to see the handler, the exception and the line that threw.',
            'docs' => self::DOCS . '/modelling/command-handling',
        ],
        'channel' => [
            'name' => 'Channel',
            'question' => 'What is a channel?',
            'what' => 'Where a message waits before a handler takes it. An asynchronous channel, named in #[Asynchronous(\'name\')], is a queue in a broker or a database table.',
            'why' => 'Messages on an asynchronous channel are handled only while a consumer runs it.',
            'todo' => 'Watch how many messages are ready and whether that number falls.',
        ],
        'ready' => [
            'name' => 'Ready',
            'question' => 'What does ready mean?',
            'what' => 'Messages waiting on a channel to be taken by a consumer.',
            'why' => 'A ready count that stays above zero and does not fall means nothing is handling the channel; Pulse reports it as not draining after PULSE_CHANNEL_STALLED_AFTER seconds.',
            'todo' => 'Start or scale the consumer shown for the channel.',
        ],
        'delayed' => [
            'name' => 'Delayed',
            'question' => 'What does delayed mean?',
            'what' => 'Messages scheduled for later, for example a delayed retry or a message sent with a delay; they become ready when their time comes.',
            'why' => 'A growing delayed count often means messages are failing and waiting to be retried.',
            'todo' => 'Check the dead letters and the recent traces for the failing handler. Not every broker reports this number.',
        ],
        'in-flight' => [
            'name' => 'In flight',
            'question' => 'What does in flight mean?',
            'what' => 'Messages a consumer has taken and not yet acknowledged, so they are being handled now.',
            'why' => 'In flight that stays high while ready grows means consumers are slow or stuck on a message.',
            'todo' => 'Look at the slowest traces of the channel\'s handlers. Only database, Redis and SQS channels report it; for other brokers it is unknown.',
        ],
        'consumer' => [
            'name' => 'Consumer',
            'question' => 'What is a consumer?',
            'what' => 'The process that takes messages from a channel and runs their handlers, started with ecotone:run <name>. A polling consumer reads an Ecotone channel; an inbound one pulls from its source directly, such as a #[Scheduled] method or a streaming projection.',
            'why' => 'Without a running consumer nothing on its channel is handled.',
            'todo' => 'Run it under a process manager (supervisor, systemd, Kubernetes) so it restarts; ecotone:list lists the consumers of a service.',
        ],
        'retry' => [
            'name' => 'Retry',
            'question' => 'What is a retry?',
            'what' => 'Ecotone handles a failed message again: instant retries happen right away in the same consumer, delayed retries come later through the error channel.',
            'why' => 'Short-lived errors heal on their own; only a message that still fails after all retries becomes a dead letter.',
            'todo' => 'Configure instant retries with InstantRetryConfiguration and delayed ones with ErrorHandlerConfiguration and RetryTemplateBuilder, returned from a #[ServiceContext] method.',
            'docs' => self::DOCS . '/modelling/recovering-tracing-and-monitoring/resiliency/retries',
        ],
        'error-channel' => [
            'name' => 'Error channel',
            'question' => 'What is an error channel?',
            'what' => 'The channel a consumer sends a message to when its handler fails, by default errorChannel; from there it is retried later or stored as a dead letter (dbal_dead_letter).',
            'why' => 'Without an error channel a failed message follows the final failure strategy of its channel instead.',
            'todo' => 'Set it with ServiceConfiguration::withDefaultErrorChannel(\'errorChannel\') and ErrorHandlerConfiguration.',
            'docs' => self::DOCS . '/modelling/recovering-tracing-and-monitoring/resiliency/retries',
        ],
        'final-failure-strategy' => [
            'name' => 'Final failure strategy',
            'question' => 'What is a final failure strategy?',
            'what' => 'What a channel does with a message that failed and has nowhere else to go: resend it (the default), release it, ignore it, or stop the consumer.',
            'why' => 'Resend keeps the consumer running but loses the order; release keeps the order but blocks the consumer; ignore drops the message.',
            'todo' => 'Pick it with withFinalFailureStrategy() on the channel builder, or add an error channel with a dead letter so nothing is lost.',
            'docs' => self::DOCS . '/modelling/recovering-tracing-and-monitoring/resiliency/final-failure-strategy#available-strategies',
        ],
        'dead-letter' => [
            'name' => 'Failed message',
            'question' => 'What is a failed message?',
            'what' => 'A message that still failed after all its retries. Ecotone keeps it instead of losing it, in its dead letter storage, which is why the console commands and the configuration call it a dead letter.',
            'why' => 'The work it carried, such as charging a card, has not happened until it is replayed.',
            'todo' => 'Fix the cause, then Replay it; Delete it only if that work must never happen.',
            'docs' => self::DOCS . '/modelling/recovering-tracing-and-monitoring/resiliency/retries',
        ],
        'failure' => [
            'name' => 'Failure group',
            'question' => 'What is a failure group?',
            'what' => 'The failed messages of the same message name that failed with the same exception at the same file and line.',
            'why' => 'One fix usually resolves the whole group.',
            'todo' => 'Open one message of the group to see the cause, fix it, then replay the group.',
        ],
        'replay' => [
            'name' => 'Replay',
            'question' => 'What does replay do?',
            'what' => 'Sends a failed message back to the channel it failed on, or to the channel you pick, to be handled again, and removes it from the failed messages.',
            'why' => 'Replaying before the cause is fixed only makes it fail and come back.',
            'todo' => 'Replay after the fix; from the console it is ecotone:deadletter:replay <messageId> or ecotone:deadletter:replayAll.',
        ],
        'delete' => [
            'name' => 'Delete',
            'question' => 'What does delete do?',
            'what' => 'Removes a failed message without handling it again.',
            'why' => 'The work it carried never happens, and a deleted message cannot be brought back.',
            'todo' => 'Delete only messages whose work must not happen, such as a duplicate; from the console it is ecotone:deadletter:delete <messageId>.',
        ],
        'projection' => [
            'name' => 'Projection',
            'question' => 'What is a projection?',
            'what' => 'A read model kept up to date from event streams by a #[Projection] class, which remembers its position in the stream.',
            'why' => 'When it is behind, stalled or failed, users read stale data.',
            'todo' => 'Backfill feeds it the events it has not seen; Rebuild resets it and feeds it every event again.',
            'docs' => self::DOCS . '/modelling/event-sourcing/setting-up-projections/backfill-and-rebuild',
        ],
        'behind' => [
            'name' => 'Behind',
            'question' => 'What does behind mean?',
            'what' => 'The projection has not yet processed every event in its streams; for a partitioned projection, some partitions have not.',
            'why' => 'Behind for a moment is normal under load; behind without moving is stalled.',
            'todo' => 'Wait a refresh or two; if the count does not fall, check its consumer and its dead letters.',
        ],
        'stalled' => [
            'name' => 'Stalled',
            'question' => 'What does stalled mean?',
            'what' => 'The projection is behind and its position has not moved across Pulse\'s snapshots for PULSE_PROJECTION_STALLED_AFTER seconds.',
            'why' => 'New events are arriving but the read model is not changing.',
            'todo' => 'Check that its consumer runs and look for dead letters on its channel.',
        ],
        'partition' => [
            'name' => 'Partition',
            'question' => 'What is a partition?',
            'what' => 'In a #[Partitioned] projection each aggregate instance, or each tag value, has its own position, so it is processed apart from the others.',
            'why' => 'One failing partition does not hold the others back, and Backfill and Rebuild run in batches of partitions with visible progress.',
            'todo' => 'Read "N of M partitions behind" to see how much is left.',
            'docs' => self::DOCS . '/modelling/event-sourcing/setting-up-projections/backfill-and-rebuild',
        ],
        'projection-failed' => [
            'name' => 'Failed projection',
            'question' => 'When is a projection failed?',
            'what' => 'Pulse marks a projection failed when dead letters are stored for its asynchronous channel: a message on that channel still failed after all its retries.',
            'why' => 'The read model may be missing what that message carried until the dead letter is replayed.',
            'todo' => 'Open the failures of the service, fix the cause, then replay the dead letters.',
        ],
        'backfill' => [
            'name' => 'Backfill',
            'question' => 'What does Backfill do?',
            'what' => 'Ecotone projects the events a projection has not seen yet, from the position each partition stored. Nothing is reset or deleted. The console command is ecotone:projection:backfill <name>.',
            'why' => 'A projection deployed after its events were stored catches up on that history without starting over.',
            'todo' => 'Set the batch size and an asynchronous channel with #[ProjectionBackfill(backfillPartitionBatchSize: 100, asyncChannelName: \'...\')].',
            'docs' => self::DOCS . '/modelling/event-sourcing/setting-up-projections/backfill-and-rebuild',
        ],
        'rebuild' => [
            'name' => 'Rebuild',
            'question' => 'What does Rebuild do?',
            'what' => 'Ecotone starts a projection over: it calls its #[ProjectionReset] method, clears its position and projects every event again. The console command is ecotone:projection:rebuild <name>.',
            'why' => 'It repairs a read model whose data is wrong, for example after fixing a bug in one of its event handlers.',
            'todo' => 'Give the projection a #[ProjectionReset] method that empties what it projects into; set the batch size and an asynchronous channel with #[ProjectionRebuild(partitionBatchSize: 100, asyncChannelName: \'...\')].',
            'docs' => self::DOCS . '/modelling/event-sourcing/setting-up-projections/backfill-and-rebuild',
        ],
        'trace' => [
            'name' => 'Trace',
            'question' => 'What is a trace?',
            'what' => 'The record of one flow: every message sent and handled under one tracing id, across services, with timing. Ecotone writes it when flow tracking (FlowTrackingConfiguration) is on.',
            'why' => 'It shows where the time went and which step failed.',
            'todo' => 'Open it from a failure or from a flow type.',
        ],
        'span' => [
            'name' => 'Step',
            'question' => 'What is a step?',
            'what' => 'One part of a trace, called a span in tracing tools: a message sent, received or handled, with its start, duration and outcome.',
            'why' => 'The failed or slowest step is where to look first.',
            'todo' => 'Select a step to see its details and attributes.',
        ],
        'queue-wait' => [
            'name' => 'Queue wait',
            'question' => 'What is queue wait?',
            'what' => 'The time a message spent in an asynchronous channel: from the end of the step that sent it to the start of the step that received it. Pulse computes it from the times of the two steps, which can come from the clocks of two services.',
            'why' => 'A long wait means the consumer of the channel was busy, behind or not running; the handler itself was not slow.',
            'todo' => 'If waits keep growing, run more consumers of the channel with ecotone:run <channel>, or look at what keeps them busy.',
        ],
        'hop' => [
            'name' => 'Hop',
            'question' => 'What is a hop?',
            'what' => 'A step that ran in a different service than the step that sent its message, for example a command sent with the Distributed Bus or an event published to another service.',
            'why' => 'A hop crosses a channel between services, so it usually adds a queue wait, and the receiving service holds its own logs and failures.',
            'todo' => 'Open the receiving service to see its consumers and dead letters.',
        ],
        'flow-type' => [
            'name' => 'Flow type',
            'question' => 'What is a flow type?',
            'what' => 'Traces grouped by how the flow entered the service: the name of the first message, such as order.place. Each service a flow reaches names it by the message that arrived there, so one business flow has a flow type in every service it crosses.',
            'why' => 'It compares runs of the same kind of work, so a slow or failing one stands out.',
            'todo' => 'Sort by errors and by the slowest runs, then open one trace.',
        ],
        'errors-recorded' => [
            'name' => 'Errors recorded',
            'question' => 'What does errors recorded mean?',
            'what' => 'How many of the newest runs of a flow type had a step that ended with an exception in this service.',
            'why' => 'It shows which kind of work fails. A message that still failed after all its retries is kept as a failed message, and its work has not happened until it is replayed. A failed attempt stays recorded, so a flow can complete, after a retry or a replay succeeded, and still have errors recorded.',
            'todo' => 'Open the traces of the flow type and select the failed step; replay a stored failure from Failures once the cause is fixed.',
        ],
        'p95' => [
            'name' => 'p95',
            'question' => 'What does p95 mean?',
            'what' => '95% of runs finished within this time; p50 is the typical run and max the slowest. The service computes them over the newest 1000 runs of each flow type it stores.',
            'why' => 'p95 shows what slow users experience, which an average hides.',
            'todo' => 'Compare p95 with p50: a large gap means some runs are much slower than usual.',
        ],
        'distributed-bus' => [
            'name' => 'Distributed Bus',
            'question' => 'What is the Distributed Bus?',
            'what' => 'How services send each other commands and events: handlers marked #[Distributed] receive them, DistributedBus sends them, and a DistributedServiceMap says which service gets what.',
            'why' => 'A route that points nowhere means a message is sent where nobody handles it.',
            'todo' => 'Topology lists the routes and the mismatches.',
            'docs' => self::DOCS . '/modelling/microservices-php/distributed-bus/distributed-bus-with-service-map/configuration#register-service-map-for-publishing',
        ],
        'asynchronous-arrow' => [
            'name' => 'Asynchronous arrow',
            'question' => 'What does a thick arrow mean?',
            'what' => 'A thick arrow (==>) leads from an asynchronous channel, named in #[Asynchronous(\'name\')], to the handler it feeds, or from one service to another across the Distributed Bus.',
            'why' => 'The sender does not wait: the message is queued and handled later, in another process, only while a consumer runs that channel, so a failure there becomes a retry or a dead letter rather than an error for the sender.',
            'todo' => 'Check the channel and its consumer on the service page; "Fold asynchronous channels into arrows" draws the channel as a label on the arrow.',
            'docs' => self::DOCS . '/modelling/asynchronous-handling',
        ],
        'inferred-arrow' => [
            'name' => 'Inferred arrow',
            'question' => 'What does a dashed arrow mean?',
            'what' => 'A dashed arrow (-.->) is read from the source code of a handler, such as $commandBus->sendWithRouting(\'order.place\') or $distributedBus->sendCommand(\'billing\', \'invoice.create\', ...), not declared by an attribute. A dashed arrow labelled reply carries the answer of a distributed message back.',
            'why' => 'Inference reads literal values only: a send with the routing key in a variable, or from code that is not a handler, such as a controller or a console command, draws no arrow.',
            'todo' => 'Treat a missing arrow as unknown, not as absent; highlight a trace to see the path a flow really took.',
        ],
        'inferred-message' => [
            'name' => 'Inferred message',
            'question' => 'What does inferred mean?',
            'what' => 'A message Pulse knows only from the source code of a handler, not from an attribute: an object created with new inside a call to a bus, an object a handler returns as an event, or a literal routing key such as $commandBus->sendWithRouting(\'order.archive\'). No handler in the services takes it.',
            'why' => 'Inference reads every object created inside the call, so it can also list a value that is not a message, such as a Uuid or an exception passed along with it.',
            'todo' => 'If it is a command or a query, add the handler that should take it; an event may have no subscriber yet. If it is not a message at all, ignore the entry.',
        ],
        'aggregate' => [
            'name' => 'Aggregate',
            'question' => 'What is an aggregate?',
            'what' => 'A class marked #[Aggregate] or #[EventSourcingAggregate] whose methods handle the commands for one entity, found by its #[Identifier], and record the events of what changed.',
            'why' => 'Its commands, the events it records and its queries belong together, so they are listed together.',
            'todo' => 'Open the aggregate to see what it accepts and what it records; follow a command on the Flow graph to see what reacts to it.',
            'docs' => self::DOCS . '/modelling/command-handling',
        ],
        'mismatch' => [
            'name' => 'Mismatch',
            'question' => 'What is a mismatch?',
            'what' => 'A disagreement between what services send over the Distributed Bus and where their DistributedServiceMap routes it: a command sent to a service the map does not name, a route to a service Pulse cannot check, an event published that no service receives, or a subscription nobody publishes.',
            'why' => 'A command without a route fails when it is sent, and an event without a subscriber reaches no other service.',
            'todo' => 'Change the DistributedServiceMap or add the #[Distributed] handler named in the mismatch.',
        ],
        'service-map' => [
            'name' => 'Service Map',
            'question' => 'What is a Service Map?',
            'what' => 'A DistributedServiceMap registered in a #[ServiceContext]: withCommandMapping(\'billing\', \'billing\') names the channel commands for billing go to, and withEventMapping(\'billing\', [\'order.*\']) the channel that published events matching order.* go to.',
            'why' => 'The Distributed Bus sends only where the map points: a command for a service the map does not name fails with UnknownDistributedDestination, and an event no mapping matches reaches no other service.',
            'todo' => 'Add the mapping to a #[ServiceContext] of the sending service; one map shared by every service keeps them in step.',
            'docs' => self::DOCS . '/modelling/microservices-php/distributed-bus/distributed-bus-with-service-map/configuration#register-service-map-for-publishing',
        ],
        'stale' => [
            'name' => 'Stale',
            'question' => 'What does stale mean?',
            'what' => 'Shown from the last snapshot of a service that is not connected right now (waiting, unreachable, or with its channel missing).',
            'why' => 'It may no longer match what the service runs.',
            'todo' => 'Bring the service back; the page updates on its next answer.',
        ],
        'deduplication' => [
            'name' => 'Deduplication',
            'question' => 'What is deduplication?',
            'what' => 'Ecotone remembers which message ids each asynchronous endpoint has handled, in the ecotone_deduplication table, and skips a message it has already handled.',
            'why' => 'A message delivered twice by the broker runs its handler only once.',
            'todo' => 'It is on by default with database (DBAL) support; set the key with #[Deduplicated] and how long ids are kept with withDeduplication().',
            'docs' => self::DOCS . '/modelling/recovering-tracing-and-monitoring/resiliency/idempotent-consumer-deduplication#deduplication-clean-up',
        ],
        'outbox' => [
            'name' => 'Outbox',
            'question' => 'What is an outbox?',
            'what' => 'A database-backed channel stores the message in the same transaction as the business data, so both are saved or neither is.',
            'why' => 'A message is never lost or sent for work that rolled back.',
            'todo' => 'Use a DbalBackedMessageChannelBuilder channel; Enterprise can forward it on to a broker.',
            'docs' => self::DOCS . '/modelling/asynchronous-handling/high-throughput-outbox-forwarding',
        ],
        'request-channel' => [
            'name' => 'Request channel',
            'question' => 'What is a request channel?',
            'what' => 'The channel a service consumes Pulse\'s snapshot requests from; by default it is named after the service, and Pulse knows it from PULSE_SERVICES (service:channel).',
            'why' => 'If the channel does not exist or nobody consumes it, the service never answers.',
            'todo' => 'Run ecotone:run <request channel> in the service, and keep it different from Pulse\'s own reply channel.',
        ],
        'service-name' => [
            'name' => 'Service name',
            'question' => 'What is the service name?',
            'what' => 'The name a service gives itself with ServiceConfiguration::withServiceName(); the Distributed Bus and Pulse address the service by it.',
            'why' => 'Pulse files each answer under the service name it carries, and ignores an answer whose name is not in PULSE_SERVICES.',
            'todo' => 'Use the same name in withServiceName() and in PULSE_SERVICES.',
        ],
        'pulse-services' => [
            'name' => 'PULSE_SERVICES',
            'question' => 'What is PULSE_SERVICES?',
            'what' => 'The setting that lists the services Pulse manages, separated by commas: the service name, then its request channel when it has another name, as in orders:orders_channel.',
            'why' => 'Pulse asks only the services listed there, and reads the list when it starts.',
            'todo' => 'Add the entry for the new service, then restart Pulse.',
        ],
        'licence-key' => [
            'name' => 'Licence key',
            'question' => 'What is the licence key?',
            'what' => 'The Ecotone Enterprise key a service needs to answer Pulse, passed as licenceKey in the Ecotone configuration of Symfony, Laravel, Tempest or Ecotone Lite.',
            'why' => 'Pulse support is part of Ecotone Enterprise: a service with a PulseConfiguration and no key refuses to boot, naming the licence.',
            'todo' => 'Add the key to the service\'s Ecotone configuration and restart it.',
            'docs' => self::DOCS . '/enterprise',
        ],
        'tenant' => [
            'name' => 'Tenant',
            'question' => 'What is a tenant?',
            'what' => 'A customer whose data lives in its own database connection, set up with MultiTenantConfiguration. Pulse asks such a service for one snapshot per tenant.',
            'why' => 'Projections, dead letters, traces and setup can differ per tenant.',
            'todo' => 'Pick the tenant to see its own sections.',
            'docs' => self::DOCS . '/messaging/multi-tenancy-support',
        ],
    ];

    public function concept(string $term): Concept
    {
        $entry = self::TERMS[$term] ?? throw new InvalidArgumentException(sprintf('Pulse has no concept "%s" in its glossary. Use one of: %s, or add the term to %s.', $term, implode(', ', $this->terms()), self::class));

        return new Concept($term, $entry['name'], $entry['question'], $entry['what'], $entry['why'], $entry['todo'], $entry['docs'] ?? null);
    }

    /**
     * @return list<string>
     */
    public function terms(): array
    {
        return array_keys(self::TERMS);
    }
}
