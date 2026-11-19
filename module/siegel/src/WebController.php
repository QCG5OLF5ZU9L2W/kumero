<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Modul\Siegel;

use Kumero\Api\V1\Problem;
use Kumero\Api\V1\Siegel;
use Kumero\Http\Antwort;
use Kumero\Plattform\Anfrage;
use Symfony\Component\HttpFoundation\Response;

/**
 * REST des Moduls siegel (SIEGEL.md Abschnitt 6). Der Ablauf: Siegel anfordern → Siegelinhalt herunterladen →
 * außerhalb signieren → Signatur hochladen. kumero prüft die Signatur gegen genau den gespeicherten Inhalt und die
 * im Mandanten angemeldeten öffentlichen Schlüssel und trägt sie erst dann ein.
 */
final class WebController
{
    private const FPR = '/^[0-9A-F]{40,64}$/';

    public function liste(Anfrage $a): Response
    {
        return Antwort::json(Siegel::uebersicht($a));
    }

    public function anfordern(Anfrage $a): Response
    {
        self::leer($a);
        return Antwort::json(Siegel::anfordern($a), 201);
    }

    /** Der Siegelinhalt als Datei – genau die Bytes, die zu signieren sind. */
    public function inhalt(Anfrage $a): Response
    {
        $s = self::siegel($a);
        return self::datei($s['inhalt'], 'application/json', self::dateiname($a, (int) $s['nr']) . '.json');
    }

    /** Signatur hochladen: prüfen gegen Inhalt und angemeldete Schlüssel, dann eintragen. */
    public function signieren(Anfrage $a): Response
    {
        $j = $a->json();
        if (array_diff(array_keys($j), ['signatur']) !== [] || !is_string($j['signatur'] ?? null)) {
            throw Problem::ungueltig('Eingabe ungültig', [['feld' => 'signatur', 'meldung' => 'Pflichtfeld: die abgesetzte Signatur als Text']]);
        }
        $s = self::siegel($a);
        // auch zurückgezogene Schlüssel laden: Die Datenbank nennt dann den Grund der Ablehnung
        $p = Pgp::pruefen(Siegel::schluesselTexte($a, 'pgp'), $s['inhalt'], $j['signatur']);
        Siegel::signaturEintragen($a, $s['id'], $p['fpr'], 'pgp', trim(str_replace("\r", '', $j['signatur'])) . "\n", $p['signiert_am']);
        return Antwort::json(['siegel' => $s['id'], 'schluessel' => $p['fpr'], 'signiert_am' => $p['signiert_am']], 201);
    }

    /** Eine Signatur als Datei, damit Dritte die Prüfung wiederholen können. */
    public function signatur(Anfrage $a): Response
    {
        $s = self::siegel($a);
        $t = Siegel::signaturText($a, $s['id'], self::fpr($a));
        if ($t === null) {
            throw Problem::nichtGefunden('Signatur unbekannt');
        }
        return self::datei((string) $t, 'application/pgp-signature', self::dateiname($a, (int) $s['nr']) . '.json.' . substr(self::fpr($a), -8) . '.asc');
    }

    public function schluesselAnmelden(Anfrage $a): Response
    {
        $j = $a->json();
        if (array_diff(array_keys($j), ['schluessel']) !== [] || !is_string($j['schluessel'] ?? null)) {
            throw Problem::ungueltig('Eingabe ungültig', [['feld' => 'schluessel', 'meldung' => 'Pflichtfeld: der öffentliche Schlüssel als Text']]);
        }
        $k = Pgp::schluesselLesen($j['schluessel']);
        Siegel::schluesselAnmelden($a, 'pgp', $k['fpr'], $k['kennung'], $k['algorithmus'], $k['oeffentlich']);
        return Antwort::json(['fpr' => $k['fpr'], 'kennung' => $k['kennung'], 'algorithmus' => $k['algorithmus']], 201);
    }

    /** Der öffentliche Schlüssel als Datei. */
    public function schluessel(Anfrage $a): Response
    {
        $t = Siegel::schluesselText($a, self::fpr($a));
        if ($t === null) {
            throw Problem::nichtGefunden('Schlüssel unbekannt');
        }
        return self::datei((string) $t, 'application/pgp-keys', 'kumero-siegelschluessel-' . substr(self::fpr($a), -16) . '.asc');
    }

    public function schluesselZurueckziehen(Anfrage $a): Response
    {
        $j = $a->json();
        $grund = $j['grund'] ?? null;
        if (array_diff(array_keys($j), ['grund']) !== [] || !is_string($grund) || trim($grund) === '' || mb_strlen($grund) > 300) {
            throw Problem::ungueltig('Eingabe ungültig', [['feld' => 'grund', 'meldung' => 'Pflichtfeld, höchstens 300 Zeichen']]);
        }
        if (Siegel::schluesselText($a, self::fpr($a)) === null) {
            throw Problem::nichtGefunden('Schlüssel unbekannt');
        }
        Siegel::schluesselZurueckziehen($a, self::fpr($a), trim($grund));
        return Antwort::leer();
    }

    // --- Hilfen -----------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private static function siegel(Anfrage $a): array
    {
        return Siegel::lesen($a, $a->param('id')) ?? throw Problem::nichtGefunden('Siegel unbekannt');
    }

    private static function fpr(Anfrage $a): string
    {
        $f = $a->param('fpr');
        return preg_match(self::FPR, $f) ? $f : throw Problem::nichtGefunden('Schlüssel unbekannt');
    }

    private static function leer(Anfrage $a): void
    {
        if ($a->json(false) !== []) {
            throw Problem::ungueltig('Dieser Aufruf nimmt keine Angaben');
        }
    }

    private static function dateiname(Anfrage $a, int $nr): string
    {
        return sprintf('kumero-siegel-%s-%04d', $a->kontext()->mandant, $nr);
    }

    private static function datei(string $inhalt, string $typ, string $name): Response
    {
        return new Response($inhalt, 200, ['Content-Type' => $typ, 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => "attachment; filename=\"$name\"", 'Content-Security-Policy' => "default-src 'none'; sandbox"]);
    }

}
