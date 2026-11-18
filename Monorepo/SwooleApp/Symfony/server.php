<?php

/**
 * Symfony on OpenSwoole. Environment:
 *   SWOOLE_WORKERS      worker processes, default 2
 *   SWOOLE_COROUTINES   1 runs each request in its own coroutine with every blocking call hooked, default 0
 *   SWOOLE_MAX_REQUEST  requests a worker serves before the manager replaces it, default 0 (never)
 *   SWOOLE_BOOT         "worker" boots one kernel per worker after the fork, "master" boots it once before the fork
 *   SWOOLE_WARM_UP      1 handles one request right after the boot, which opens the database connection, default 0
 */

use Monorepo\SwooleApp\Symfony\Kernel;
use OpenSwoole\Constant;
use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;
use OpenSwoole\Http\Server;
use OpenSwoole\Runtime;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$coroutines = (bool) (getenv('SWOOLE_COROUTINES') ?: 0);
$bootInMaster = (getenv('SWOOLE_BOOT') ?: 'worker') === 'master';

$server = new Server('0.0.0.0', 9501);
$server->set([
    'worker_num' => (int) (getenv('SWOOLE_WORKERS') ?: 2),
    'max_request' => (int) (getenv('SWOOLE_MAX_REQUEST') ?: 0),
    'enable_coroutine' => $coroutines,
    'hook_flags' => $coroutines ? Runtime::HOOK_ALL : 0,
    'log_level' => Constant::LOG_WARNING,
]);

$kernel = null;
$boot = static function () use (&$kernel): void {
    $kernel = new Kernel(getenv('APP_ENV') ?: 'prod', false);
    $kernel->boot();
    if (getenv('SWOOLE_WARM_UP')) {
        $kernel->handle(SymfonyRequest::create('/state', 'POST', content: '{}'));
    }
};

if ($bootInMaster) {
    $boot();
} else {
    $server->on('workerStart', $boot);
}

$server->on('request', static function (Request $request, Response $response) use (&$kernel): void {
    $symfonyRequest = new SymfonyRequest(
        $request->get ?? [],
        $request->post ?? [],
        [],
        $request->cookie ?? [],
        [],
        array_change_key_case($request->server ?? [], CASE_UPPER),
        $request->rawContent() ?: null,
    );
    $symfonyRequest->headers->add($request->header ?? []);

    $symfonyResponse = $kernel->handle($symfonyRequest);

    $response->status($symfonyResponse->getStatusCode());
    foreach ($symfonyResponse->headers->all() as $name => $values) {
        foreach ($values as $value) {
            $response->header((string) $name, (string) $value);
        }
    }
    $response->end($symfonyResponse->getContent());

    $kernel->terminate($symfonyRequest, $symfonyResponse);
});

$server->start();
