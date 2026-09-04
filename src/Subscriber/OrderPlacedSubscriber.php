<?php declare(strict_types=1);

/**
 * The moment an order becomes real.
 *
 * CheckoutOrderPlacedEvent is dispatched once, after the order has been
 * written, by the one route that turns a cart into an order. That is the
 * difference between it and listening for orders being saved: an order is
 * saved again every time somebody corrects an address, and a customer messaged
 * for that would rightly wonder what the shop was doing.
 *
 * The event lives in Checkout\Cart\Event, not in Checkout\Order\Event. The
 * order namespace reads like the right home for it and does not contain it in
 * either 6.5 or 6.6, and getting this wrong fails silently rather than loudly:
 * getSubscribedEvents() returns a class-string that is never autoloaded, so
 * Shopware registers the listener against a name it never dispatches and every
 * message this plugin owes a customer at checkout simply never happens. Do not
 * "tidy" this import to match the class's own namespace.
 *
 * It runs inside the customer's checkout request, so it must do almost
 * nothing. It hands the order id to the Notifier, which puts it on a queue.
 */

namespace Upeo\Njiwa\Subscriber;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Upeo\Njiwa\Service\Config;
use Upeo\Njiwa\Service\Notifier;

class OrderPlacedSubscriber implements EventSubscriberInterface
{
    private Notifier $notifier;

    private LoggerInterface $logger;

    public function __construct(Notifier $notifier, LoggerInterface $logger)
    {
        $this->notifier = $notifier;
        $this->logger = $logger;
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutOrderPlacedEvent::class => 'onOrderPlaced',
        ];
    }

    public function onOrderPlaced(CheckoutOrderPlacedEvent $event): void
    {
        // This runs inside the customer's checkout. Whatever goes wrong in
        // here, the order has already been written and the customer is owed
        // their confirmation page, so nothing is allowed to escape into the
        // request that placed it.
        try {
            $order = $event->getOrder();

            $this->notifier->orderEvent(
                $order->getId(),
                $order->getSalesChannelId(),
                Config::EVENT_PLACED
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Could not handle the order placed event for WhatsApp: ' . $e->getMessage(),
                ['exception' => $e]
            );
        }
    }
}
