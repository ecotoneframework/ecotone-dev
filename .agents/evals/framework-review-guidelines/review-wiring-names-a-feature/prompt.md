---
description: "Guidelines review of a diff where another feature's compiler pass names a channel class, next to a pool guarding on its own wait setting"
tags: [framework-review-guidelines]
max_turns: 20
allowed_tools: [Read, Glob, Grep, Skill]
---
You're the guidelines reviewer for a change in the Ecotone framework monorepo (ecotone-dev). The change's purpose: 'the execution recording says that a message sent to the in-process channel is taken by the sending process, and the in-process consumer pool fails at once when it is configured with a wait of 0 seconds'. Here is the full diff (`git diff main...HEAD`):

```diff
--- a/packages/Ecotone/src/Tracing/Execution/RecordExecutionCompilerPass.php
+++ b/packages/Ecotone/src/Tracing/Execution/RecordExecutionCompilerPass.php
@@
 use Ecotone\Messaging\Channel\DirectChannel;
+use Ecotone\Messaging\Channel\InProcess\InProcessMessageChannel;
 use Ecotone\Messaging\Config\Container\CompilerPass;
@@
 final class RecordExecutionCompilerPass implements CompilerPass
 {
     private function recordingOf(string $channelName, Definition $channel, mixed $innerChannel): Definition
     {
         return new Definition(ChannelSendRecording::class, [
             $channelName,
+            $innerChannel instanceof Definition && $innerChannel->getClassName() === InProcessMessageChannel::class,
             new Reference(Tracer::class),
         ]);
     }
 }
--- a/packages/Ecotone/src/Tracing/Execution/ChannelSendRecording.php
+++ b/packages/Ecotone/src/Tracing/Execution/ChannelSendRecording.php
@@
-    public function __construct(private string $channelName, private Tracer $tracer)
+    public function __construct(private string $channelName, private bool $takenByTheSendingProcess, private Tracer $tracer)
     {
     }
--- a/packages/Ecotone/src/Messaging/Channel/InProcess/InProcessConsumerPool.php
+++ b/packages/Ecotone/src/Messaging/Channel/InProcess/InProcessConsumerPool.php
@@
 final class InProcessConsumerPool
 {
     public function __construct(private string $channelName, private int $size, private float $waitSeconds)
     {
     }

     public function take(): InProcessConsumer
     {
         if ($this->idle() !== null) {
             return $this->idle();
         }
+        if ($this->waitSeconds <= 0.0) {
+            throw InProcessConsumerPoolExhausted::withEveryConsumerBusy(channelName: $this->channelName, size: $this->size, waitedSeconds: 0.0);
+        }
         return $this->waitForAReturnedConsumer($this->waitSeconds);
     }
 }
```

Every class in the diff exists on main, carries `licence Apache-2.0`, is `final` and declares strict types. The tracing classes belong to the execution recording feature; the `InProcess` classes belong to the in-process channel feature, which is a different module. `$waitSeconds` is what the application passed to `InProcessChannelBuilder::withConsumerWaitSeconds()`, documented there as "seconds to wait for a free consumer, 0 fails at once", and the builder hands it to the pool's `Definition`. Tests in the diff (not shown) assert the recorded line and the exception through `EcotoneLite::bootstrapFlowTesting()`.

Write your review report.
