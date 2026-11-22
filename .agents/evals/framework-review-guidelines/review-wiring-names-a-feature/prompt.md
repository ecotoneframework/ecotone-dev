---
description: "Guidelines review of a diff where another feature's compiler pass names a channel class, next to a pool that guards on its own wait setting and picks a wait implementation per call"
tags: [framework-review-guidelines]
max_turns: 20
allowed_tools: [Read, Glob, Grep, Skill]
---
You're the guidelines reviewer for a change in the Ecotone framework monorepo (ecotone-dev). The change's purpose: 'the hand-over recording says that a message sent to the in-process channel is taken by the sending process; the in-process consumer pool fails at once when it is configured with a wait of 0 seconds, and waits with the ev extension where it is installed'. Here is the full diff (`git diff main...HEAD`):

```diff
--- a/packages/Ecotone/src/Tracing/HandOver/HandOverRecordingCompilerPass.php
+++ b/packages/Ecotone/src/Tracing/HandOver/HandOverRecordingCompilerPass.php
@@
 use Ecotone\Messaging\Channel\DirectChannel;
+use Ecotone\Messaging\Channel\InProcess\InProcessMessageChannel;
 use Ecotone\Messaging\Config\Container\Compiler\CompilerPass;
@@
 final class HandOverRecordingCompilerPass implements CompilerPass
 {
     private function recordingOf(string $channelName, mixed $innerChannel): Definition
     {
         return new Definition(HandOverRecording::class, [
             $channelName,
+            $innerChannel instanceof Definition && $innerChannel->getClassName() === InProcessMessageChannel::class,
             new Reference(HandOverRecorder::class),
         ]);
     }
 }
--- a/packages/Ecotone/src/Tracing/HandOver/HandOverRecording.php
+++ b/packages/Ecotone/src/Tracing/HandOver/HandOverRecording.php
@@
-    public function __construct(private string $channelName, private HandOverRecorder $recorder)
+    public function __construct(private string $channelName, private bool $takenByTheSendingProcess, private HandOverRecorder $recorder)
     {
     }
--- a/packages/Ecotone/src/Messaging/Channel/InProcess/InProcessConsumerPool.php
+++ b/packages/Ecotone/src/Messaging/Channel/InProcess/InProcessConsumerPool.php
@@
 final class InProcessConsumerPool
 {
-    public function __construct(private string $channelName, private int $size, private float $waitSeconds, private StreamSelectWait $streamSelectWait)
+    public function __construct(private string $channelName, private int $size, private float $waitSeconds, private StreamSelectWait $streamSelectWait, private EvWait $evWait)
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
-        return $this->streamSelectWait->forAReturnedConsumer(pool: $this, seconds: $this->waitSeconds);
+        return extension_loaded('ev')
+            ? $this->evWait->forAReturnedConsumer(pool: $this, seconds: $this->waitSeconds)
+            : $this->streamSelectWait->forAReturnedConsumer(pool: $this, seconds: $this->waitSeconds);
     }
 }
```

Take the classes in the diff as given: the `HandOver` classes are the hand-over recording feature and the `InProcess` classes the in-process channel feature, two different modules this change builds on, not classes of the Ecotone tree you may know. All of them carry `licence Apache-2.0`, are `final` and declare strict types. `$waitSeconds` is what the application passed to `InProcessChannelBuilder::withConsumerWaitSeconds()`, documented there as "seconds to wait for a free consumer, 0 fails at once", and the builder hands it to the pool's `Definition`. `EvWait` and `StreamSelectWait` wait for a returned consumer in two different ways and share no interface yet. Tests in the diff (not shown) assert the recorded line and the exception through `EcotoneLite::bootstrapFlowTesting()`.

Write your review report.
