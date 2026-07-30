<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * formatMac() is the front door of the whole boot chain: every endpoint
 * identifies its caller by MAC, and an earlier version returned garbage such
 * as "zz:zz" for non-hex input instead of rejecting it.
 */
final class MacAddressTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function separatorProvider(): array
    {
        return [
            'colons'      => ['00:0c:29:91:cf:eb', '00:0c:29:91:cf:eb'],
            'dashes'      => ['00-0C-29-91-CF-EB', '00:0c:29:91:cf:eb'],
            'dots'        => ['000c.2991.cfeb', '00:0c:29:91:cf:eb'],
            'spaces'      => ['00 0c 29 91 cf eb', '00:0c:29:91:cf:eb'],
            'bare'        => ['000c2991cfeb', '00:0c:29:91:cf:eb'],
            'mixed case'  => ['00:0C:29:91:cF:eB', '00:0c:29:91:cf:eb'],
            'padded'      => ['  00:0c:29:91:cf:eb  ', '00:0c:29:91:cf:eb'],
        ];
    }

    #[DataProvider('separatorProvider')]
    public function testNormalisesAnySeparatorToLowerCaseColons(string $input, string $expected): void
    {
        self::assertSame($expected, formatMac($input));
    }

    /** @return array<string, array{mixed}> */
    public static function invalidProvider(): array
    {
        return [
            'empty'          => [''],
            'non-hex'        => ['zz:zz:zz:zz:zz:zz'],
            'too short'      => ['00:0c:29:91:cf'],
            'too long'       => ['00:0c:29:91:cf:eb:00'],
            'words'          => ['not a mac address'],
            'null'           => [null],
            'digits only'    => ['123'],
        ];
    }

    /**
     * The empty string is the contract for "invalid"; callers test for it.
     */
    #[DataProvider('invalidProvider')]
    public function testReturnsEmptyStringForInvalidInput(mixed $input): void
    {
        self::assertSame('', formatMac($input));
        self::assertFalse(isValidMac($input));
    }

    public function testValidMacIsAccepted(): void
    {
        self::assertTrue(isValidMac('00:0c:29:91:cf:eb'));
    }
}
