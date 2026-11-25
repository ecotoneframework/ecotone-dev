<?php

declare(strict_types=1);

namespace Ecotone\Sqs;

use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Execution\ExecutionScope;
use Enqueue\Sqs\SqsClient;

/**
 * licence Apache-2.0
 */
final class SqsClientOfExecution
{
    public ?SqsClient $client = null;

    public static function refuseCoroutineWhoseCurlHookRejectsTheAwsSdk(): void
    {
        if (! ExecutionScope::isCoroutineOfOpenSwooleHookingCurl()) {
            return;
        }

        throw ConfigurationException::create(
            'An SQS queue is used inside an OpenSwoole coroutine with the curl hook enabled. '
            . 'OpenSwoole\'s curl hook rejects options the HTTP client of the AWS SDK sets, so every SQS request fails there with `curl_setopt(): Argument #2 ($option) is not a valid cURL option`. '
            . 'Run the application on Swoole, where coroutines wait on SQS requests; '
            . 'or switch the hook off before the coroutines start, `OpenSwoole\Runtime::enableCoroutine(OpenSwoole\Runtime::HOOK_ALL & ~(OpenSwoole\Runtime::HOOK_CURL | OpenSwoole\Runtime::HOOK_NATIVE_CURL))` or the same value as `hook_flags` of the server, and every SQS request then stops the other coroutines of the process while it waits; '
            . 'or handle one message at a time: set `enable_coroutine` to false on the server and run consumers without the `coroutines` option.'
        );
    }

    public static function refuseClientOfTheApplicationInsideACoroutine(): void
    {
        if (! ExecutionScope::isInsideCoroutine()) {
            return;
        }

        throw ConfigurationException::create(
            'SqsConnectionFactory was given an Aws\Sqs\SqsClient the application built itself, and is used inside a Swoole or OpenSwoole coroutine. '
            . 'That client sends every request through one HTTP handler for the whole process, and two coroutines calling it at once stop the process. '
            . "Pass the connection settings instead, `new SqsConnectionFactory('sqs:?key=...&secret=...&region=...')` or the same settings as an array, so every coroutine builds a client of its own, "
            . 'or handle one message at a time: set `enable_coroutine` to false on the server and run consumers without the `coroutines` option.'
        );
    }
}
