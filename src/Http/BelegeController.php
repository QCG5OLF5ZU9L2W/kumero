<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Http;

use Kumero\Api\V1\Belegarchiv;
use Kumero\Api\V1\Dienste;
use Kumero\Api\V1\Problem;
use Kumero\Plattform\Anfrage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Belege aus angebundenen Belegarchiven (F97): Vorschau, Original und geprüfter Belegverweis – unabhängig
 * davon, welches DMS-Modul zuständig ist. Der Kern lädt nie selbst einen Beleg; er reicht an das Modul
 * weiter, dessen Archiv für den Link zuständig ist.
 */
final class BelegeController
{
    public function archive(Anfrage $a): Response
    {
        $erg = [];
        foreach ($this->module($a) as $name => $m) {
            try {
                foreach ($m->belegArchive($a) as $x) {
                    $erg[] = ['praefix' => (string) $x['praefix'], 'titel' => (string) $x['titel'], 'bereit' => (bool) $x['bereit'], 'modul' => $name,
                              'vorlaeufig' => (bool) ($x['vorlaeufig'] ?? false)];
                }
            } catch (Problem) {
                // z. B. Dienstkonto oder kein Recht in diesem Modul: kein Archiv
            }
        }
        return Antwort::json($erg);
    }

    public function vorschau(Anfrage $a): Response
    {
        [$m, $link] = $this->zustaendig($a, (string) $a->http->query->get('link', ''));
        return $m->belegVorschau($a, $link);
    }

    public function original(Anfrage $a): Response
    {
        [$m, $link] = $this->zustaendig($a, (string) $a->http->query->get('link', ''));
        return $m->belegOriginal($a, $link);
    }

    public function verweis(Anfrage $a): Response
    {
        $j = $a->json();
        if (array_diff(array_keys($j), ['link']) !== [] || !is_string($j['link'] ?? null)) {
            throw Problem::ungueltig('Eingabe ungültig', [['feld' => 'link', 'meldung' => 'Link erwartet']]);
        }
        [$m, $link] = $this->zustaendig($a, $j['link']);
        return Antwort::json($m->belegPruefen($a, $link));
    }

    /**
     * Vorläufige Belege (F110) gelangen nie in eine Buchung: Ein Archiv, das sich als vorläufig meldet, hält ein Original
     * nur, bis es an das DMS übergeben ist. Sein Link würde später ins Leere zeigen und darf nicht versiegelt werden.
     *
     * @param list<mixed> $belege
     */
    public static function vorlaeufigAblehnen(Anfrage $a, array $belege): void
    {
        $links = array_values(array_filter(array_map(fn ($b) => is_array($b) && is_string($b['link'] ?? null) ? $b['link'] : null, $belege)));
        if ($links === []) {
            return;
        }
        foreach ((new self())->module($a) as $m) {
            try {
                $archive = $m->belegArchive($a);
            } catch (Problem) {
                continue;
            }
            foreach ($archive as $x) {
                foreach ($links as $link) {
                    if (($x['vorlaeufig'] ?? false) && str_starts_with($link, (string) $x['praefix'])) {
                        throw new Problem(422, 'beleg.vorlaeufig', 'Der Beleg liegt noch in der Zwischenablage von kumero und nicht im Belegarchiv – er kann nicht an einer Buchung stehen');
                    }
                }
            }
        }
    }

    /** @return array<string, Belegarchiv> */
    private function module(Anfrage $a): array
    {
        $erg = [];
        foreach (Dienste::aktiveModule($a->db()) as $name => $m) {
            if ($m['einstieg'] instanceof Belegarchiv) {
                $erg[$name] = $m['einstieg'];
            }
        }
        return $erg;
    }

    /** @return array{0: Belegarchiv, 1: string} */
    private function zustaendig(Anfrage $a, string $link): array
    {
        foreach ($this->module($a) as $m) {
            try {
                foreach ($m->belegArchive($a) as $x) {
                    if ($link !== '' && str_starts_with($link, (string) $x['praefix'])) {
                        return [$m, $link];
                    }
                }
            } catch (Problem) {
                continue;
            }
        }
        throw new Problem(404, 'beleg.kein_archiv', 'Für diesen Beleglink ist kein angebundenes Belegarchiv zuständig');
    }
}
