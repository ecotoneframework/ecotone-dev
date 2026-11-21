---
description: "Send asynchronous notifications in the HTTP worker after committing the order"
tags: [ecotone-open-swoole]
max_turns: 15
allowed_tools: [Read, Glob, Grep, Skill]
---
Our Symfony application uses Ecotone 2.0 and runs HTTP requests on OpenSwoole with coroutines and hooks enabled. MySQL connections are registered from a DSN, and we have an Ecotone Enterprise licence. Placing an order sends a notification command, but our HTTP response currently waits for the notification provider.

Notifications are best effort: we accept losing pending notifications if the HTTP worker crashes or restarts. We want the order transaction to commit before notification handling starts, and a failed order must start no notification. At most four notification handlers should run at once in each HTTP worker, with the rest waiting in memory. One HTTP worker handles at most twelve requests at once and each request uses a database connection. Show the sender, handler attributes and ServiceContext configuration, including the pool size. Explain when the handlers actually start, whether a transaction opened manually around the bus would also be waited for, and what happens when the worker stops. Do not ask questions.
