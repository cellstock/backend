<?php

namespace App\Support;

/**
 * GSM IMEI (15 digits, Luhn check on positions 2,4,6… from the left).
 * Normalizes common scanner output before pushing to Refurbed.
 */
class Imei
{
    public static function hasValidChecksum(string $value): bool
    {
        $imei = trim($value);

        if (! preg_match('/^\d{15}$/', $imei)) {
            return false;
        }

        $sum = 0;

        for ($i = 0; $i < 15; $i++) {
            $digit = (int) $imei[$i];

            if ($i % 2 === 1) {
                $digit *= 2;

                if ($digit > 9) {
                    $digit -= 9;
                }
            }

            $sum += $digit;
        }

        return $sum % 10 === 0;
    }

    /**
     * Best-effort 15-digit IMEI for Refurbed (fixes 14-digit scans, 16-digit suffix, wrong check digit).
     */
    public static function formatForRefurbed(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';

        if ($digits === '') {
            return '';
        }

        if (strlen($digits) === 15 && self::hasValidChecksum($digits)) {
            return $digits;
        }

        if (strlen($digits) === 14) {
            $candidate = self::appendCheckDigit($digits);

            if (self::hasValidChecksum($candidate)) {
                return $candidate;
            }
        }

        if (strlen($digits) === 15) {
            $fromBody = self::appendCheckDigit(substr($digits, 0, 14));

            if (self::hasValidChecksum($fromBody)) {
                return $fromBody;
            }
        }

        if (strlen($digits) === 16) {
            $first = substr($digits, 0, 15);
            $last = substr($digits, 1, 15);

            if (self::hasValidChecksum($first)) {
                return $first;
            }

            if (self::hasValidChecksum($last)) {
                return $last;
            }
        }

        $length = strlen($digits);

        if ($length > 16) {
            for ($start = 0; $start <= $length - 15; $start++) {
                $candidate = substr($digits, $start, 15);

                if (self::hasValidChecksum($candidate)) {
                    return $candidate;
                }
            }

            for ($start = 0; $start <= $length - 14; $start++) {
                $chunk = substr($digits, $start, 14);

                if (! preg_match('/^\d{14}$/', $chunk)) {
                    continue;
                }

                $candidate = self::appendCheckDigit($chunk);

                if (self::hasValidChecksum($candidate)) {
                    return $candidate;
                }
            }
        }

        return strlen($digits) >= 15 ? substr($digits, 0, 15) : $digits;
    }

    private static function appendCheckDigit(string $imei14): string
    {
        $sum = 0;

        for ($i = 0; $i < 14; $i++) {
            $digit = (int) $imei14[$i];

            if ($i % 2 === 1) {
                $digit *= 2;

                if ($digit > 9) {
                    $digit -= 9;
                }
            }

            $sum += $digit;
        }

        $check = (10 - ($sum % 10)) % 10;

        return $imei14.(string) $check;
    }
}
