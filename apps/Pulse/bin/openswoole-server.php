<?php

declare(strict_types=1);

/*
 * Pulse on OpenSwoole, started by docker/entrypoint.sh when PULSE_RUNTIME=swoole.
 * Every worker boots Pulse once and keeps it between requests; a worker handles one request at a time.
 *
 *   PULSE_HTTP_PORT       port to listen on, default 8080
 *   PULSE_HTTP_WORKERS    worker processes, default 4
 *   PULSE_PUBLIC_DIR      directory of the compiled assets, default public/ of Pulse
 */

use App\Kernel;
use App\Settings\PulseEnvironment;
use App\Settings\Runtime;
use OpenSwoole\Constant;
use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;
use OpenSwoole\Http\Server;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

require dirname(__DIR__) . '/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__) . '/.env');

$server = new Server('0.0.0.0', (int) (getenv('PULSE_HTTP_PORT') ?: 8080));
$server->set([
    'worker_num' => Runtime::httpWorkers(PulseEnvironment::read()),
    'enable_coroutine' => false,
    'enable_static_handler' => true,
    'document_root' => getenv('PULSE_PUBLIC_DIR') ?: dirname(__DIR__) . '/public',
    'http_compression' => true,
    'log_level' => Constant::LOG_WARNING,
]);

$kernel = null;
$server->on('workerStart', static function () use (&$kernel): void {
    $kernel = new Kernel($_SERVER['APP_ENV'], (bool) $_SERVER['APP_DEBUG']);
    $kernel->boot();
});

$server->on('request', static function (Request $request, Response $response) use (&$kernel): void {
    $serverVariables = array_change_key_case($request->server ?? [], CASE_UPPER);
    foreach ($request->header ?? [] as $name => $value) {
        $serverVariables['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
    }
    $symfonyRequest = new SymfonyRequest(
        $request->get ?? [],
        $request->post ?? [],
        [],
        $request->cookie ?? [],
        $request->files ?? [],
        $serverVariables,
        $request->rawContent() ?: null,
    );

    $symfonyResponse = $kernel->handle($symfonyRequest);

    $response->status($symfonyResponse->getStatusCode());
    foreach ($symfonyResponse->headers->allPreserveCaseWithoutCookies() as $name => $values) {
        $response->header((string) $name, implode(', ', $values));
    }
    foreach ($symfonyResponse->headers->getCookies() as $cookie) {
        $response->cookie(
            $cookie->getName(),
            (string) $cookie->getValue(),
            $cookie->getExpiresTime(),
            $cookie->getPath(),
            (string) $cookie->getDomain(),
            $cookie->isSecure(),
            $cookie->isHttpOnly(),
            (string) $cookie->getSameSite(),
        );
    }
    $response->end((string) $symfonyResponse->getContent());

    $kernel->terminate($symfonyRequest, $symfonyResponse);
});

$server->start();
