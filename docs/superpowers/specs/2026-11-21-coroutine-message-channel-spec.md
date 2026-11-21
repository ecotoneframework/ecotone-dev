# Coroutine message channel

## Scope

Enterprise `CoroutineMessageChannelBuilder` executes asynchronous handlers in the sending PHP worker. This wave covers commands, events, gateways, console commands and asynchronous endpoints, on Swoole and OpenSwoole. Database boundaries cover MySQL and PostgreSQL on Swoole; OpenSwoole refuses coroutine PostgreSQL connections. Future-returning gateways and their caller-executed queries belong to wave 2. Serialisation, shutdown draining and pool-size comparisons are excluded from production and tests.

The ordinary polling consumer already supplies acknowledgements, retries, error routing and dead letters. Its message collector releases inside the database transaction, so it cannot supply after-commit delivery. CoroutineMessageChannelHoldingInterceptor, a separate outer interceptor, holds messages in ExecutionScope, outside the transaction and inside instant retry. Successful release feeds a process-local queue and ConsumerCoroutines through CoroutineMessageChannelConsumer; no database storage is introduced. The container registers CoroutineMessageChannelSleep under an internal service id of its own, so a `SleepInterface` service of the application is left alone; the Enterprise CoroutineMessageChannelModule replaces that id with the test clock when the flow test pins time, so the open-core test support knows nothing of the channel. Channel builders have no knowledge of test configuration. Pending timers and queued messages are volatile and shutdown is not coordinated.

## Decisions and executable evidence

Test names below are in `packages/Ecotone/tests/Messaging/Execution/CoroutineMessageChannelTest.php` unless another file is named.

| Approved decision | Evidence |
| --- | --- |
| Handlers execute in the sending process | `test_handler_runs_on_a_coroutine_of_the_sending_process` |
| Successful outer boundary releases after Ecotone commits | Dbal `CoroutineMessageChannelTest::test_handler_starts_after_the_sender_commits_and_sees_its_data`, command_bus, gateway, asynchronous, console and event datasets; corresponding `_on_postgresql` test |
| Failed senders start nothing | `test_a_failed_sender_starts_no_handler`, `test_failed_console_command_starts_no_handler`, `test_console_command_holds_messages_until_it_returns`, `test_failed_gateway_discards_messages_and_successful_gateway_releases_them` |
| Each execution owns its held messages | Dbal `test_interleaved_senders_release_only_the_successful_senders_message` |
| Retried attempts discard failed attempts | `test_a_retried_sender_discards_messages_from_its_failed_attempt`, `test_retried_outer_command_discards_messages_from_its_failed_nested_call` |
| Caught inner failures share the outer transaction outcome | Dbal `test_caught_inner_failure_commits_its_rows_and_releases_both_commands_messages_with_the_outer_command` |
| No supported userland entry bypasses release boundaries | Checked CommandBus, EventBus and QueryBus methods (all carry MessageGateway), custom MessageGateway methods, ConsoleCommand and AsynchronousRunningEndpoint. A direct send to the channel object, with no boundary around it, is handed over at once and has no userland test, because every userland entry point is a release boundary; the direct channel test was removed after G16. |
| Sending does not wait for a handler | `test_command_bus_returns_before_even_a_non_yielding_handler_finishes` |
| Per-channel limit defaults to 10, waiting messages start FIFO | `test_concurrency_is_limited_and_waiting_messages_start_in_order` |
| Payload objects are passed without serialisation; ServiceContext registration | `test_class_routed_objects_keep_their_identity_with_service_context_registration` |
| Without an error channel final failures log channel, endpoint and headers and drop; other messages continue | `test_failed_messages_are_logged_with_headers_and_dropped_while_others_continue`, `test_failure_logs_keep_each_coroutines_endpoint_and_headers` |
| Ordinary error channel and instant retry pipeline | `test_handler_scoped_error_channel_receives_the_failure_and_headers`, `test_polling_metadata_error_channel_receives_the_failure_and_headers`, `test_instant_retry_runs_in_the_ordinary_asynchronous_consumer` |
| Delays and delayed retries use in-process timers | `test_delayed_message_waits_on_a_coroutine_timer`, `test_delay_header_accepts_durations_and_an_absolute_time`, `test_delayed_retry_returns_to_the_coroutine_channel_after_its_delay`, `test_delayed_message_with_hooks_off_does_not_stall_a_sibling_coroutine`, `test_delays_wait_on_the_coroutine_timer_beside_a_sleep_service_of_the_application`; `TestClockAtBootstrapTest::test_pinned_time_leaves_the_applications_sleep_service_in_place` |
| DBAL dead letter and synchronous replay outside a coroutine | Dbal `test_failed_message_goes_to_dbal_dead_letter_and_replays_outside_a_coroutine` |
| Independent headers and transactions | Dbal `test_concurrent_messages_keep_their_own_headers_and_transaction` |
| Tenant connections and deduplication | Dbal `test_each_message_uses_its_tenants_connection`, `test_deduplicated_handler_runs_once_for_the_same_deduplication_key` |
| Sending to the same channel with limit one completes | `test_a_handler_can_send_to_its_own_channel_with_one_coroutine` |
| Traces retain the sender context | OpenTelemetry `CoroutineTracingTest::test_coroutine_message_channel_continues_each_senders_trace` |
| Outside a coroutine is refused with recovery and rollback guidance, including fibers | `test_sending_outside_a_coroutine_is_refused`, `test_a_sender_on_a_fiber_outside_a_coroutine_is_refused` |
| Bootstrap requires Enterprise licence and runtime extension; positive concurrency; extension-free environment replacement | `CoroutineMessageChannelRefusedTest`, including `test_a_production_coroutine_channel_can_be_replaced_with_a_queue_in_tests` |
| Separate consumer execution and polling concurrency are refused | `test_starting_a_consumer_is_refused`, `test_console_consumer_start_is_refused`, `test_polling_concurrency_is_refused_at_bootstrap` |
| Consumer stop/handled/time/execution/memory limits are unsupported | `test_polling_consumer_limits_are_refused_at_bootstrap` |
| Priority and TimeToLive are refused on methods and classes | `test_unsupported_delivery_attributes_are_refused_at_bootstrap` |
| Configurable pool wait reaches Symfony's connection pool | Symfony `CoroutineConnectionPoolTest::test_symfony_zero_pool_wait_refuses_a_second_coroutine_at_once`; `SymfonyConnectionCopiesTest::test_create_for_connection_refuses_a_negative_pool_wait` |

Loss on crash/restart and no shutdown guarantee are explicit exclusions: there is no durable write and no process-kill acceptance test. A manually managed external transaction is not the release boundary. Future resolution, caller execution of unstarted queries and refusing Future gateways on other channels are deferred to wave 2, with no tests claimed here.

## Configuration decisions retained for maintainer review

The default remains 10, maximum delay remains unlimited, and `isPollable()` remains true. Changing any of these requires a maintainer decision. Polling memory defaults of 1024 MB and disabled memory limit 0 are accepted; other memory limits are refused, and the internal consumer disables memory stopping because this channel does not own the worker process. Polling concurrency 1 is an indistinguishable default and is accepted. The queue's ordinary polling registration requires `isPollable()`; switching it off removes the asynchronous bridge and endpoint runner without supplying an event-driven replacement.
