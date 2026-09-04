<?php declare(strict_types=1);

/**
 * One row per message this plugin has decided to send.
 *
 * This is the marker that outlives everything else. The Idempotency-Key sent
 * with each message stops Njiwa delivering the same thing twice inside
 * twenty-four hours; this table is what stops the plugin asking a second time
 * a week later, when a delivery is transitioned back and forth to correct a
 * tracking code, or when somebody replays an order.
 *
 * The unique key is the whole point of the table. The row is written before
 * the message is handed to Njiwa, so a second attempt at the same order, event
 * and recipient collides with it and stops there — including two workers that
 * happen to pick up the same order at the same moment, which is a race no
 * amount of checking-then-writing in PHP can win.
 *
 * There is no foreign key to `order`. An order's primary key in Shopware is
 * the pair (id, version_id), so a foreign key would have to name a version,
 * and this marker has to mean the same thing across every version of an order.
 * The order id on its own is stable, and that is what is stored.
 */

namespace Upeo\Njiwa\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1756944000CreateNjiwaMessageTable extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1756944000;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `upeo_njiwa_message` (
                `id`               BINARY(16)  NOT NULL,
                `order_id`         BINARY(16)  NULL,
                `order_number`     VARCHAR(64) NULL,
                `sales_channel_id` BINARY(16)  NULL,
                `event`            VARCHAR(32) NOT NULL,
                `recipient`        VARCHAR(32) NOT NULL,
                `status`           VARCHAR(16) NOT NULL DEFAULT \'queued\',
                `njiwa_id`         VARCHAR(64) NULL,
                `detail`           VARCHAR(255) NULL,
                `created_at`       DATETIME(3) NOT NULL,
                `updated_at`       DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq.upeo_njiwa_message.claim` (`order_id`, `event`, `recipient`),
                KEY `idx.upeo_njiwa_message.created_at` (`created_at`)
            ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
        ');

        // A test message has no order, so its order_id is null. MySQL treats
        // nulls in a unique key as distinct from one another, which is exactly
        // what is wanted: two test messages to the same number are two things
        // the operator asked for, not a duplicate.
    }

    public function updateDestructive(Connection $connection): void
    {
        // Nothing. Dropping this table is what a clean uninstall is for.
    }
}
