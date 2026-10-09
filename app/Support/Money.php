<?php

namespace App\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

final class Money
{
    public const SCALE = 6;

    public static function of(string|int $value, int $scale = self::SCALE): string
    {
        try {
            return (string) BigDecimal::of($value)->toScale($scale, RoundingMode::HalfUp);
        } catch (\Throwable $exception) {
            throw new InvalidArgumentException('Invalid decimal value.', previous: $exception);
        }
    }

    public static function add(string|int $left, string|int $right): string
    {
        return (string) BigDecimal::of($left)->plus($right)->toScale(self::SCALE, RoundingMode::HalfUp);
    }

    public static function subtract(string|int $left, string|int $right): string
    {
        return (string) BigDecimal::of($left)->minus($right)->toScale(self::SCALE, RoundingMode::HalfUp);
    }

    public static function multiply(string|int $left, string|int $right): string
    {
        return (string) BigDecimal::of($left)->multipliedBy($right)->toScale(self::SCALE, RoundingMode::HalfUp);
    }

    public static function divide(string|int $left, string|int $right): string
    {
        return (string) BigDecimal::of($left)->dividedBy($right, self::SCALE, RoundingMode::HalfUp);
    }

    public static function percentage(string|int $amount, string|int $rate): string
    {
        return self::divide(self::multiply($amount, $rate), '100');
    }

    public static function compare(string|int $left, string|int $right): int
    {
        return BigDecimal::of($left)->compareTo($right);
    }

    public static function min(string|int $left, string|int $right): string
    {
        return self::compare($left, $right) <= 0 ? self::of($left) : self::of($right);
    }

    public static function max(string|int $left, string|int $right): string
    {
        return self::compare($left, $right) >= 0 ? self::of($left) : self::of($right);
    }
}
