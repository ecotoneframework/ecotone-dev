---
description: "Split an import into rows worked at once and summarised in the same HTTP worker"
tags: [ecotone-open-swoole]
max_turns: 15
allowed_tools: [Read, Glob, Grep, Skill]
---
Our Symfony application uses Ecotone 2.0 and runs HTTP requests on OpenSwoole with coroutines and hooks enabled. MySQL connections are registered from a DSN, and we have an Ecotone Enterprise licence. An import command carries a list of rows. We want the HTTP response to return right away, the rows to be processed in the same HTTP worker, up to ten at once, and one summary handler to run once per import with the results of every row. We accept losing a pending import if the worker restarts.

Show the handler attributes for the import command, the row handler and the summary handler, and the ServiceContext configuration of the channels, including how many messages each channel handles at once and why. Do not ask questions.
