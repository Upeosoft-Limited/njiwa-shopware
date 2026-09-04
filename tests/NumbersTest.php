<?php declare(strict_types=1);

/**
 * What people type into a phone field, and what has to come out.
 *
 * Every case here is one somebody actually typed. The group address is the one
 * that matters most: if it ever gets through, a single order could message
 * every member of a WhatsApp group from the shop's own number.
 */

namespace Upeo\Njiwa\Tests;

use PHPUnit\Framework\TestCase;
use Upeo\Njiwa\Service\Numbers;

class NumbersTest extends TestCase
{
    private Numbers $numbers;

    protected function setUp(): void
    {
        $this->numbers = new Numbers();
    }

    /**
     * @dataProvider numbersProvider
     */
    public function testToMsisdn(?string $typed, string $expected, string $because): void
    {
        static::assertSame($expected, $this->numbers->toMsisdn($typed), $because);
    }

    /**
     * @return array<int, array{0: string|null, 1: string, 2: string}>
     */
    public static function numbersProvider(): array
    {
        return [
            ['254712345678', '254712345678', 'a number already in full international form is left alone'],
            ['+254712345678', '254712345678', 'the plus is not part of the number'],
            ['+254 712 345 678', '254712345678', 'spaces are how people read a number out loud'],
            ['(071) 234-5678', '0712345678', 'brackets and dashes are punctuation, not digits'],
            ['00254712345678', '254712345678', '00 is how much of the world dials out and means the same as a plus'],
            ['0712345678', '0712345678', 'a leading zero is kept: Njiwa reads a recipient against the sending number country'],
            ['  254712345678  ', '254712345678', 'whitespace round the edges is not a reason to refuse somebody'],

            [null, '', 'a customer with no phone number is normal, not an error'],
            ['', '', 'an empty field is not a number'],
            ['not a phone number', '', 'letters alone leave nothing to dial'],
            ['12345', '', 'too short to be a telephone number anywhere'],
            ['12345678901234567890', '', 'longer than any real number, so it is something else'],

            ['120363042@g.us', '', 'a WhatsApp group address is not a phone number and must never be messaged'],
            ['254712345678@g.us', '', 'a group address that also contains a real number is still a group'],
            ['someone@example.com', '', 'an email address in the phone field is a mistake, not a recipient'],
        ];
    }

    public function testParseListTakesCommasSemicolonsAndNewlines(): void
    {
        $parsed = $this->numbers->parseList("254712345678, 254733000111;\n254700111222");

        static::assertSame(['254712345678', '254733000111', '254700111222'], $parsed);
    }

    public function testParseListDropsDuplicates(): void
    {
        // The same person typed twice is one person, and they need one copy.
        $parsed = $this->numbers->parseList('254712345678, 254712345678');

        static::assertSame(['254712345678'], $parsed);
    }

    public function testParseListDropsAnythingThatIsNotANumber(): void
    {
        $parsed = $this->numbers->parseList('254712345678, me, 120363042@g.us, 123');

        static::assertSame(['254712345678'], $parsed);
    }

    public function testParseListOfNothingIsEmpty(): void
    {
        static::assertSame([], $this->numbers->parseList(''));
        static::assertSame([], $this->numbers->parseList(null));
    }
}
