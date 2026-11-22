// SPDX-License-Identifier: AGPL-3.0-or-later
// Der Weg eines Besuchers im Browser – wird von tests/spielwiese/lauf.php gestartet, wenn PLAYWRIGHT gesetzt ist:
//   PLAYWRIGHT=/opt/npm-tools/node_modules/playwright CHROME=/usr/bin/google-chrome php tests/spielwiese/lauf.php [Bildverzeichnis]
import { createRequire } from 'node:module';
import { mkdirSync } from 'node:fs';

const require = createRequire(import.meta.url);
const { chromium } = require(process.env.PLAYWRIGHT || 'playwright');
const ADRESSE = process.argv[2];
const bilder = process.argv[3] || null;
if (bilder) mkdirSync(bilder, { recursive: true });
let pruefungen = 0;
function ist(b, was) { if (!b) throw new Error(`fehlgeschlagen: ${was}`); pruefungen++; }

const browser = await chromium.launch({ executablePath: process.env.CHROME || undefined, args: ['--no-sandbox'] });
try {
    const seite = await (await browser.newContext({ viewport: { width: 1400, height: 900 } })).newPage();
    const fehler = [];
    seite.on('pageerror', (e) => fehler.push(String(e)));
    await seite.goto(ADRESSE + '/app.html');
    ist(new URL(seite.url()).pathname === '/', 'ohne Bestätigung landet der Besucher auf der Startseite');
    ist(await seite.getByText('Alles hier ist öffentlich.').isVisible(), 'Hinweis auf der vorgeschalteten Seite');
    if (bilder) await seite.screenshot({ path: `${bilder}/1-startseite.png`, fullPage: true });
    await seite.getByRole('button', { name: 'Verstanden, weiter' }).click();
    await seite.waitForURL('**/app.html**');
    await seite.locator('option[value="haupt"]').first().waitFor({ state: 'attached', timeout: 15000 });
    ist((await seite.locator('option[value="haupt"]').first().textContent()).includes('Malerbetrieb Farbenfroh'), 'Buch des erfundenen Betriebs in der Auswahl');
    ist(!(await seite.getByText('Bitte anmelden').isVisible().catch(() => false)), 'keine Anmeldeseite von kumero');
    if (bilder) await seite.screenshot({ path: `${bilder}/2-oberflaeche.png` });
    await seite.goto(ADRESSE + '/app.html#/m/demo/b/haupt/buchen/liste');
    await seite.getByText('Familie Beispiel, Wohnzimmer und Flur gestrichen').first().waitFor({ timeout: 15000 });
    ist(true, 'Buchungsliste zeigt den erfundenen Bestand');
    if (bilder) await seite.screenshot({ path: `${bilder}/3-buchungen.png` });
    // Sitzung weg (abgelaufen oder zurückgesetzt): Der Pförtner meldet neu an, der Besucher merkt nichts
    const kontext = seite.context();
    const merk = (await kontext.cookies()).filter((c) => c.name === '__Host-spielwiese');
    await kontext.clearCookies();
    await kontext.addCookies(merk);
    await seite.reload();
    await seite.getByText('Familie Beispiel, Wohnzimmer und Flur gestrichen').first().waitFor({ timeout: 15000 });
    ist(!(await seite.locator('#sitzung-abgelaufen').isVisible().catch(() => false)), 'kein Dialog „Bitte neu anmelden“');
    ist(fehler.length === 0, 'keine Fehler im Browser: ' + fehler.join(' | '));
    console.log(`${pruefungen} Prüfungen im Browser grün`);
} finally {
    await browser.close();
}
