# Review findings: execution recording readability, R1 to R6 (head 93d7ada97)

The maintainer read the #771 recording as a page and named places that do not tell a reader what the framework did. Recording must never change what a flow does or throws, and with the option off nothing changes.

## R1. The gatherer: how a part is taken in, and how the group is handed over

```text
reply string to routing slip channel ecotone.projection.backfill.completion:course_seats.execute
  direct channel ecotone.projection.backfill.completion:course_seats.execute
    RecordedGatheringPart, chosen by the licence
      store part 1 of 2
        complete part 1 of gathering group {generated#1}
```

Not clear: what runs here, what it does with the message, and how the finished group gets to the code that
acts on it.

- The step names the recording class. It must name the framework class that does the work
  (`GatheringMessageHandler::handle`), like every other handler step. Check every other `Recorded*` class for the
  same leak; a recording class name must never appear in a recording.
- Show the steps the gatherer takes in order, each as its own step: which group and part the message says it is
  (and from which headers), where the part is stored (the store class), how many of how many parts are now in.
- When not all parts are in: say that nothing is handed over and the process ends here, waiting for the rest.
- When all parts are in: say what is built from the stored parts (what the released message carries: how many
  parts, the payload type), to what it is passed (channel or handler, and how that target was chosen: configured
  on the gathering, a header, the licence), and then the receiving handler as a nested step as now.
- `store part 1 of 2` containing `complete part 1` reads as if storing causes completing. Order and nest them as
  the code calls them.

Question a reader must be able to answer: "The second part report arrived. Who noticed the group was full, what
did it send, and how did `ProjectionRunCompletion::complete` come to be called?"

## R2. Post-send and after-send-completion look like the same thing

```text
post-send through OutboundSerializationChannelInterceptor, MessageCollectorChannelInterceptor, SendRetryChannelInterceptor, InMemoryQueueAcknowledgeInterceptor, TracingChannelInterceptor
after-send-completion through OutboundSerializationChannelInterceptor, MessageCollectorChannelInterceptor, SendRetryChannelInterceptor, InMemoryQueueAcknowledgeInterceptor, TracingChannelInterceptor
```

Two long identical lists; a reader cannot tell whether these are one phase written twice or two, nor what each is
for. The receive side has the same problem in one line (`pre-receive, after-receive-completion and post-receive
through …`).

- Name each phase by what it is: the interceptor method that ran and when it runs (after a send that succeeded;
  after the send ended, whether it succeeded or threw, and with which of the two). Same for the receive phases.
- Do not print the same five class names again for each phase. See R3: with nesting, each interceptor appears
  once and its phases hang from it.
- An interceptor whose phase method is the inherited no-op is not a step. If only one of five interceptors does
  anything after the send, one line is what the reader should see. Decide this from the class (method declared or
  overridden versus inherited empty default), at container build time, not by reflection per message.

Question: "After the message was put on `backfill`, which interceptors did something, and in which of the two
phases?"

## R3. Channel interceptors are flat

```text
send backfill
  channel interceptor OutboundSerializationChannelInterceptor
  channel interceptor MessageCollectorChannelInterceptor
  channel interceptor SendRetryChannelInterceptor
  channel interceptor InMemoryQueueAcknowledgeInterceptor
  span send backfill
    channel interceptor TracingChannelInterceptor
  post-send through …
  after-send-completion through …
```

Around interceptors of a handler are shown nested, so a reader sees what wraps what. Channel interceptors are a
flat list and the actual put on the channel is not a step at all.

- Show the send as it is carried out: each interceptor's before-send step in call order, the message each passes
  on (changed or not, when it changes: what changed, for example payload converted to which media type, which
  headers added), then the put on the channel as its own innermost step, then the after phases of R2 on the way
  out in the order the code runs them.
- Nest so that what runs inside what is visible, the same way around interceptors are nested. If the code calls
  them in a loop rather than as a chain, nest by order of entry and exit so the put on the channel is innermost;
  say in your report how you mapped the loop to nesting.
- An interceptor that stops the send must be visible as the place where it stopped: the collector holds the
  message (now `collected, held until the handler ends`), so the interceptors after it and the put on the channel
  do not run, and the recording shows exactly that, with the later release pointing back to it.
- Same shape for receive: the receive-side phases nest around the take from the channel.

Question: "In which order did interceptors see the message on its way onto `backfill`, which of them changed it,
and at which one did the first attempt stop?"

## R4. "reply … to routing slip channel" does not say what happened

```text
bridge Bridge::handle returns a message of string
reply string to routing slip channel ecotone.projection.partition_batch.executor
  direct channel ecotone.projection.partition_batch.executor
```

"Reply" suggests an answer going back to a caller, and the reader cannot tell whether a reply header was added,
read, or neither. What happens is that the handler's result is forwarded to the next channel named in a header of
the message.

- Do not call a forward a reply. Keep "reply" for a result going back to whoever is waiting for it (the reply
  channel of a request).
- Say which header decided the target and what it held: the routing slip before (all channels in it), the channel
  taken from it, what remains on the message after.
- Say what the forwarded message is: the handler's return value as payload, the request's headers kept, which
  headers were added or removed by this hand-over (reply channel kept or not).
- Where a routing slip is put on a message, that is a step too: `RoutingSlipPrepender::transform` now reads
  `returns a message of string`; it must say which channels it put in front and what the slip is afterwards. The
  same where the entrypoint or an asynchronous endpoint sets the slip before sending to the queue, so the reader
  sees at the send why the consumer will later go to `ecotone.projection.partition_batch.executor`.

Question: "The consumer took a message from `backfill`. Why did it go to
`ecotone.projection.partition_batch.executor`, and who decided that, when?"

## R5. Every hand-over says by which mechanism the message moved

After a handler returns, the message can move on by the endpoint's configured output channel, by the routing slip
header, by the reply channel header of the request, or not at all. The recording says this in some places
(`reply string to output channel backfill`, `no reply`) and not consistently, and never says why one was chosen
over another.

- Every hand-over after a handler, transformer, router, splitter, bridge, gateway or part completion names its
  mechanism in the same words everywhere: output channel (configured on which endpoint), routing slip (R4),
  reply channel of the request, part report (as now), or nothing.
- When more than one applies, say which won and what was left for later (for example: output channel taken now,
  routing slip still on the message for the next step). Record the order of precedence the code applies; do not
  assume it.
- "no reply" says why: the handler returned nothing; or it returned a value and nothing was waiting for it.
- Routers and the entrypoint's `HeaderRouter` say which header or rule chose the route (`route to backfill` now
  has no reason).

Question: for every arrow in the flow, "why did the message go there: configuration, a header, or a waiting
caller?"

## R6. Process boundaries

Reading the gatherer process, the projection seemed to run and the part to complete in one run. Make the process
boundaries explicit:

- (a) Every consumer process says which message it received and where it came from: the earlier process and step
  that sent it, and the sending step points forward the same way (handled later by a separate consumer run). Keep
  this deterministic and derived from what was recorded, not guessed.
- (b) The process title and first steps of a gatherer run make clear that no projection is executed in it.
- (c) `complete part` is the store method name and reads as "work completed": word it as the part being recorded as
  arrived, as one step, not two nested ones.
- (d) When the last part fills the group, say that the release and the call to the gathered handler
  (`ProjectionRunCompletion::complete`) run inside this same consumer run, with no queue in between, if that is what
  the code does.
