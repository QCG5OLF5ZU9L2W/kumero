<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Plattform;

/**
 * Kanonische JSON-Form nach RFC 8785 für den in F29 erlaubten Wertebereich
 * (ASCII-Schlüssel, Ganzzahlen < 2^53, Beträge als Text) – gleiche Ausgabe
 * wie kern.jcs() in der Datenbank.
 */
final class Jcs
{
    public static function kodieren(mixed $w): string
    {
        if (is_array($w)) {
            if (array_is_list($w)) {
                return '[' . implode(',', array_map([self::class, 'kodieren'], $w)) . ']';
            }
            $schluessel = array_map('strval', array_keys($w));
            foreach ($schluessel as $k) {
                if (!preg_match('/^[\x20-\x7E]*$/', $k)) {
                    throw new \InvalidArgumentException('JCS: Objektschlüssel müssen ASCII sein');
                }
            }
            sort($schluessel, SORT_STRING);
            $teile = [];
            foreach ($schluessel as $k) {
                $teile[] = self::text($k) . ':' . self::kodieren($w[$k]);
            }
            return '{' . implode(',', $teile) . '}';
        }
        if ($w instanceof \stdClass) {
            return self::kodieren((array) $w) === '[]' ? '{}' : self::kodieren((array) $w);
        }
        if (is_string($w)) {
            return self::text($w);
        }
        if (is_int($w)) {
            if (abs($w) >= 9007199254740992) {
                throw new \InvalidArgumentException('JCS: Ganzzahl zu groß');
            }
            return (string) $w;
        }
        if (is_bool($w)) {
            return $w ? 'true' : 'false';
        }
        if ($w === null) {
            return 'null';
        }
        throw new \InvalidArgumentException('JCS: Typ nicht erlaubt (' . get_debug_type($w) . ')');
    }

    public static function sha256(mixed $w): string
    {
        return hash('sha256', self::kodieren($w));
    }

    private static function text(string $s): string
    {
        return json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
