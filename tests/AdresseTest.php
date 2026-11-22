<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Tests;

use Kumero\Plattform\Adresse;

/** Die wirksame Adresse einer Anfrage ist die einzige Quelle für RP-ID, Herkunft und Links (VERWALTUNG.md 18, DM13). */
final class AdresseTest extends Rahmen
{
    public function testAdresseDesMandanten(): void
    {
        $a = Adresse::desMandanten('buch.praxis-muster.example', 'praxis');
        $this->gleich('https://buch.praxis-muster.example', $a->origin, 'Origin ist https ohne Port');
        $this->gleich('buch.praxis-muster.example', $a->rpId, 'RP-ID ist der Hostname');
        $this->gleich('praxis', $a->mandant, 'Die Adresse nennt ihren Mandanten');
        $this->gleich('https://buch.praxis-muster.example/app.html#x', $a->link('/app.html#x'), 'Link beginnt mit dem Origin');
    }

    /** Kein Controller und kein Modul liest Adresse oder RP-ID aus der Konfiguration oder baut sich den Prüfer selbst. */
    public function testNurEineStelle(): void
    {
        $wurzel = dirname(__DIR__);
        $erlaubt = ['src/Plattform/Adresse.php', 'src/Plattform/Konfiguration.php', 'src/Konsole/Befehle.php'];
        $funde = [];
        foreach (['src', 'module'] as $verz) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("$wurzel/$verz", \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $datei) {
                $pfad = substr((string) $datei, strlen($wurzel) + 1);
                if (!str_ends_with($pfad, '.php') || in_array($pfad, $erlaubt, true)) {
                    continue;
                }
                if (preg_match('/konfig->(adresse|rpId)\b|new\s+Pruefer\s*\(/', (string) file_get_contents((string) $datei))) {
                    $funde[] = $pfad;
                }
            }
        }
        $this->gleich([], $funde, 'Adresse, RP-ID und Prüfer kommen nur aus der Anfrage');
    }
}
