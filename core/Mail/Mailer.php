<?php

declare(strict_types=1);

namespace Core\Mail;

use Core\Queue\QueueManager;

class Mailer
{
    /**
     * @var array<string, MailerDriverInterface>
     */
    protected array $drivers = [];

    public function __construct(
        protected MailerDriverInterface $defaultDriver,
        protected ?QueueManager $queue = null
    ) {
        $this->drivers['default'] = $defaultDriver;
    }

    public function setDriver(string $name, MailerDriverInterface $driver): self
    {
        $this->drivers[$name] = $driver;
        return $this;
    }

    public function driver(string $name = 'default'): MailerDriverInterface
    {
        return $this->drivers[$name] ?? $this->defaultDriver;
    }

    /**
     * Send an email immediately using the active driver.
     */
    public function send(Message|array $message, string $driverName = 'default'): bool
    {
        $msg = $message instanceof Message ? $message : Message::fromArray($message);
        return $this->driver($driverName)->send($msg);
    }

    /**
     * Queue an email to be sent asynchronously via the QueueManager.
     */
    public function queue(Message|array $message, string $queue = 'emails'): void
    {
        $msg = $message instanceof Message ? $message : Message::fromArray($message);

        if ($this->queue !== null) {
            $this->queue->push(SendMailJob::class, [
                'message' => $msg->toArray(),
            ], null, $queue);
        } else {
            // Fallback to immediate delivery if queue manager is not bound
            $this->send($msg);
        }
    }
}
