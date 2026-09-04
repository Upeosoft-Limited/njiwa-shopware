<?php declare(strict_types=1);

/**
 * The record of what this plugin has already decided to send.
 *
 * Shopware has no order note stream to write to, so this table is where the
 * answer to "did the customer ever get told?" lives, and it is also the lock
 * that stops the same customer being told twice. Both jobs are done by the one
 * unique key on (order_id, event, recipient): the row is claimed before
 * anything is sent, and a second attempt loses the race rather than sending.
 *
 * It talks to the database directly rather than through the data abstraction
 * layer. This is bookkeeping that belongs to the plugin, nothing outside it
 * ever needs to search or write it, and a claim has to be a single insert that
 * either succeeds or collides — which is a plain unique-key insert, not a
 * read-then-write.
 */

namespace Upeo\Njiwa\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;

class MessageLog
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';

    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * Claim the right to send one message.
     *
     * @return string|null The claim's own id, or null when somebody has
     *                     already claimed this order, event and recipient —
     *                     which is the answer to "has this been sent before",
     *                     and means the caller must send nothing.
     */
    public function claim(
        ?string $orderId,
        ?string $orderNumber,
        ?string $salesChannelId,
        string $event,
        string $recipient
    ): ?string {
        $id = Uuid::randomHex();

        try {
            $this->connection->insert('upeo_njiwa_message', [
                'id' => Uuid::fromHexToBytes($id),
                'order_id' => $orderId === null ? null : Uuid::fromHexToBytes($orderId),
                'order_number' => $orderNumber,
                'sales_channel_id' => $salesChannelId === null ? null : Uuid::fromHexToBytes($salesChannelId),
                'event' => $event,
                'recipient' => $recipient,
                'status' => self::STATUS_QUEUED,
                'created_at' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            ]);
        } catch (UniqueConstraintViolationException $e) {
            return null;
        }

        return $id;
    }

    /**
     * Give a claim back, so that a later attempt can take it.
     *
     * Used when the send failed in a way that is worth retrying. Leaving the
     * row behind would mean the retry looked like a duplicate and the customer
     * heard nothing at all, which is the worse of the two failures.
     */
    public function release(string $id): void
    {
        $this->connection->delete('upeo_njiwa_message', ['id' => Uuid::fromHexToBytes($id)]);
    }

    public function markSent(string $id, ?string $njiwaId, ?string $detail = null): void
    {
        $this->finish($id, self::STATUS_SENT, $njiwaId, $detail);
    }

    public function markFailed(string $id, string $detail): void
    {
        $this->finish($id, self::STATUS_FAILED, null, $detail);
    }

    /**
     * How many messages of one kind have been claimed since a moment.
     *
     * This is what rate limits the "send test message" button. It reuses the
     * table already being written rather than adding a second place where
     * state about sending lives.
     */
    public function countSince(string $event, \DateTimeImmutable $since): int
    {
        $count = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM `upeo_njiwa_message` WHERE `event` = :event AND `created_at` >= :since',
            [
                'event' => $event,
                'since' => $since->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            ]
        );

        return (int) $count;
    }

    private function finish(string $id, string $status, ?string $njiwaId, ?string $detail): void
    {
        $this->connection->update(
            'upeo_njiwa_message',
            [
                'status' => $status,
                'njiwa_id' => $njiwaId,
                // The column is 255 characters and a stack of nested exception
                // messages is longer than that more often than not.
                'detail' => $detail === null ? null : mb_substr($detail, 0, 255),
                'updated_at' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            ],
            ['id' => Uuid::fromHexToBytes($id)]
        );
    }
}
