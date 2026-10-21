<?php

declare(strict_types=1);

namespace App\Doctor;

use App\Settings\Connection;
use App\Settings\ServiceRoute;
use App\Settings\Transport;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Sqs\SqsConnectionFactory;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class BrokerProbe
{
    private const TIMEOUT_SECONDS = 3;

    public function __construct(#[Autowire(service: 'service_container')] private ContainerInterface $container)
    {
    }

    /**
     * @return list<array{string, int}>
     */
    public function endpoints(Connection $connection): array
    {
        if ($connection->transport === Transport::Kafka) {
            return array_map(static function (string $server): array {
                [$host, $port] = array_pad(explode(':', $server, 2), 2, '9092');

                return [$host, (int) $port];
            }, $connection->kafkaBootstrapServers());
        }

        $parts = parse_url((string) preg_replace('#^sqs:\??#i', 'sqs://x?', $connection->dsn)) ?: [];
        if ($connection->transport === Transport::Sqs) {
            $endpoint = [];
            parse_str($parts['query'] ?? '', $endpoint);
            $endpointParts = parse_url((string) ($endpoint['endpoint'] ?? '')) ?: [];

            return isset($endpointParts['host']) ? [[$endpointParts['host'], $endpointParts['port'] ?? 80]] : [];
        }
        if (! isset($parts['host']) || strtolower((string) ($parts['scheme'] ?? '')) === 'sqlite') {
            return [];
        }

        $scheme = strtolower($parts['scheme'] ?? '');

        return [[$parts['host'], $parts['port'] ?? match ($scheme) {
            'amqp' => 5672,
            'amqps' => 5671,
            'redis', 'rediss' => 6379,
            'pgsql' => 5432,
            'mysql' => 3306,
            default => 0,
        }]];
    }

    public function loginProblem(Connection $connection): ?string
    {
        try {
            match ($connection->transport) {
                Transport::Amqp => $this->amqpConnection($connection),
                Transport::Redis => $this->redis($connection),
                Transport::Dbal => $this->container->get($connection->referenceName())->createContext()->getDbalConnection()->fetchOne('SELECT 1'),
                Transport::Sqs => $this->container->get($connection->referenceName())->createContext()->getClient()->listQueues(),
                Transport::Kafka => $this->kafka($connection)->getMetadata(false, null, self::TIMEOUT_SECONDS * 1000),
            };
        } catch (\Throwable $exception) {
            return $exception->getMessage();
        }

        return null;
    }

    public function destinationExists(Connection $connection, ServiceRoute $route): ?bool
    {
        try {
            return match ($connection->transport) {
                Transport::Amqp => $this->amqpQueueExists($connection, $route->destination),
                Transport::Kafka => $this->kafkaTopicExists($connection, $route->destination),
                Transport::Sqs => $this->sqsQueueExists($connection, $route->destination),
                Transport::Redis, Transport::Dbal => null,
            };
        } catch (\Throwable) {
            return false;
        }
    }

    private function amqpConnection(Connection $connection): \AMQPConnection
    {
        $parts = parse_url($connection->dsn) ?: [];
        $amqp = new \AMQPConnection([
            'host' => $parts['host'] ?? 'localhost',
            'port' => $parts['port'] ?? 5672,
            'login' => urldecode($parts['user'] ?? 'guest'),
            'password' => urldecode($parts['pass'] ?? 'guest'),
            'vhost' => isset($parts['path']) && $parts['path'] !== '/' ? urldecode(ltrim($parts['path'], '/')) : '/',
            'connect_timeout' => self::TIMEOUT_SECONDS,
        ]);
        $amqp->connect();

        return $amqp;
    }

    private function amqpQueueExists(Connection $connection, string $queueName): bool
    {
        $amqp = $this->amqpConnection($connection);
        try {
            $queue = new \AMQPQueue(new \AMQPChannel($amqp));
            $queue->setName($queueName);
            $queue->setFlags(AMQP_PASSIVE);
            $queue->declareQueue();
        } catch (\AMQPQueueException) {
            return false;
        } finally {
            $amqp->disconnect();
        }

        return true;
    }

    private function redis(Connection $connection): void
    {
        $parts = parse_url($connection->dsn) ?: [];
        $redis = new \Redis();
        $redis->connect($parts['host'] ?? 'localhost', $parts['port'] ?? 6379, self::TIMEOUT_SECONDS);
        if (isset($parts['pass'])) {
            $redis->auth(urldecode($parts['pass']));
        }
        $redis->ping();
    }

    private function kafka(Connection $connection): \RdKafka\Producer
    {
        $configuration = new \RdKafka\Conf();
        $configuration->set('metadata.broker.list', implode(',', $connection->kafkaBootstrapServers()));

        return new \RdKafka\Producer($configuration);
    }

    private function kafkaTopicExists(Connection $connection, string $topic): bool
    {
        foreach ($this->kafka($connection)->getMetadata(true, null, self::TIMEOUT_SECONDS * 1000)->getTopics() as $existing) {
            if ($existing->getTopic() === $topic) {
                return true;
            }
        }

        return false;
    }

    private function sqsQueueExists(Connection $connection, string $queue): bool
    {
        $this->container->get($connection->referenceName())->createContext()->getClient()->getQueueUrl(['QueueName' => $queue]);

        return true;
    }
}
