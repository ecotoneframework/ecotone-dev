---
description: "Showing a feature that runs only on Swoole coroutines in the pull request's flow comparison"
tags: [framework-testing]
max_turns: 20
allowed_tools: [Read, Glob, Grep, Skill]
---
I'm in the Ecotone framework monorepo (ecotone-dev). I added a channel type that works only on real Swoole or
OpenSwoole coroutines: `SameWorkerChannelBuilder::create('audit')` runs `#[Asynchronous('audit')]` handlers on
coroutines of the worker that sent the message, and the application refuses to boot with it when neither extension
is loaded. Its tests pass in the swoole and openswoole containers. The pull request's flow comparison comment shows
nothing for the channel, and I assumed that is unavoidable because the comparison runs on plain PHP. Is it? Write
out everything the change needs so the comparison shows the channel's flow (full code for any file you add, and
which files you leave alone). Don't ask questions.
