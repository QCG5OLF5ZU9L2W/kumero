<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Api\V1;

use Kumero\Plattform\Anfrage;

/**
 * Siegel im Kern des Mandanten (SIEGEL.md Abschnitt 6, F120) – der Zugang für Module (F44): Ein Modul liest und schreibt
 * Siegel, Signaturen und öffentliche Schlüssel nur hierüber. Die Signatur selbst prüft der Aufrufer, bevor er sie einträgt.
 *
 * Seit 0.7.28 (F124): Anfordern legt nur eine vorläufige Anforderung an. Ein Siegel entsteht erst mit
 * {@see festschreiben()} – nach geprüfter Signatur und Bestätigung, in einer Transaktion.
 */
final class Siegel
{
    /** Stand der Ketten, Siegel mit Signaturen, öffentliche Schlüssel, Namen für die Anzeige. @return array<string, mixed> */
    public static function uebersicht(Anfrage $a): array
    {
        $db = $a->db();
        $kopf = [];
        foreach ($db->fetchAllAssociative('SELECT kette, letzte_nr, letzter_hash FROM kern.kettenkopf ORDER BY kette') as $k) {
            $kopf[(int) $k['kette'] === 1 ? 'buchungen' : 'protokoll'] = ['nr' => (int) $k['letzte_nr'], 'hash' => $k['letzter_hash']];
        }
        $signaturen = [];
        foreach ($db->fetchAllAssociative(
            "SELECT g.siegel, g.schluessel, g.art, s.kennung, to_char(g.signiert_am AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"') AS signiert_am,
                    to_char(g.eingereicht_am AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"') AS eingereicht_am, g.eingereicht_von
               FROM kern.siegel_signatur g JOIN kern.siegelschluessel s ON s.fpr = g.schluessel ORDER BY g.eingereicht_am") as $g) {
            $signaturen[$g['siegel']][] = ['schluessel' => $g['schluessel'], 'art' => $g['art'], 'kennung' => $g['kennung'],
                'signiert_am' => $g['signiert_am'], 'eingereicht_am' => $g['eingereicht_am'], 'eingereicht_von' => $g['eingereicht_von']];
        }
        $siegel = array_map(fn (array $s) => [
            'id' => $s['id'], 'nr' => (int) $s['nr'], 'status' => $s['status'], 'angefordert_am' => $s['angefordert_am'], 'angefordert_von' => $s['angefordert_von'],
            'buchungen_nr' => (int) $s['buchungen_nr'], 'protokoll_nr' => (int) $s['protokoll_nr'], 'inhalt_sha256' => $s['inhalt_sha256'],
            // nach diesem Siegel festgeschriebene Buchungen sind durch es noch nicht verankert
            'buchungen_danach' => max(0, ($kopf['buchungen']['nr'] ?? 0) - (int) $s['buchungen_nr']),
            'signaturen' => $signaturen[$s['id']] ?? [],
        ], $db->fetchAllAssociative(
            "SELECT id, nr, status, to_char(angefordert_am AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"') AS angefordert_am, angefordert_von,
                    buchungen_nr, protokoll_nr, inhalt_sha256
               FROM kern.siegel ORDER BY nr DESC LIMIT 200"));
        $schluessel = array_map(fn (array $s) => $s + ['signaturen' => (int) $db->fetchOne('SELECT count(*) FROM kern.siegel_signatur WHERE schluessel = ?', [$s['fpr']])],
            $db->fetchAllAssociative(
                "SELECT fpr, art, kennung, algorithmus, to_char(angemeldet_am AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"') AS angemeldet_am, angemeldet_von,
                        to_char(zurueckgezogen_am AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"') AS zurueckgezogen_am, zurueckgezogen_grund
                   FROM kern.siegelschluessel ORDER BY angemeldet_am"));
        $anf = self::anforderung($a);
        return ['kettenkoepfe' => $kopf, 'anforderung' => $anf, 'siegel' => $siegel, 'schluessel' => $schluessel,
            'namen' => self::namen($a, array_merge(array_column($siegel, 'angefordert_von'), array_column($schluessel, 'angemeldet_von'),
                $anf === null ? [] : [$anf['angefordert_von']],
                ...array_map(fn ($l) => array_column($l, 'eingereicht_von'), array_values($signaturen))))];
    }


    /**
     * Die offene Anforderung – vorläufig, noch kein Siegel (F124). Ohne Volltext von Inhalt und Signatur.
     *
     * @return array<string, mixed>|null
     */
    public static function anforderung(Anfrage $a): ?array
    {
        $r = $a->db()->fetchAssociative(
            "SELECT f.id, f.nr, to_char(f.angefordert_am AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"') AS angefordert_am, f.angefordert_von, to_char(f.gueltig_bis AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"') AS gueltig_bis,
                    f.buchungen_nr, f.protokoll_nr, f.inhalt_sha256, f.schluessel, f.art, s.kennung,
                    to_char(f.signiert_am AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"') AS signiert_am, to_char(f.geprueft_am AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"') AS geprueft_am
               FROM kern.siegel_anforderung f LEFT JOIN kern.siegelschluessel s ON s.fpr = f.schluessel
              WHERE f.gueltig_bis > clock_timestamp()");
        if ($r === false) {
            return null;
        }
        return ['id' => $r['id'], 'nr' => (int) $r['nr'], 'angefordert_am' => $r['angefordert_am'], 'angefordert_von' => $r['angefordert_von'],
            'gueltig_bis' => $r['gueltig_bis'], 'buchungen_nr' => (int) $r['buchungen_nr'], 'protokoll_nr' => (int) $r['protokoll_nr'],
            'inhalt_sha256' => $r['inhalt_sha256'],
            'signatur' => $r['schluessel'] === null ? null : ['schluessel' => $r['schluessel'], 'art' => $r['art'], 'kennung' => $r['kennung'],
                'signiert_am' => $r['signiert_am'], 'geprueft_am' => $r['geprueft_am']]];
    }

    /** Fordert ein Siegel an – vorläufig; festgeschrieben wird noch nichts. @return array<string, mixed> die Anforderung */
    public static function anfordern(Anfrage $a): array
    {
        $a->db()->fetchOne('SELECT kern.siegel_anfordern(?, ?, ?)', [$a->kontext()->subjekt, $a->kontext()->mandant, $a->kontext()->buch]);
        return self::anforderung($a) ?? throw new \LogicException('Anforderung fehlt nach dem Anfordern');
    }

    /**
     * Siegel oder offene Anforderung mit den Bytes, die signiert werden.
     *
     * @return array{id: string, nr: int, inhalt: string, vorlaeufig: bool}|null
     */
    public static function lesen(Anfrage $a, string $id): ?array
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id) !== 1) {
            return null;
        }
        $s = $a->db()->fetchAssociative('SELECT id, nr, inhalt FROM kern.siegel WHERE id = ?', [$id]);
        $vorlaeufig = $s === false;
        if ($vorlaeufig) {
            $s = $a->db()->fetchAssociative('SELECT id, nr, inhalt FROM kern.siegel_anforderung WHERE id = ? AND gueltig_bis > clock_timestamp()', [$id]);
        }
        return $s === false ? null : ['id' => $s['id'], 'nr' => (int) $s['nr'], 'inhalt' => $s['inhalt'], 'vorlaeufig' => $vorlaeufig];
    }

    /** Vermerkt eine vom Aufrufer geprüfte Signatur an der Anforderung. Festgeschrieben wird damit noch nichts. */
    public static function anforderungSignatur(Anfrage $a, string $anforderung, string $fpr, string $art, string $signatur, string $signiertAm): void
    {
        $a->db()->executeStatement('SELECT kern.siegel_anforderung_signatur(?, ?::uuid, ?, ?, ?, ?::timestamptz)',
            [$a->kontext()->subjekt, $anforderung, $fpr, $art, $signatur, $signiertAm]);
    }

    /** Verwirft die Anforderung spurlos. */
    public static function anforderungVerwerfen(Anfrage $a, string $anforderung): void
    {
        $a->db()->executeStatement('SELECT kern.siegel_anforderung_verwerfen(?, ?::uuid)', [$a->kontext()->subjekt, $anforderung]);
    }

    /**
     * Schreibt das Siegel fest: Siegel, Signatur und Protokolleintrag in einer Transaktion.
     *
     * @return array{id: string, nr: int, inhalt_sha256: string}
     */
    public static function festschreiben(Anfrage $a, string $anforderung): array
    {
        $id = (string) $a->db()->fetchOne('SELECT kern.siegel_festschreiben(?, ?::uuid)', [$a->kontext()->subjekt, $anforderung]);
        $s = $a->db()->fetchAssociative('SELECT nr, inhalt_sha256 FROM kern.siegel WHERE id = ?', [$id]);
        return ['id' => $id, 'nr' => (int) $s['nr'], 'inhalt_sha256' => $s['inhalt_sha256']];
    }

    /** @return list<string> alle angemeldeten öffentlichen Schlüssel einer Art, auch zurückgezogene */
    public static function schluesselTexte(Anfrage $a, string $art): array
    {
        return $a->db()->fetchFirstColumn('SELECT oeffentlich FROM kern.siegelschluessel WHERE art = ? ORDER BY angemeldet_am', [$art]);
    }

    public static function schluesselText(Anfrage $a, string $fpr): ?string
    {
        $t = $a->db()->fetchOne('SELECT oeffentlich FROM kern.siegelschluessel WHERE fpr = ?', [$fpr]);
        return $t === false ? null : (string) $t;
    }

    public static function signaturText(Anfrage $a, string $siegel, string $fpr): ?string
    {
        $t = $a->db()->fetchOne('SELECT signatur FROM kern.siegel_signatur WHERE siegel = ? AND schluessel = ?', [$siegel, $fpr]);
        return $t === false ? null : (string) $t;
    }

    /** Trägt eine vom Aufrufer geprüfte weitere Signatur an einem festgeschriebenen Siegel ein. */
    public static function signaturEintragen(Anfrage $a, string $siegel, string $fpr, string $art, string $signatur, string $signiertAm): void
    {
        $a->db()->executeStatement('SELECT kern.siegel_signieren(?, ?::uuid, ?, ?, ?, ?::timestamptz)',
            [$a->kontext()->subjekt, $siegel, $fpr, $art, $signatur, $signiertAm]);
    }

    public static function schluesselAnmelden(Anfrage $a, string $art, string $fpr, string $kennung, string $algorithmus, string $oeffentlich): void
    {
        $a->db()->executeStatement('SELECT kern.siegelschluessel_anmelden(?, ?, ?, ?, ?, ?)',
            [$a->kontext()->subjekt, $art, $fpr, $kennung, $algorithmus, $oeffentlich]);
    }

    public static function schluesselZurueckziehen(Anfrage $a, string $fpr, string $grund): void
    {
        $a->db()->executeStatement('SELECT kern.siegelschluessel_zurueckziehen(?, ?, ?)', [$a->kontext()->subjekt, $fpr, $grund]);
    }

    /**
     * Namen zu den Subjekten `nutzer:<uuid>` – für die Anzeige, wer angefordert und eingereicht hat.
     *
     * @param list<string> $subjekte
     * @return array<string, string>
     */
    private static function namen(Anfrage $a, array $subjekte): array
    {
        $ids = [];
        foreach (array_unique($subjekte) as $s) {
            if (preg_match('/^nutzer:([0-9a-f-]{36})$/', $s, $m)) {
                $ids[] = $m[1];
            }
        }
        $erg = [];
        foreach ($ids as $id) {
            $n = $a->mandantDb()->fetchAssociative('SELECT vornamen, familienname FROM mandant.nutzer WHERE id = ?', [$id]);
            if ($n !== false) {
                $erg["nutzer:$id"] = trim(($n['vornamen'] ?? '') . ' ' . $n['familienname']);
            }
        }
        return $erg;
    }
}
