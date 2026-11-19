<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Modul\Siegel;

use Kumero\Api\V1\Problem;

/**
 * OpenPGP nur lesend und prüfend (SIEGEL.md Abschnitt 5): einen öffentlichen Schlüssel auslesen und eine abgesetzte
 * Signatur gegen den Siegelinhalt prüfen. Gearbeitet wird mit `gpg --show-keys` und `gpgv` in einem Wegwerfverzeichnis –
 * ohne Schlüsselbund des Servers, ohne Agent, ohne Netz. Signiert wird hier nie.
 */
final class Pgp
{
    public const SCHLUESSEL_HOECHSTENS = 65536;
    public const SIGNATUR_HOECHSTENS = 16384;
    /** Hashverfahren nach RFC 9580: SHA-256, -384, -512, SHA3-256, SHA3-512 */
    private const HASH_ERLAUBT = ['8', '9', '10', '12', '14'];
    private const ALGORITHMEN = ['1' => 'RSA', '17' => 'DSA', '19' => 'ECDSA', '22' => 'EdDSA', '27' => 'Ed25519', '28' => 'Ed448'];

    /**
     * Liest einen öffentlichen Schlüssel (ASCII-Hülle) aus.
     *
     * @return array{fpr: string, kennung: string, algorithmus: string, oeffentlich: string}
     */
    public static function schluesselLesen(string $text): array
    {
        $text = self::huelle($text, 'PGP PUBLIC KEY BLOCK', self::SCHLUESSEL_HOECHSTENS, 'schluessel');
        return self::imVerzeichnis(function (string $v) use ($text): array {
            file_put_contents("$v/schluessel.asc", $text);
            [$code, $aus] = self::lauf(['gpg', '--batch', '--no-tty', '--homedir', $v, '--no-autostart', '--with-colons', '--show-keys', "$v/schluessel.asc"]);
            $pub = [];
            $fpr = null;
            $kennung = null;
            $geheim = false;
            foreach (explode("\n", $aus) as $zeile) {
                $f = explode(':', $zeile);
                if ($f[0] === 'sec' || $f[0] === 'ssb') {
                    $geheim = true;
                } elseif ($f[0] === 'pub') {
                    $pub[] = $f;
                } elseif ($f[0] === 'fpr' && count($pub) === 1 && $fpr === null) {
                    $fpr = $f[9] ?? null;
                } elseif ($f[0] === 'uid' && count($pub) === 1 && $kennung === null && !in_array($f[1] ?? '', ['r', 'e'], true)) {
                    $kennung = self::entmaskiert($f[9] ?? '');
                }
            }
            if ($geheim) {
                throw new Problem(422, 'siegel.privater_schluessel', 'Das ist ein privater Schlüssel. Er wird nicht gespeichert – hochgeladen wird nur der öffentliche Schlüssel.');
            }
            if ($code !== 0 || count($pub) !== 1 || !is_string($fpr) || !preg_match('/^[0-9A-F]{40,64}$/', $fpr)) {
                throw new Problem(422, 'siegel.schluessel_unlesbar', 'Erwartet wird genau ein öffentlicher OpenPGP-Schlüssel');
            }
            $gueltigkeit = $pub[0][1] ?? '';
            if (in_array($gueltigkeit, ['r', 'e', 'i', 'd'], true)) {
                throw new Problem(422, 'siegel.schluessel_ungueltig', $gueltigkeit === 'e' ? 'Der Schlüssel ist abgelaufen' : 'Der Schlüssel ist widerrufen oder ungültig');
            }
            if (!str_contains(strtolower($pub[0][11] ?? '') . self::unterschluesselFaehigkeiten($aus), 's')) {
                throw new Problem(422, 'siegel.schluessel_signiert_nicht', 'Mit diesem Schlüssel lässt sich nicht signieren');
            }
            if ($kennung === null || trim($kennung) === '') {
                throw new Problem(422, 'siegel.schluessel_unlesbar', 'Der Schlüssel trägt keine gültige Kennung (User-ID)');
            }
            $alg = (self::ALGORITHMEN[$pub[0][3] ?? ''] ?? 'Verfahren ' . ($pub[0][3] ?? '?'))
                . (($pub[0][16] ?? '') !== '' ? ' ' . $pub[0][16] : (($pub[0][2] ?? '') !== '' ? ' ' . $pub[0][2] : ''));
            return ['fpr' => $fpr, 'kennung' => mb_substr(trim($kennung), 0, 300), 'algorithmus' => mb_substr($alg, 0, 40), 'oeffentlich' => $text];
        });
    }

    /**
     * Prüft eine abgesetzte Signatur über genau die Bytes von $inhalt gegen die angegebenen öffentlichen Schlüssel.
     *
     * @param list<string> $schluessel öffentliche Schlüssel in ASCII-Hülle
     * @return array{fpr: string, signiert_am: string} Fingerabdruck des Hauptschlüssels, Zeitangabe der Signatur (UTC, RFC 3339)
     */
    public static function pruefen(array $schluessel, string $inhalt, string $signatur): array
    {
        $signatur = self::huelle($signatur, 'PGP SIGNATURE', self::SIGNATUR_HOECHSTENS, 'signatur');
        if ($schluessel === []) {
            throw new Problem(409, 'siegel.schluessel_unbekannt', 'In diesem Mandanten ist noch kein Schlüssel angemeldet');
        }
        return self::imVerzeichnis(function (string $v) use ($schluessel, $inhalt, $signatur): array {
            $befehl = ['gpgv', '--homedir', $v, '--status-fd', '1'];
            foreach ($schluessel as $i => $s) {
                file_put_contents("$v/s$i.gpg", self::binaer($s));
                array_push($befehl, '--keyring', "$v/s$i.gpg");
            }
            file_put_contents("$v/inhalt", $inhalt);
            file_put_contents("$v/inhalt.asc", $signatur);
            [, $aus] = self::lauf([...$befehl, "$v/inhalt.asc", "$v/inhalt"]);
            $status = [];
            foreach (explode("\n", $aus) as $zeile) {
                if (str_starts_with($zeile, '[GNUPG:] ')) {
                    $f = explode(' ', substr($zeile, 9));
                    $status[$f[0]] ??= array_slice($f, 1);
                }
            }
            if (isset($status['BADSIG'])) {
                throw new Problem(422, 'siegel.signatur_ungueltig', 'Die Signatur passt nicht zu diesem Siegelinhalt. Signiert werden muss genau die heruntergeladene Datei, unverändert.');
            }
            if (isset($status['NO_PUBKEY']) || isset($status['ERRSIG'])) {
                throw new Problem(409, 'siegel.schluessel_unbekannt', 'Der Schlüssel dieser Signatur ist in diesem Mandanten nicht angemeldet');
            }
            if (isset($status['EXPKEYSIG']) || isset($status['REVKEYSIG']) || isset($status['EXPSIG'])) {
                throw new Problem(422, 'siegel.schluessel_ungueltig', 'Der Schlüssel oder die Signatur ist abgelaufen oder widerrufen');
            }
            $g = $status['VALIDSIG'] ?? null;
            if (!isset($status['GOODSIG']) || $g === null || count($g) < 10) {
                throw new Problem(422, 'siegel.signatur_unlesbar', 'Erwartet wird eine abgesetzte OpenPGP-Signatur (gpg --armor --detach-sign)');
            }
            if (!in_array($g[7], self::HASH_ERLAUBT, true)) {
                throw new Problem(422, 'siegel.signatur_schwach', 'Die Signatur nutzt ein veraltetes Hashverfahren; erwartet wird SHA-256 oder stärker');
            }
            if (!in_array($g[8], ['00', '01'], true)) {
                throw new Problem(422, 'siegel.signatur_unlesbar', 'Erwartet wird eine Signatur über die Datei');
            }
            $zeit = ctype_digit($g[2]) ? (new \DateTimeImmutable('@' . $g[2])) : new \DateTimeImmutable($g[2], new \DateTimeZone('UTC'));
            return ['fpr' => $g[9], 'signiert_am' => $zeit->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z')];
        });
    }

    /** Fähigkeiten der Unterschlüssel (Feld 12 der sub-Zeilen), damit ein Schlüssel mit Signier-Unterschlüssel gilt. */
    private static function unterschluesselFaehigkeiten(string $aus): string
    {
        $erg = '';
        foreach (explode("\n", $aus) as $zeile) {
            $f = explode(':', $zeile);
            if ($f[0] === 'sub' && !in_array($f[1] ?? '', ['r', 'e', 'i', 'd'], true)) {
                $erg .= strtolower($f[11] ?? '');
            }
        }
        return $erg;
    }

    /** Prüft die ASCII-Hülle und liefert sie mit einheitlichen Zeilenenden zurück. */
    private static function huelle(string $text, string $art, int $hoechstens, string $feld): string
    {
        $text = trim(str_replace("\r", '', $text));
        if (str_contains($text, 'PRIVATE KEY')) {
            throw new Problem(422, 'siegel.privater_schluessel', 'Das ist ein privater Schlüssel. Er wird nicht gespeichert – hochgeladen wird nur der öffentliche Schlüssel.');
        }
        if (strlen($text) > $hoechstens || !preg_match('/\A-----BEGIN ' . $art . '-----\n[A-Za-z0-9+\/=:. \n-]*\n-----END ' . $art . '-----\z/', $text)) {
            throw Problem::ungueltig('Eingabe ungültig', [['feld' => $feld, 'meldung' => "Erwartet wird ein Block „-----BEGIN $art-----“"]]);
        }
        return $text . "\n";
    }

    /** ASCII-Hülle → Binärform (für gpgv --keyring). */
    private static function binaer(string $text): string
    {
        $zeilen = explode("\n", trim($text));
        $koerper = '';
        $imKopf = true;
        foreach (array_slice($zeilen, 1, -1) as $z) {
            if ($imKopf) {
                // Kopfzeilen der Hülle enden mit einer Leerzeile
                if (trim($z) === '') {
                    $imKopf = false;
                } elseif (!str_contains($z, ': ')) {
                    $imKopf = false;
                    $koerper .= trim($z);
                }
                continue;
            }
            if (!str_starts_with($z, '=')) {      // Prüfsumme der Hülle
                $koerper .= trim($z);
            }
        }
        $b = base64_decode($koerper, true);
        if ($b === false || $b === '') {
            throw new Problem(422, 'siegel.schluessel_unlesbar', 'Der Schlüssel ist nicht lesbar');
        }
        return $b;
    }

    /** gpg maskiert in der Doppelpunkt-Ausgabe Sonderzeichen als \xNN. */
    private static function entmaskiert(string $s): string
    {
        $s = (string) preg_replace_callback('/\\\\x([0-9a-fA-F]{2})/', fn ($m) => chr((int) hexdec($m[1])), $s);
        return mb_check_encoding($s, 'UTF-8') ? (string) preg_replace('/[\x00-\x1f\x7f]/', '', $s) : '';
    }

    /** @template T @param callable(string): T $f @return T */
    private static function imVerzeichnis(callable $f): mixed
    {
        $v = sys_get_temp_dir() . '/kumero-siegel-' . bin2hex(random_bytes(8));
        if (!mkdir($v, 0700)) {
            throw new \RuntimeException('Arbeitsverzeichnis für die Signaturprüfung lässt sich nicht anlegen');
        }
        try {
            return $f($v);
        } finally {
            self::entfernen($v);
        }
    }

    private static function entfernen(string $v): void
    {
        foreach (scandir($v) ?: [] as $n) {
            if ($n === '.' || $n === '..') {
                continue;
            }
            is_dir("$v/$n") && !is_link("$v/$n") ? self::entfernen("$v/$n") : @unlink("$v/$n");
        }
        @rmdir($v);
    }

    /** @param list<string> $befehl @return array{0: int, 1: string} Rückgabewert und Standardausgabe */
    private static function lauf(array $befehl): array
    {
        $p = @proc_open($befehl, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $rohre, null,
            ['PATH' => '/usr/local/bin:/usr/bin:/bin', 'LC_ALL' => 'C']);
        if (!is_resource($p)) {
            throw new Problem(503, 'siegel.pruefung_fehlt', "Auf dem Server fehlt das Programm {$befehl[0]}");
        }
        $aus = (string) stream_get_contents($rohre[1]);
        fclose($rohre[1]);
        $code = proc_close($p);
        if ($code === 127) {
            throw new Problem(503, 'siegel.pruefung_fehlt', "Auf dem Server fehlt das Programm {$befehl[0]}");
        }
        return [$code, $aus];
    }
}
