<?php

declare(strict_types=1);

namespace Rajdhani\Helpers;

use InvalidArgumentException;

/**
 * ULIDs for the CHAR(26) primary keys in section 8.0.
 *
 * Why not UUIDv4: InnoDB clusters rows on the primary key. Random keys scatter
 * inserts across the whole B-tree, splitting pages and bloating the index. A
 * ULID's first 48 bits are a millisecond timestamp, so keys generated over time
 * sort in roughly insert order and rows append to the end of the tree.
 *
 * Encoding is Crockford base32 — 10 characters of timestamp, 16 of randomness.
 *
 * Monotonicity within a millisecond: when two IDs are generated in the same
 * millisecond, the random component of the second is the first's incremented by
 * one, so ordering holds even at machine speed. This matters because the
 * sortability is the entire reason for choosing ULID.
 */
final class UlidHelper
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
    private const ENCODED_LENGTH = 26;
    private const TIME_LENGTH = 10;
    private const RANDOM_LENGTH = 16;

    private static int $lastTimestamp = 0;

    /** @var int[] indices into ALPHABET */
    private static array $lastRandom = [];

    public static function generate(?int $timestampMs = null): string
    {
        $now = $timestampMs ?? (int) (microtime(true) * 1000);

        if ($now === self::$lastTimestamp && self::$lastRandom !== []) {
            self::incrementRandom();
        } else {
            self::$lastTimestamp = $now;
            self::$lastRandom = self::randomIndices();
        }

        return self::encodeTime($now) . self::encodeRandom();
    }

    public static function isValid(string $ulid): bool
    {
        if (strlen($ulid) !== self::ENCODED_LENGTH) {
            return false;
        }

        // The first character encodes the high bits of the timestamp; anything
        // above '7' overflows the 48-bit time field.
        if (strpos(self::ALPHABET, $ulid[0]) === false || $ulid[0] > '7') {
            return false;
        }

        return strspn($ulid, self::ALPHABET) === self::ENCODED_LENGTH;
    }

    public static function timestampOf(string $ulid): int
    {
        if (!self::isValid($ulid)) {
            throw new InvalidArgumentException("Not a valid ULID: {$ulid}");
        }

        $time = 0;

        for ($i = 0; $i < self::TIME_LENGTH; $i++) {
            $time = $time * 32 + (int) strpos(self::ALPHABET, $ulid[$i]);
        }

        return $time;
    }

    private static function encodeTime(int $ms): string
    {
        $out = '';

        for ($i = self::TIME_LENGTH - 1; $i >= 0; $i--) {
            $out = self::ALPHABET[$ms % 32] . $out;
            $ms = intdiv($ms, 32);
        }

        return $out;
    }

    private static function encodeRandom(): string
    {
        $out = '';

        foreach (self::$lastRandom as $index) {
            $out .= self::ALPHABET[$index];
        }

        return $out;
    }

    /** @return int[] */
    private static function randomIndices(): array
    {
        $indices = [];

        for ($i = 0; $i < self::RANDOM_LENGTH; $i++) {
            $indices[] = random_int(0, 31);
        }

        return $indices;
    }

    private static function incrementRandom(): void
    {
        for ($i = self::RANDOM_LENGTH - 1; $i >= 0; $i--) {
            if (self::$lastRandom[$i] < 31) {
                self::$lastRandom[$i]++;

                return;
            }

            self::$lastRandom[$i] = 0;
        }

        // All 80 random bits were 1s in the same millisecond. Astronomically
        // unlikely; reseed rather than silently wrap into a duplicate.
        self::$lastRandom = self::randomIndices();
    }
}
