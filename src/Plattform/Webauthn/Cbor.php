<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Plattform\Webauthn;

/**
 * Minimaler CBOR-Decoder (RFC 8949) für WebAuthn: Ganzzahlen, Byte- und
 * Textketten, Arrays, Maps, false/true/null. Nur definite Längen; Gleitkomma-
 * zahlen und Tags werden abgewiesen – WebAuthn braucht sie nicht.
 */
final class Cbor
{
    /** Dekodiert genau einen Wert ab $pos; $pos zeigt danach hinter den Wert. */
    public static function lesen(string $daten, int &$pos = 0, int $tiefe = 0): mixed
    {
        if ($tiefe > 16) {
            throw new WebauthnFehler('CBOR zu tief verschachtelt');
        }
        $kopf = self::byte($daten, $pos);
        $typ = $kopf >> 5;
        $info = $kopf & 0x1f;
        $wert = self::laenge($daten, $pos, $info);

        switch ($typ) {
            case 0:
                return $wert;
            case 1:
                return -1 - $wert;
            case 2:
            case 3:
                if ($pos + $wert > strlen($daten)) {
                    throw new WebauthnFehler('CBOR: Zeichenkette über das Ende hinaus');
                }
                $s = substr($daten, $pos, $wert);
                $pos += $wert;
                return $s;
            case 4:
                $liste = [];
                for ($i = 0; $i < $wert; $i++) {
                    $liste[] = self::lesen($daten, $pos, $tiefe + 1);
                }
                return $liste;
            case 5:
                $map = [];
                for ($i = 0; $i < $wert; $i++) {
                    $schluessel = self::lesen($daten, $pos, $tiefe + 1);
                    if (!is_int($schluessel) && !is_string($schluessel)) {
                        throw new WebauthnFehler('CBOR: unzulässiger Map-Schlüssel');
                    }
                    if (array_key_exists($schluessel, $map)) {
                        throw new WebauthnFehler('CBOR: doppelter Map-Schlüssel');
                    }
                    $map[$schluessel] = self::lesen($daten, $pos, $tiefe + 1);
                }
                return $map;
            case 7:
                return match ($info) {
                    20 => false,
                    21 => true,
                    22 => null,
                    default => throw new WebauthnFehler('CBOR: Gleitkomma und einfache Werte nicht unterstützt'),
                };
            default:
                throw new WebauthnFehler('CBOR: Tags nicht unterstützt');
        }
    }

    /** Dekodiert $daten vollständig; Reste am Ende sind ein Fehler. */
    public static function dekodieren(string $daten): mixed
    {
        $pos = 0;
        $wert = self::lesen($daten, $pos);
        if ($pos !== strlen($daten)) {
            throw new WebauthnFehler('CBOR: überzählige Bytes');
        }
        return $wert;
    }

    private static function byte(string $daten, int &$pos): int
    {
        if ($pos >= strlen($daten)) {
            throw new WebauthnFehler('CBOR: unerwartetes Ende');
        }
        return ord($daten[$pos++]);
    }

    private static function laenge(string $daten, int &$pos, int $info): int
    {
        if ($info < 24) {
            return $info;
        }
        $bytes = match ($info) {
            24 => 1, 25 => 2, 26 => 4, 27 => 8,
            default => throw new WebauthnFehler('CBOR: indefinite Längen nicht unterstützt'),
        };
        if ($pos + $bytes > strlen($daten)) {
            throw new WebauthnFehler('CBOR: unerwartetes Ende');
        }
        $wert = 0;
        for ($i = 0; $i < $bytes; $i++) {
            $wert = ($wert << 8) | ord($daten[$pos++]);
        }
        if ($wert < 0) {
            throw new WebauthnFehler('CBOR: Wert zu groß');
        }
        return $wert;
    }
}
