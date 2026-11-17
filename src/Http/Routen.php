<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Http;

use Kumero\Api\V1\RoutenAnbieter;
use Kumero\Plattform\Menue;
use Kumero\Plattform\Module;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Routen nach openapi/v1.yaml. Je Route: Handler, ob im Mandanten, nötiges
 * Recht (F41) und ob eine Anmeldung nötig ist.
 */
final class Routen
{
    private static ?RouteCollection $sammlung = null;

    public static function sammlung(): RouteCollection
    {
        if (self::$sammlung !== null) {
            return self::$sammlung;
        }
        $s = new RouteCollection();
        $an = new AnmeldungController();
        $st = new StammdatenController();

        $global = function (string $name, string $methode, string $pfad, callable $h, bool $anmeldung = true) use ($s): void {
            $s->add($name, new Route('/api/v1' . $pfad, ['_handler' => $h, '_anmeldung' => $anmeldung], [], [], '', [], [$methode]));
        };
        $m = function (string $name, string $methode, string $pfad, callable $h, ?string $recht) use ($s): void {
            $s->add($name, new Route('/m/{mandant}/api/v1' . $pfad,
                ['_handler' => $h, '_mandant' => true, '_recht' => $recht],
                ['mandant' => '[a-z][a-z0-9-]{1,31}', 'nr' => '[0-9A-Za-z][0-9A-Za-z._-]{0,19}',
                 'kuerzel' => '[A-Za-z0-9_-]{1,16}', 'geschaeftsjahr' => '[^/]{1,32}'],
                [], '', [], [$methode]));
        };

        $s->add('start', new Route('/', ['_handler' => fn () => new \Symfony\Component\HttpFoundation\RedirectResponse('/app.html'),
            '_anmeldung' => false], [], [], '', [], ['GET']));
        $global('anmeldung.optionen', 'POST', '/anmeldung/optionen', $an->optionen(...), false);
        $global('anmeldung', 'POST', '/anmeldung', $an->anmelden(...), false);
        $global('abmeldung', 'POST', '/abmeldung', $an->abmelden(...));
        $global('ich', 'GET', '/ich', $an->ich(...));
        $global('registrierung.optionen', 'POST', '/registrierung/optionen', $an->registrierungOptionen(...), false);
        $global('registrierung', 'POST', '/registrierung', $an->registrieren(...), false);

        $m('konten.liste', 'GET', '/konten', $st->kontenListe(...), 'kern.stammdaten.sehen');
        $m('konten.anlegen', 'POST', '/konten', $st->kontoAnlegen(...), 'kern.stammdaten.aendern');
        $m('konto.lesen', 'GET', '/konten/{nr}', $st->kontoLesen(...), 'kern.stammdaten.sehen');
        $m('konto.aendern', 'PATCH', '/konten/{nr}', $st->kontoAendern(...), 'kern.stammdaten.aendern');
        $m('tags.liste', 'GET', '/tags', $st->tagsListe(...), 'kern.stammdaten.sehen');
        $m('tags.anlegen', 'POST', '/tags', $st->tagAnlegen(...), 'kern.stammdaten.aendern');
        $m('nummernkreise.liste', 'GET', '/nummernkreise', $st->nummernkreiseListe(...), 'kern.stammdaten.sehen');
        $m('nummernkreise.anlegen', 'POST', '/nummernkreise', $st->nummernkreisAnlegen(...), 'kern.stammdaten.aendern');
        $m('nummernkreis.festlegen', 'PUT', '/nummernkreise/{kuerzel}/jahre/{geschaeftsjahr}', $st->nummernkreisFestlegen(...), 'kern.nummernkreis.festlegen');
        $m('buchungsarten.liste', 'GET', '/buchungsarten', $st->buchungsartenListe(...), 'kern.stammdaten.sehen');
        $m('buchungsarten.anlegen', 'POST', '/buchungsarten', $st->buchungsartAnlegen(...), 'kern.stammdaten.aendern');
        $m('geschaeftsjahre.liste', 'GET', '/geschaeftsjahre', $st->geschaeftsjahreListe(...), 'kern.stammdaten.sehen');
        $m('geschaeftsjahre.anlegen', 'POST', '/geschaeftsjahre', $st->geschaeftsjahrAnlegen(...), 'kern.geschaeftsjahr.anlegen');

        $om = new OberflaecheController();
        $global('oberflaeche.menue', 'GET', '/oberflaeche/menue', $om->menue(...));
        $m('oberflaeche.menue.mandant', 'GET', '/oberflaeche/menue', $om->menue(...), null);

        // Endpunkte der Module (RoutenAnbieter), Pfade unter /<modul>
        foreach (Module::alle() as $name => $_) {
            $e = Module::einstieg($name);
            if (!$e instanceof RoutenAnbieter) {
                continue;
            }
            foreach ($e->routen() as $r) {
                if (!str_starts_with($r['pfad'], "/$name/") && $r['pfad'] !== "/$name") {
                    throw new \RuntimeException("Route {$r['name']} von $name liegt nicht unter /$name");
                }
                $routenName = "$name.{$r['name']}";
                if ($r['mandant']) {
                    $s->add($routenName, new Route('/m/{mandant}/api/v1' . $r['pfad'],
                        ['_handler' => $r['handler'], '_mandant' => true, '_recht' => $r['recht'], '_modul' => $name],
                        ['mandant' => '[a-z][a-z0-9-]{1,31}', 'kennung' => '[a-z0-9-]{1,64}', 'id' => '[0-9a-f-]{36}'], [], '', [], [$r['methode']]));
                } else {
                    if (!in_array($r['recht'], Menue::SYSTEMRECHTE, true)) {
                        throw new \RuntimeException("Route {$r['name']} von $name: systemweit nur " . implode(', ', Menue::SYSTEMRECHTE));
                    }
                    $s->add($routenName, new Route('/api/v1' . $r['pfad'],
                        ['_handler' => $r['handler'], '_anmeldung' => true, '_recht' => $r['recht'], '_modul' => $name],
                        ['kennung' => '[a-z0-9-]{1,64}', 'id' => '[0-9a-f-]{36}'], [], '', [], [$r['methode']]));
                }
            }
        }

        return self::$sammlung = $s;
    }
}
