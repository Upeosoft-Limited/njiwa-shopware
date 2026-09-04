<?php declare(strict_types=1);

/**
 * Everything a message can say about one order, read once.
 *
 * This is the only class in the plugin that has to understand Shopware's order
 * model, which is why the renderer next door knows nothing about it: the
 * wording can then be tested without a database, a sales channel or a kernel.
 *
 * Every value comes back as a string, and a value the order cannot supply
 * comes back empty rather than as "null" or "0.00". A customer reading a hole
 * where their name should be is a bad message; a customer reading the word
 * "null" is a broken one.
 */

namespace Upeo\Njiwa\Service;

use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\Currency\CurrencyFormatter;

class OrderValues
{
    /** How many order lines {items} prints before it starts counting instead. */
    private const MAX_ITEMS = 10;

    /**
     * A promotion is a line item like any other in Shopware's model, but
     * "1 x Summer sale" in a list of what somebody bought reads as a mistake.
     * The constant is Shopware\Core\Checkout\Cart\LineItem\LineItem::
     * PROMOTION_LINE_ITEM_TYPE; it is written out here so that this class does
     * not drag the cart into a worker that has no cart.
     */
    private const PROMOTION_LINE_ITEM = 'promotion';

    private CurrencyFormatter $currencyFormatter;

    private Numbers $numbers;

    public function __construct(CurrencyFormatter $currencyFormatter, Numbers $numbers)
    {
        $this->currencyFormatter = $currencyFormatter;
        $this->numbers = $numbers;
    }

    /**
     * @return array<string, string>
     */
    public function forOrder(OrderEntity $order, Context $context): array
    {
        [$first, $last] = $this->name($order);

        return [
            '{first_name}' => $first !== '' ? $first : 'there',
            '{last_name}' => $last,
            '{customer_name}' => trim($first . ' ' . $last),
            '{order_number}' => (string) $order->getOrderNumber(),
            '{order_total}' => $this->total($order, $context),
            '{order_date}' => $this->placedOn($order),
            '{order_status}' => $this->status($order),
            '{payment_method}' => $this->paymentMethod($order),
            '{items}' => $this->items($order),
            '{item_count}' => (string) $this->itemCount($order),
            '{shop_name}' => (string) ($order->getSalesChannel() ? $order->getSalesChannel()->getName() : ''),
            '{order_url}' => $this->orderUrl($order),
            '{admin_url}' => $this->adminUrl($order),
        ];
    }

    /**
     * The number to message about this order.
     *
     * The billing address first, because that is the number a shop rings about
     * a payment. The delivery address second, because a gift order often has
     * the only reachable number there.
     */
    public function phoneNumber(OrderEntity $order): string
    {
        $billing = $order->getBillingAddress();
        if ($billing !== null) {
            $number = $this->numbers->toMsisdn($billing->getPhoneNumber());
            if ($number !== '') {
                return $number;
            }
        }

        $deliveries = $order->getDeliveries();
        if ($deliveries !== null) {
            foreach ($deliveries as $delivery) {
                $address = $delivery->getShippingOrderAddress();
                if ($address === null) {
                    continue;
                }
                $number = $this->numbers->toMsisdn($address->getPhoneNumber());
                if ($number !== '') {
                    return $number;
                }
            }
        }

        return '';
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function name(OrderEntity $order): array
    {
        $customer = $order->getOrderCustomer();
        $first = trim((string) ($customer !== null ? $customer->getFirstName() : ''));
        $last = trim((string) ($customer !== null ? $customer->getLastName() : ''));

        // An order placed from the administration can have an order_customer
        // row with nothing in it. The billing address is then the only name
        // the shop actually has.
        if ($first === '' && $last === '') {
            $billing = $order->getBillingAddress();
            if ($billing !== null) {
                $first = trim((string) $billing->getFirstName());
                $last = trim((string) $billing->getLastName());
            }
        }

        return [$first, $last];
    }

    /**
     * The total in the currency the customer was charged in, written the way
     * that currency is written in the language the order was placed in, rather
     * than the way whoever is running the worker would write it.
     */
    private function total(OrderEntity $order, Context $context): string
    {
        $currency = $order->getCurrency();
        $iso = $currency !== null ? $currency->getIsoCode() : null;

        if ($iso === null) {
            // The currency association was not loaded, or the currency has
            // since been deleted. A bare number is worth more than nothing.
            return number_format($order->getAmountTotal(), 2);
        }

        try {
            return trim($this->currencyFormatter->formatCurrencyByLanguage(
                $order->getAmountTotal(),
                $iso,
                $order->getLanguageId(),
                $context
            ));
        } catch (\Throwable $e) {
            // A missing locale should cost the shop a currency symbol, not the
            // whole message.
            return $iso . ' ' . number_format($order->getAmountTotal(), 2);
        }
    }

    /**
     * Shopware keeps every date in UTC and has no per-shop timezone setting —
     * the administration converts to the browser's. A worker has no browser,
     * so this follows the timezone PHP is configured with, which on a normal
     * Shopware server is the one the shop thinks in.
     */
    private function placedOn(OrderEntity $order): string
    {
        $placedAt = $order->getOrderDateTime();

        try {
            return (new \DateTimeImmutable('@' . $placedAt->getTimestamp()))
                ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
                ->format('j M Y, H:i');
        } catch (\Throwable $e) {
            return $placedAt->format('j M Y, H:i');
        }
    }

    /**
     * The name a merchant would recognise from the order list, translated, and
     * the technical name only when the translation is not loaded — "in_progress"
     * on a customer's phone is worse than nothing but better than blank.
     */
    private function status(OrderEntity $order): string
    {
        $state = $order->getStateMachineState();
        if ($state === null) {
            return '';
        }

        $name = (string) $state->getName();

        return $name !== '' ? $name : $state->getTechnicalName();
    }

    /**
     * An order can have several transactions when a first payment failed and
     * the customer tried again, so the last one is the one that describes how
     * this order was actually paid.
     */
    private function paymentMethod(OrderEntity $order): string
    {
        $transactions = $order->getTransactions();
        if ($transactions === null || $transactions->count() === 0) {
            return '';
        }

        $transaction = $transactions->last();
        if ($transaction === null || $transaction->getPaymentMethod() === null) {
            return '';
        }

        return (string) $transaction->getPaymentMethod()->getName();
    }

    private function items(OrderEntity $order): string
    {
        $lines = [];
        $more = 0;

        foreach ($this->goods($order) as $item) {
            if (count($lines) >= self::MAX_ITEMS) {
                ++$more;
                continue;
            }
            $lines[] = sprintf('%d x %s', $item->getQuantity(), (string) $item->getLabel());
        }

        if ($more > 0) {
            $lines[] = sprintf('and %d more', $more);
        }

        return implode("\n", $lines);
    }

    private function itemCount(OrderEntity $order): int
    {
        $count = 0;
        foreach ($this->goods($order) as $item) {
            $count += $item->getQuantity();
        }

        return $count;
    }

    /**
     * @return OrderLineItemEntity[]
     */
    private function goods(OrderEntity $order): array
    {
        $lineItems = $order->getLineItems();
        if ($lineItems === null) {
            return [];
        }

        $goods = [];
        foreach ($lineItems as $item) {
            if ($item->getType() === self::PROMOTION_LINE_ITEM) {
                continue;
            }
            $goods[] = $item;
        }

        return $goods;
    }

    /**
     * The customer's own view of their order.
     *
     * This is the address Shopware's own order confirmation email builds, deep
     * link code and all, so a customer who follows it sees exactly what that
     * email would have shown them and a guest is not asked to log in.
     *
     * A sales channel with no domain is a headless one being driven by an app,
     * where there is no page to link to. That is a missing value, not an
     * error, and the placeholder resolves to nothing.
     */
    private function orderUrl(OrderEntity $order): string
    {
        $salesChannel = $order->getSalesChannel();
        $deepLinkCode = (string) $order->getDeepLinkCode();

        if ($salesChannel === null || $deepLinkCode === '') {
            return '';
        }

        $domains = $salesChannel->getDomains();
        if ($domains === null || $domains->count() === 0) {
            return '';
        }

        $chosen = null;
        foreach ($domains as $domain) {
            // A shop with one domain per language must send the customer to
            // the one their own order was placed in, or they land on a page in
            // a language they never chose.
            if ($domain->getLanguageId() === $order->getLanguageId()) {
                $chosen = $domain;
                break;
            }
            $chosen = $chosen ?? $domain;
        }

        return $chosen === null ? '' : rtrim($chosen->getUrl(), '/') . '/account/order/' . $deepLinkCode;
    }

    /**
     * The order in the administration, for the message the shop sends itself.
     *
     * APP_URL is the address Shopware itself builds administration links from,
     * so it is the one honest answer available to a worker that has no
     * request. A shop that has moved the administration off /admin should take
     * {admin_url} out of its wording rather than trust a guess.
     */
    private function adminUrl(OrderEntity $order): string
    {
        $appUrl = trim((string) ($_ENV['APP_URL'] ?? $_SERVER['APP_URL'] ?? ''));
        if ($appUrl === '') {
            return '';
        }

        return rtrim($appUrl, '/') . '/admin#/sw/order/detail/' . $order->getId();
    }
}
