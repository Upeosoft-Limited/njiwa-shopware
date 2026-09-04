<?php declare(strict_types=1);

/**
 * Every setting this plugin has, read at the scope the order belongs to.
 *
 * One Shopware installation commonly runs several sales channels, each with
 * its own name, currency and language, and there is no reason a merchant
 * should not give each of them its own wording or even its own Njiwa account.
 * Nothing here reads a setting without a sales channel id, so a message is
 * always composed with the settings of the channel the order was placed in
 * rather than with whatever the fallback happens to be.
 *
 * The keys match src/Resources/config/config.xml exactly. A setting read from
 * a key nothing writes to is a setting that is always at its default and
 * nobody can tell why.
 */

namespace Upeo\Njiwa\Service;

use Shopware\Core\System\SystemConfig\SystemConfigService;

class Config
{
    public const DEFAULT_BASE_URL = 'https://njiwa.upeo.ai';

    /** Everything this plugin stores sits under this prefix in system_config. */
    private const PREFIX = 'UpeoNjiwa.config.';

    /**
     * The five moments a customer hears about. The name of each one is half of
     * its configuration key and the whole of what is written in the "event"
     * column of upeo_njiwa_message, so these strings are not free to change.
     */
    public const EVENT_PLACED = 'placed';
    public const EVENT_PAID = 'paid';
    public const EVENT_SHIPPED = 'shipped';
    public const EVENT_CANCELLED = 'cancelled';
    public const EVENT_REFUNDED = 'refunded';

    /** The one message that goes to the shop rather than to the customer. */
    public const EVENT_ALERT = 'alert';

    public const CUSTOMER_EVENTS = [
        self::EVENT_PLACED,
        self::EVENT_PAID,
        self::EVENT_SHIPPED,
        self::EVENT_CANCELLED,
        self::EVENT_REFUNDED,
    ];

    private SystemConfigService $systemConfig;

    public function __construct(SystemConfigService $systemConfig)
    {
        $this->systemConfig = $systemConfig;
    }

    /**
     * The master switch. Off keeps every other setting and sends nothing.
     *
     * It defaults to on because config.xml ships it on: a merchant who has
     * installed this plugin and turned an event on has already said yes twice,
     * and being asked a third time reads as a bug.
     */
    public function isEnabled(?string $salesChannelId = null): bool
    {
        $value = $this->systemConfig->get(self::PREFIX . 'enabled', $salesChannelId);

        return $value === null ? true : (bool) $value;
    }

    public function apiKey(?string $salesChannelId = null): string
    {
        return trim((string) $this->systemConfig->getString(self::PREFIX . 'apiKey', $salesChannelId));
    }

    public function isConfigured(?string $salesChannelId = null): bool
    {
        return $this->apiKey($salesChannelId) !== '';
    }

    /**
     * A test key checks and stores every message and delivers nothing, which
     * is what a shop wants while it is setting this up. Several messages say
     * so out loud, because a merchant who thinks they have sent a real message
     * and has not is a merchant who trusts nothing else this plugin says.
     */
    public function isTestKey(?string $salesChannelId = null): bool
    {
        return str_starts_with($this->apiKey($salesChannelId), 'sk_test_');
    }

    public function baseUrl(?string $salesChannelId = null): string
    {
        $url = trim((string) $this->systemConfig->getString(self::PREFIX . 'baseUrl', $salesChannelId));

        return rtrim($url === '' ? self::DEFAULT_BASE_URL : $url, '/');
    }

    /**
     * Which of the account's own numbers sends. Empty means the one marked
     * default in the console, which is the right answer for a shop with one
     * number that never thinks about this again.
     *
     * Digits only: unlike a recipient, the sending number is never read
     * against anybody's country, so a leading zero here is genuinely
     * ambiguous and would be refused by Njiwa rather than guessed at.
     */
    public function sendFrom(?string $salesChannelId = null): string
    {
        $raw = (string) $this->systemConfig->getString(self::PREFIX . 'sendFrom', $salesChannelId);

        return (string) preg_replace('/\D/', '', $raw);
    }

    public function isEventOn(string $event, ?string $salesChannelId = null): bool
    {
        // Every event is off until somebody turns it on. Installing a plugin
        // must never cause a message to be sent, so an unset value is a no
        // rather than a yes.
        return (bool) $this->systemConfig->get(self::PREFIX . $event . 'Enabled', $salesChannelId);
    }

    /**
     * The wording for one event.
     *
     * There are three states here and they mean different things. A key that
     * has never been written falls back to the wording in Templates, so a shop
     * that ticked an event and never opened the box still has something to
     * send. A key holding an empty string is a merchant who cleared the box on
     * purpose, and that sends nothing. Anything else is what they typed.
     */
    public function template(string $event, ?string $salesChannelId = null): string
    {
        $value = $this->systemConfig->get(self::PREFIX . $event . 'Template', $salesChannelId);

        if ($value === null) {
            return Templates::defaultFor($event);
        }

        return trim((string) $value);
    }

    /** Where the new-order alert goes, exactly as the shop owner typed it. */
    public function alertNumbers(?string $salesChannelId = null): string
    {
        return (string) $this->systemConfig->getString(self::PREFIX . 'alertNumbers', $salesChannelId);
    }
}
