<?php declare(strict_types=1);

/**
 * The wording, and what it does with what the order gives it.
 *
 * These run without a Shopware kernel on purpose. Templates knows nothing
 * about orders — OrderValues hands it a finished list of replacements — and
 * that split is what makes the part a merchant actually edits testable in a
 * second rather than in a test shop.
 */

namespace Upeo\Njiwa\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Upeo\Njiwa\Service\Config;
use Upeo\Njiwa\Service\Templates;

class TemplatesTest extends TestCase
{
    /**
     * @var array<string, string>
     */
    private const VALUES = [
        '{first_name}' => 'Amina',
        '{last_name}' => 'Otieno',
        '{customer_name}' => 'Amina Otieno',
        '{order_number}' => '10042',
        '{order_total}' => 'KSh 4,500.00',
        '{order_date}' => '4 Sep 2026, 14:02',
        '{order_status}' => 'In progress',
        '{payment_method}' => 'Cash on delivery',
        '{items}' => "2 x Blue shirt\n1 x Leather belt",
        '{item_count}' => '3',
        '{shop_name}' => 'Duka la Mavazi',
        '{order_url}' => 'https://shop.example/account/order/abc123',
        '{admin_url}' => 'https://shop.example/admin#/sw/order/detail/0189',
    ];

    public function testEveryPlaceholderIsSubstituted(): void
    {
        $template = implode(' ', array_keys(self::VALUES));

        $message = $this->templates()->render($template, self::VALUES);

        foreach (self::VALUES as $value) {
            static::assertStringContainsString($value, $message);
        }
        static::assertStringNotContainsString('{', $message, 'nothing in braces should survive rendering');
    }

    public function testAnEmptyTemplateSendsNothing(): void
    {
        // Clearing the box is how a merchant turns one message off without
        // turning the event off, so this is an instruction rather than a bug.
        static::assertSame('', $this->templates()->render('', self::VALUES));
        static::assertSame('', $this->templates()->render("  \n ", self::VALUES));
    }

    public function testAPlaceholderThatDoesNotExistIsRemovedAndReported(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(static::once())
            ->method('warning')
            ->with(static::stringContains('{order_no}'));

        $templates = new Templates($logger);

        // Sending "{order_no}" to a customer looks broken. It comes out, and
        // the shop is told where to look rather than left to wonder.
        $message = $templates->render('Order {order_no} for {first_name}', self::VALUES);

        static::assertSame('Order  for Amina', $message);
    }

    public function testAPlaceholderWithNothingBehindItLeavesNoHole(): void
    {
        $values = self::VALUES;
        $values['{items}'] = '';

        $message = $this->templates()->render("Thanks {first_name}.\n\n{items}\n\nTotal {order_total}", $values);

        static::assertSame("Thanks Amina.\n\nTotal KSh 4,500.00", $message);
    }

    public function testOrderDataContainingBracesIsLeftAlone(): void
    {
        // A product genuinely called "Set {red}" must not have its own braces
        // stripped out of the customer's order, and must not make this warn
        // about a placeholder nobody wrote.
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(static::never())->method('warning');

        $values = self::VALUES;
        $values['{items}'] = '1 x Set {red}';

        $message = (new Templates($logger))->render('You ordered {items}', $values);

        static::assertSame('You ordered 1 x Set {red}', $message);
    }

    public function testAVeryLongMessageIsCutToSomethingWhatsAppWillTake(): void
    {
        $values = self::VALUES;
        $values['{items}'] = str_repeat('1 x Blue shirt ', 1000);

        $message = $this->templates()->render('{items}', $values);

        static::assertSame(Templates::MAX_LENGTH, mb_strlen($message));
        static::assertStringEndsWith("\u{2026}", $message);
    }

    /**
     * @dataProvider eventProvider
     */
    public function testEveryEventShipsWordingThatWorksUnedited(string $event): void
    {
        // Turning an event on has to be one click, not one click and a writing
        // exercise, so every event has to have something to say out of the box.
        $template = Templates::defaultFor($event);
        static::assertNotSame('', $template, $event . ' has no default wording');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(static::never())
            ->method('warning');

        $message = (new Templates($logger))->render($template, self::VALUES);

        static::assertNotSame('', $message);
        static::assertStringNotContainsString('{', $message);
    }

    /**
     * The wording a merchant reads in the settings form and the wording a shop
     * that has never saved that form actually sends have to be the same words.
     * They are written in two files, so something has to check.
     *
     * @dataProvider eventProvider
     */
    public function testTheSettingsFormShipsTheSameWordingAsTheCode(string $event): void
    {
        if (!\function_exists('simplexml_load_file')) {
            static::markTestSkipped('SimpleXML is not installed.');
        }

        $config = simplexml_load_file(__DIR__ . '/../src/Resources/config/config.xml');
        static::assertNotFalse($config, 'config.xml could not be read');

        $found = null;
        foreach ($config->card as $card) {
            foreach ($card->{'input-field'} as $field) {
                if ((string) $field->name === $event . 'Template') {
                    $found = (string) $field->defaultValue;
                }
            }
        }

        static::assertNotNull($found, 'config.xml has no wording for ' . $event);
        static::assertSame(Templates::defaultFor($event), $found);
    }

    /**
     * @return array<int, array{0: string}>
     */
    public static function eventProvider(): array
    {
        $events = array_merge(Config::CUSTOMER_EVENTS, [Config::EVENT_ALERT]);

        return array_map(static fn (string $event): array => [$event], $events);
    }

    public function testEveryPlaceholderTheFormPromisesIsOneTheCodeFills(): void
    {
        // The settings form lists these, so a placeholder that is documented
        // and not filled is a hole a merchant finds on a customer's phone.
        static::assertSame(
            array_keys(Templates::placeholders()),
            array_keys(self::VALUES),
            'placeholders() and the values a real order supplies have drifted apart'
        );
    }

    private function templates(): Templates
    {
        return new Templates($this->createMock(LoggerInterface::class));
    }
}
