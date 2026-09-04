<?php declare(strict_types=1);

/**
 * Who hears about what.
 *
 * Every subscriber ends up here, and everything here runs inside somebody's
 * checkout or inside an administrator's save. So it does the least it can: two
 * settings lookups, which Shopware serves from a cache, and a dispatch onto
 * the queue. It reads no orders, renders no wording and opens no sockets.
 *
 * Nothing it does is allowed to fail upwards. An order must never fail to be
 * placed, and a status must never fail to change, because a WhatsApp message
 * could not be arranged.
 */

namespace Upeo\Njiwa\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Upeo\Njiwa\MessageQueue\SendOrderMessage;

class Notifier
{
    private Config $config;

    private MessageBusInterface $bus;

    private LoggerInterface $logger;

    public function __construct(Config $config, MessageBusInterface $bus, LoggerInterface $logger)
    {
        $this->config = $config;
        $this->bus = $bus;
        $this->logger = $logger;
    }

    /**
     * Something has happened to an order. Work out what, if anything, that
     * means for WhatsApp.
     */
    public function orderEvent(string $orderId, ?string $salesChannelId, string $event): void
    {
        try {
            if (!$this->config->isEnabled($salesChannelId) || !$this->config->isConfigured($salesChannelId)) {
                return;
            }

            if (\in_array($event, Config::CUSTOMER_EVENTS, true) && $this->config->isEventOn($event, $salesChannelId)) {
                $this->bus->dispatch(new SendOrderMessage($orderId, $event));
            }

            // The shop owner hears once, when the order is placed. In Shopware
            // that is the first moment an order is real: what exists before it
            // is a cart, and a cart is somebody who reached the payment page
            // and may never come back.
            //
            // It is dispatched as its own message rather than folded into the
            // one above so that the two cannot interfere: different wording,
            // different recipients, and a customer who hears nothing because
            // the "order placed" message is switched off must not also cost
            // the shop its own alert.
            if ($event === Config::EVENT_PLACED && $this->config->isEventOn(Config::EVENT_ALERT, $salesChannelId)) {
                $this->bus->dispatch(new SendOrderMessage($orderId, Config::EVENT_ALERT));
            }
        } catch (\Throwable $e) {
            $this->logger->error(sprintf(
                'Could not queue the "%s" WhatsApp message for order %s: %s',
                $event,
                $orderId,
                $e->getMessage()
            ), ['exception' => $e]);
        }
    }
}
