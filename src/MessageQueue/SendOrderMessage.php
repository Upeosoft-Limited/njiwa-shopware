<?php declare(strict_types=1);

/**
 * "Somebody should be told about this order."
 *
 * It carries an id and an event name and nothing else. The wording, the
 * recipient and the order itself are all worked out by the handler, off the
 * request, because the point of this message is that the customer standing at
 * the checkout waits for none of it.
 *
 * AsyncMessageInterface is what puts it on Shopware's async transport rather
 * than running it inline. Without it, a message dispatched during checkout is
 * handled during checkout, which is exactly what must not happen.
 */

namespace Upeo\Njiwa\MessageQueue;

use Shopware\Core\Framework\MessageQueue\AsyncMessageInterface;

class SendOrderMessage implements AsyncMessageInterface
{
    private string $orderId;

    private string $event;

    public function __construct(string $orderId, string $event)
    {
        $this->orderId = $orderId;
        $this->event = $event;
    }

    public function getOrderId(): string
    {
        return $this->orderId;
    }

    public function getEvent(): string
    {
        return $this->event;
    }
}
