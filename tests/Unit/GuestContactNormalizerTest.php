<?php

namespace Tests\Unit;

use App\Domain\Guest\Support\ContactNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GuestContactNormalizerTest extends TestCase
{
    /** @return array<string, array{0: string, 1: ?string}> */
    public static function phones(): array
    {
        return [
            'local 0803' => ['08031234567', '+2348031234567'],
            'local with spaces' => ['0803 123 4567', '+2348031234567'],
            'local with dashes' => ['0803-123-4567', '+2348031234567'],
            '234 prefix' => ['2348031234567', '+2348031234567'],
            'plus 234' => ['+234 803 123 4567', '+2348031234567'],
            '00234' => ['002348031234567', '+2348031234567'],
            'plus 2340 typo' => ['+2340803 123 4567', '+2348031234567'],
            'no leading zero' => ['8031234567', '+2348031234567'],
            'brackets' => ['(0803) 123 4567', '+2348031234567'],
            'international UK' => ['+44 7700 900123', '+447700900123'],
            'international US' => ['+1 (415) 555-2671', '+14155552671'],
            'too short' => ['0803123', null],
            'too long' => ['080312345678', null],
            'letters' => ['0803abc4567', null],
            'junk' => ['not a phone', null],
            'empty' => ['', null],
            'ng landline-like prefix 0' => ['0123456789', null],
            'international without plus' => ['447700900123', null],
            'plus too short' => ['+12345', null],
        ];
    }

    #[DataProvider('phones')]
    public function test_phone(string $in, ?string $out): void
    {
        $this->assertSame($out, ContactNormalizer::phone($in));
    }

    public function test_international_numbers_can_be_switched_off(): void
    {
        $this->assertNull(ContactNormalizer::phone('+447700900123', false));
        $this->assertSame('+2348031234567', ContactNormalizer::phone('+2348031234567', false));
    }

    public function test_email_is_lowercased_trimmed_and_validated(): void
    {
        $this->assertSame('ada@example.com', ContactNormalizer::email('  Ada@Example.COM '));
        $this->assertNull(ContactNormalizer::email('ada@'));
        $this->assertNull(ContactNormalizer::email('not an email'));
        $this->assertNull(ContactNormalizer::email(str_repeat('a', 250).'@x.co'));
        $this->assertNotNull(ContactNormalizer::email('ada@no-mx-domain.invalid'), 'MX is not checked');
    }

    public function test_name(): void
    {
        $this->assertSame('Ada Lovelace', ContactNormalizer::name("  Ada   Lovelace \n"));
        $this->assertNull(ContactNormalizer::name('A'));
        $this->assertNull(ContactNormalizer::name('12345'));
        $this->assertNull(ContactNormalizer::name(str_repeat('a', 121)));
        $this->assertSame('Chukwuemeka Okafor-Ìbrahim', ContactNormalizer::name('Chukwuemeka Okafor-Ìbrahim'));
    }
}
