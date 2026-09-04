<?php declare(strict_types=1);

/**
 * The message itself.
 *
 * A template is plain text with placeholders in braces. Every placeholder a
 * shop can use is listed in placeholders() below, and that same list is what
 * the settings form prints, so what a merchant reads there cannot drift from
 * what this class replaces.
 *
 * Nothing in here knows what an order is. It is handed a finished set of
 * values by OrderValues, which is the only class that has to understand
 * Shopware, and that split is what makes the wording testable without a shop.
 */

namespace Upeo\Njiwa\Service;

use Psr\Log\LoggerInterface;

class Templates
{
    /** WhatsApp takes 4096 characters. Stopping short leaves room to spare. */
    public const MAX_LENGTH = 4000;

    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Placeholder => what it is replaced with, in the shop's own words.
     *
     * @return array<string, string>
     */
    public static function placeholders(): array
    {
        return [
            '{first_name}' => 'The customer\'s first name, or "there" if the order has none.',
            '{last_name}' => 'The customer\'s last name.',
            '{customer_name}' => 'Both names together.',
            '{order_number}' => 'The order number as the customer sees it.',
            '{order_total}' => 'The total, in the currency the order was placed in.',
            '{order_date}' => 'The date the order was placed.',
            '{order_status}' => 'The status the order is in at that moment.',
            '{payment_method}' => 'How they paid, as it is named in your payment settings.',
            '{items}' => 'One line per item, as "2 x Blue shirt".',
            '{item_count}' => 'How many items in total.',
            '{shop_name}' => 'The name of the sales channel the order was placed in.',
            '{order_url}' => 'A link the customer can open to see their own order.',
            '{admin_url}' => 'A link that opens the order in the administration. Only put this in the message to yourself.',
        ];
    }

    /**
     * What each message says before anybody edits it.
     *
     * These live in the code as well as in config.xml because the two are
     * needed in different places: config.xml fills the box a merchant looks
     * at, and this fills the gap for a shop that has saved that form exactly
     * zero times. The two must stay identical, and config.xml says so too.
     *
     * They are deliberately short. A WhatsApp message that reads like an email
     * gets read like an email, which is to say not at all.
     */
    public static function defaultFor(string $event): string
    {
        $defaults = [
            Config::EVENT_PLACED => "Hi {first_name}, we have your order {order_number} for {order_total}. We will let you know the moment your payment comes through.\n\n{shop_name}",
            Config::EVENT_PAID => "Hi {first_name}, thank you. Your payment for order {order_number} came through and we are getting it ready.\n\n{items}\n\nTotal {order_total}\n{shop_name}",
            Config::EVENT_SHIPPED => "Hi {first_name}, order {order_number} is on its way to you. Thank you for shopping with {shop_name}.",
            Config::EVENT_CANCELLED => "Hi {first_name}, order {order_number} has been cancelled. If that was not you, reply to this message and we will look into it.\n\n{shop_name}",
            Config::EVENT_REFUNDED => "Hi {first_name}, we have refunded {order_total} for order {order_number}. Banks take a few days to show it.\n\n{shop_name}",
            Config::EVENT_ALERT => "New order {order_number} on {shop_name}.\n\n{customer_name}\n{item_count} item(s), {order_total}\nPaid by {payment_method}\n\n{admin_url}",
        ];

        return $defaults[$event] ?? '';
    }

    /**
     * @param array<string, string> $values Placeholder => replacement, as
     *                                      OrderValues built them.
     *
     * @return string The message, or '' when the template is empty.
     */
    public function render(string $template, array $values, string $orderNumber = ''): string
    {
        $template = trim($template);
        if ($template === '') {
            // Clearing the box is how a merchant turns one message off without
            // turning the event off, so an empty template is an instruction
            // rather than a mistake.
            return '';
        }

        // Anything in braces that is not a placeholder does not exist, and is
        // usually a typo. Sending "{order_no}" to a customer looks broken, so
        // it comes out and the shop is told where to look.
        //
        // This is done to the template before anything is substituted into it,
        // never afterwards. A product called "Set {red}" would otherwise put
        // braces into the finished message and have them stripped out of the
        // customer's own order, along with a warning about a placeholder
        // nobody wrote.
        if (preg_match_all('/\{[a-z_]+\}/', $template, $found)) {
            $unknown = array_values(array_unique(array_diff($found[0], array_keys($values))));

            if ($unknown !== []) {
                $this->logger->warning(sprintf(
                    'Unknown placeholder %s in the wording%s. It was removed before sending.',
                    implode(', ', $unknown),
                    $orderNumber === '' ? '' : ' for order ' . $orderNumber
                ));
                $template = str_replace($unknown, '', $template);
            }
        }

        $message = strtr($template, $values);

        // A placeholder that resolved to nothing — an order with no items, a
        // customer with no surname — leaves a hole where a blank line used to
        // be. Closing it is the difference between a message that looks
        // written and one that looks generated.
        $message = trim((string) preg_replace("/\n{3,}/", "\n\n", $message));

        if (mb_strlen($message) > self::MAX_LENGTH) {
            $message = mb_substr($message, 0, self::MAX_LENGTH - 1) . "\u{2026}";
        }

        return $message;
    }
}
