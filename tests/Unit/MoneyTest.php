<?php

namespace Tests\Unit;

use App\Support\Money\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_normalises_to_four_decimal_strings(): void
    {
        $this->assertSame('12.5000', Money::of('12.5')->amount);
        $this->assertSame('100.0000', Money::of(100)->amount);
        $this->assertSame('0.0000', Money::of('-0')->amount);
        $this->assertSame(['amount' => '1500.0000', 'currency' => 'NGN'], Money::of('1500')->toArray());
    }

    public function test_rejects_floats_and_excess_precision(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::of(1.5);
    }

    public function test_rejects_more_than_four_decimals(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::of('1.00001');
    }

    public function test_arithmetic_is_exact(): void
    {
        $this->assertSame('0.3000', Money::of('0.1')->add(Money::of('0.2'))->amount); // 0.1+0.2 in floats != 0.3
        $this->assertSame('9.9000', Money::of('10')->sub(Money::of('0.1'))->amount);
        $this->assertSame('7.5000', Money::of('100')->percent('7.5')->amount);
        $this->assertSame('33.3333', Money::of('100')->mul('0.33333333')->amount);
        $this->assertSame('0.1000', Money::of('0.05')->mul('2')->amount);
    }

    public function test_rounding_is_half_away_from_zero(): void
    {
        $this->assertSame('1.0001', Money::roundHalfUp('1.00005', 4));
        $this->assertSame('-1.0001', Money::roundHalfUp('-1.00005', 4));
        $this->assertSame('2', Money::roundHalfUp('1.5', 0));
    }

    public function test_currency_mismatch_and_compare(): void
    {
        $this->assertTrue(Money::of('5')->compare(Money::of('4.9999')) > 0);
        $this->assertTrue(Money::of('5')->equals(Money::of('5.0000')));
        $this->expectException(InvalidArgumentException::class);
        Money::of('1', 'NGN')->add(Money::of('1', 'USD'));
    }

    public function test_validation_helper(): void
    {
        $this->assertTrue(Money::isValid('10.25'));
        $this->assertFalse(Money::isValid(10.25));
        $this->assertFalse(Money::isValid('10.12345'));
        $this->assertFalse(Money::isValid('abc'));
    }
}
