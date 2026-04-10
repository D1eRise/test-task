<?php

declare(strict_types=1);

namespace App\Integration\Queue;

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

class RabbitMqQueue
{
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $user,
        private readonly string $password,
        private readonly string $vhost,
        private readonly string $queueName
    ) {
    }

    public function publish(array $message): void
    {
        $connection = $this->createConnection();
        $channel = $connection->channel();
        $channel->queue_declare($this->queueName, false, true, false, false);

        $amqpMessage = new AMQPMessage(
            json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            [
                'content_type' => 'application/json',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
            ]
        );

        $channel->basic_publish($amqpMessage, '', $this->queueName);
        $channel->close();
        $connection->close();
    }

    public function purge(): void
    {
        $connection = $this->createConnection();
        $channel = $connection->channel();
        $channel->queue_declare($this->queueName, false, true, false, false);
        $channel->queue_purge($this->queueName);
        $channel->close();
        $connection->close();
    }

    


    public function consume(callable $handler, int $limit = 0): int
    {
        if ($limit > 0) {
            return $this->consumeBatch($handler, $limit);
        }

        return $this->consumeForever($handler);
    }

    


    private function consumeBatch(callable $handler, int $limit): int
    {
        $connection = $this->createConnection();
        $channel = $connection->channel();
        $channel->queue_declare($this->queueName, false, true, false, false);

        $processed = 0;

        try {
            while ($processed < $limit) {
                $amqpMessage = $channel->basic_get($this->queueName, false);

                if ($amqpMessage === null) {
                    break;
                }

                try {
                     
                    $message = json_decode($amqpMessage->getBody(), true, 512, JSON_THROW_ON_ERROR);
                    $handler($message);
                    $channel->basic_ack($amqpMessage->getDeliveryTag());
                } catch (\Throwable $exception) {
                    $channel->basic_nack($amqpMessage->getDeliveryTag(), false, true);
                    throw $exception;
                }

                $processed++;
            }
        } finally {
            $channel->close();
            $connection->close();
        }

        return $processed;
    }

    


    private function consumeForever(callable $handler): int
    {
        $connection = $this->createConnection();
        $channel = $connection->channel();
        $channel->queue_declare($this->queueName, false, true, false, false);
        $channel->basic_qos(null, 1, null);

        $processed = 0;
        $channel->basic_consume(
            $this->queueName,
            '',
            false,
            false,
            false,
            false,
            function (AMQPMessage $amqpMessage) use ($handler, $channel, &$processed): void {
                try {
                     
                    $message = json_decode($amqpMessage->getBody(), true, 512, JSON_THROW_ON_ERROR);
                    $handler($message);
                    $channel->basic_ack($amqpMessage->getDeliveryTag());
                } catch (\Throwable $exception) {
                    $channel->basic_nack($amqpMessage->getDeliveryTag(), false, true);
                    throw $exception;
                }

                $processed++;
            }
        );

        try {
            while ($channel->is_consuming()) {
                $channel->wait();
            }
        } finally {
            $channel->close();
            $connection->close();
        }

        return $processed;
    }

    private function createConnection(): AMQPStreamConnection
    {
        return new AMQPStreamConnection(
            $this->host,
            $this->port,
            $this->user,
            $this->password,
            $this->vhost
        );
    }
}
