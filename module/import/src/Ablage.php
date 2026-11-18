<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Modul\Import;

use Doctrine\DBAL\Connection;
use Kumero\Api\V1\Problem;
use Kumero\Plattform\Anfrage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Zwischenablage für das Original einer E-Rechnung (IMPORT.md Abschnitt 16, F110, F112).
 *
 * Das Original (PDF, oder das XML einer XRechnung, wenn das DMS XML annimmt) liegt in der Mandanten-Datenbank, bis feststeht, was aus dem Eintrag wird. Solange gibt es die Vorschau
 * über einen **vorläufigen** Beleglink; in eine Buchung gelangt dieser Link nie (das verhindert der Kern).
 * Wird der Eintrag verworfen oder der Import zurückgezogen, wird der Inhalt gelöscht.
 */
final class Ablage
{
    private const UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    /** Anfang aller vorläufigen Links dieses Mandanten. */
    public static function praefix(Anfrage $a): string
    {
        return $a->konfig->adresse . '/m/' . $a->kontext()->mandant . '/import/dateien/';
    }

    public static function link(Anfrage $a, string $id): string
    {
        return self::praefix($a) . $id;
    }

    public const PDF = 'application/pdf';
    public const XML = 'application/xml';

    /**
     * Legt das Original ab (PDF oder – wenn das DMS es annimmt – das XML einer XRechnung, F112) und liefert den
     * vorläufigen Belegverweis.
     *
     * @return array{link: string, md5: string, sha256: string}
     */
    public static function ablegen(Anfrage $a, string $schluessel, string $name, string $inhalt, string $typ = self::PDF): array
    {
        $id = (string) $a->db()->fetchOne('SELECT import.datei_ablegen(?, ?, ?, ?, ?)',
            [$a->kontext()->subjekt, $schluessel, $name, $typ, base64_encode($inhalt)]);
        return ['link' => self::link($a, $id), 'md5' => md5($inhalt), 'sha256' => hash('sha256', $inhalt)];
    }

    /** Löscht die Inhalte zu den Schlüsseln; liefert die Zahl der gelöschten Dateien. @param list<string> $schluessel */
    public static function loeschen(Connection $db, string $subjekt, array $schluessel, string $grund): int
    {
        $n = 0;
        foreach ($schluessel as $s) {
            $id = $db->fetchOne('SELECT id FROM import.datei WHERE schluessel = ? AND inhalt IS NOT NULL', [$s]);
            if ($id !== false && $db->fetchOne('SELECT import.datei_loeschen(?, ?::uuid, ?)', [$subjekt, $id, $grund])) {
                $n++;
            }
        }
        return $n;
    }

    /** Die Datei zum Link. @return array{id: string, name: string, typ: string, sha256: string, md5: string, inhalt: string} */
    public static function holen(Anfrage $a, string $link): array
    {
        if (!preg_match('~^' . preg_quote(self::praefix($a), '~') . '(' . self::UUID . ')$~', $link, $m)) {
            throw Problem::nichtGefunden('Kein Beleg aus der Zwischenablage');
        }
        $z = $a->db()->fetchAssociative("SELECT id, name, typ, sha256, md5, geloescht_grund, encode(inhalt, 'base64') AS inhalt FROM import.datei WHERE id = ?::uuid", [$m[1]]);
        if ($z === false) {
            throw Problem::nichtGefunden('Die Datei ist in der Zwischenablage nicht bekannt');
        }
        if ($z['inhalt'] === null) {
            throw new Problem(410, 'import.datei_geloescht', match ($z['geloescht_grund']) {
                'verworfen' => 'Die Datei wurde gelöscht, weil der Eintrag verworfen wurde – ohne Buchung wird sie nicht aufbewahrt',
                'zurueckgezogen' => 'Die Datei wurde gelöscht, weil der Import zurückgezogen wurde',
                default => 'Die Datei liegt nicht mehr in kumero – sie wurde an das Belegarchiv übergeben',
            });
        }
        $z['inhalt'] = (string) base64_decode((string) $z['inhalt'], true);
        return $z;
    }

    /** Erste Seite des PDF als Bild (poppler-utils, wie beim Kontenrahmen-Import); nichts bleibt auf der Platte. */
    public static function vorschau(Anfrage $a, string $link): Response
    {
        $d = self::holen($a, $link);
        if ($d['typ'] === self::XML) {
            return self::antwort(self::bildAusXml($d['inhalt']), 'image/svg+xml', 'vorschau.svg');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'kumero-vorschau-');
        if ($tmp === false) {
            throw new Problem(500, 'import.vorschau', 'Für die Vorschau lässt sich keine Arbeitsdatei anlegen');
        }
        try {
            chmod($tmp, 0600);
            file_put_contents($tmp, $d['inhalt']);
            $p = @proc_open(['pdftocairo', '-png', '-singlefile', '-f', '1', '-l', '1', '-scale-to', '900', $tmp, $tmp], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rohre);
            if (!is_resource($p)) {
                throw new Problem(503, 'import.vorschau', 'Für die Vorschau fehlt auf dem Server das Programm pdftocairo (Paket poppler-utils)');
            }
            stream_get_contents($rohre[1]);
            stream_get_contents($rohre[2]);
            $ende = proc_close($p);
            $bild = is_file("$tmp.png") ? (string) file_get_contents("$tmp.png") : '';
            if ($ende !== 0 || $bild === '') {
                throw new Problem(422, 'import.vorschau', $ende === 127
                    ? 'Für die Vorschau fehlt auf dem Server das Programm pdftocairo (Paket poppler-utils)'
                    : 'Aus dem PDF lässt sich kein Vorschaubild erzeugen');
            }
        } finally {
            @unlink($tmp);
            @unlink("$tmp.png");
        }
        return self::antwort($bild, 'image/png', 'vorschau.png');
    }

    public static function original(Anfrage $a, string $link): Response
    {
        $d = self::holen($a, $link);
        return self::antwort($d['inhalt'], $d['typ'], $d['typ'] === self::XML ? 'e-rechnung.xml' : 'e-rechnung.pdf');
    }

    /**
     * Vorschau einer XRechnung (reines XML, F112): Ein XML hat kein Bild. Gezeigt werden die Angaben, die kumero aus der
     * Datei liest – als Blatt (SVG). Die Darstellung der ganzen Rechnung entsteht erst im DMS.
     */
    private static function bildAusXml(string $xml): string
    {
        $x = fn (string $s) => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $kurz = fn (string $s, int $n) => mb_strlen($s) > $n ? mb_substr($s, 0, $n - 1) . '…' : $s;
        try {
            $r = Rechnung::lesen($xml);
            $zeilen = array_slice(Rechnung::anwenden($r, 'eingang')['angaben'], 0, 34);
        } catch (\InvalidArgumentException) {
            $zeilen = [['bezeichnung' => 'Hinweis', 'wert' => 'Die Datei lässt sich nicht als E-Rechnung lesen']];
        }
        $hoehe = 150 + 30 * count($zeilen);
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="900" height="' . max(600, $hoehe) . '" viewBox="0 0 900 ' . max(600, $hoehe) . '" font-family="sans-serif">'
            . '<rect width="100%" height="100%" fill="#ffffff"/>'
            . '<text x="40" y="60" font-size="28" font-weight="bold" fill="#1a1a1a">E-Rechnung (XML)</text>'
            . '<text x="40" y="92" font-size="16" fill="#555555">Angaben aus der Datei – ein XML hat kein Bild; die Darstellung der Rechnung entsteht im Belegarchiv</text>'
            . '<line x1="40" y1="108" x2="860" y2="108" stroke="#999999"/>';
        foreach ($zeilen as $n => $z) {
            $y = 142 + 30 * $n;
            $svg .= '<text x="40" y="' . $y . '" font-size="17" fill="#555555">' . $x($kurz((string) $z['bezeichnung'], 34)) . '</text>'
                . '<text x="350" y="' . $y . '" font-size="17" fill="#1a1a1a">' . $x($kurz((string) $z['wert'], 58)) . '</text>';
        }
        return $svg . '</svg>';
    }

    private static function antwort(string $inhalt, string $typ, string $name): Response
    {
        return new Response($inhalt, 200, ['Content-Type' => $typ, 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => "inline; filename=\"$name\"", 'Content-Security-Policy' => "default-src 'none'; sandbox"]);
    }
}
