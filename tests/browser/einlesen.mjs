// SPDX-License-Identifier: AGPL-3.0-or-later
// Browsertest des Moduls import (IMPORT.md, F104): CSV wählen, Aufbau, Spalten, Richtung, Geldkonto, Vorschau, einliefern,
// Arbeitsvorrat mit Geldkonto als Vorauswahl, markierter Fehlerzeile und der Spalte „Doppelt?“. Nur synthetische Daten.
//
//   … node tests/browser/einlesen.mjs [Bildverzeichnis]   (Umgebung wie tests/browser/buchen.mjs)
import { spawn, execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { mkdirSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const require = createRequire(import.meta.url);
const { chromium } = require(process.env.PLAYWRIGHT || 'playwright');
const wurzel = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const PORT = 8093;
const ADRESSE = `http://localhost:${PORT}`;
const bilder = process.argv[2] || null;
if (bilder) mkdirSync(bilder, { recursive: true });

let pruefungen = 0;
function ist(b, was) { if (!b) throw new Error(`fehlgeschlagen: ${was}`); pruefungen++; }

const umgebung = JSON.parse(execFileSync('php', [path.join(wurzel, 'tests/browser/aufbau.php'), ADRESSE, '--buchen', '--vorrat', '--import'], { encoding: 'utf8' }).trim().split('\n').pop());
const server = spawn('php', ['-S', `localhost:${PORT}`, path.join(wurzel, 'werkzeuge/entwicklungsserver.php')], {
  cwd: wurzel, env: { ...process.env, KUMERO_KONFIG: umgebung.konfig, KUMERO_ENTWICKLUNG: '1' }, stdio: ['ignore', 'ignore', 'pipe'],
});
let serverLog = '';
server.stderr.on('data', (d) => { serverLog += d; });
await new Promise((r) => setTimeout(r, 700));

const browser = await chromium.launch({ executablePath: process.env.CHROME || undefined, headless: true });
const fehler = [];
try {
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'de-DE' });
  const page = await context.newPage();
  page.setDefaultTimeout(15000);
  page.on('console', (m) => { if (m.type() === 'error') fehler.push(`console: ${m.text()}`); });
  page.on('pageerror', (e) => fehler.push(`pageerror: ${e.message}`));
  page.on('response', (r) => { if (r.status() >= 400 && r.status() !== 401) fehler.push(`HTTP ${r.status()} ${r.url()}`); });
  const cdp = await context.newCDPSession(page);
  await cdp.send('WebAuthn.enable');
  await cdp.send('WebAuthn.addVirtualAuthenticator', { options: {
    protocol: 'ctap2', transport: 'internal', hasResidentKey: true, hasUserVerification: true, isUserVerified: true, automaticPresenceSimulation: true,
  } });
  const bild = async (name) => { if (bilder) await page.screenshot({ path: path.join(bilder, `${name}.png`), fullPage: false }); };
  const meldung = (text) => page.locator('.meldung').filter({ hasText: text }).first().waitFor();
  const nav = (text) => page.getByRole('navigation', { name: 'Menü' }).getByText(text, { exact: true }).click();

  // Registrieren und anmelden
  await page.goto(umgebung.link);
  await page.getByText('Passkey registrieren', { exact: true }).first().waitFor();
  await page.getByRole('button', { name: 'Passkey registrieren' }).click();
  await page.getByText('Passkey registriert. Jetzt anmelden.').waitFor();
  await page.locator('#anmelden').click();
  await page.getByRole('navigation', { name: 'Menü' }).getByText('Buchungserfassung').waitFor();
  const bereiche = await page.locator('.navigation .bereich h2').allTextContents();
  ist(bereiche.includes('Buchen'), `Bereiche im Menü: ${bereiche}`);


  const csv = 'Musterbank;Umsatzliste\nKonto;DE00 0000 0000\n\nBuchungstag;Valuta;Name;Verwendungszweck;Betrag;Währung;Saldo\n'
    + '02.03.2026;03.03.2026;Musterlieferant GmbH;RE-0815;-109,43;EUR;890,57\n04.03.2026;04.03.2026;Musterpatient;Honorar;1.250,00;EUR;2.140,57\n05.03.2026;;Unklar;kaputt;abc;EUR;2.140,57\n';
  await nav('Buchen einrichten');
  await page.locator('#schnell-knopf').click();
  await page.locator('#einrichtung-bereit').waitFor();
  // Ein Beleg von Hand, der zur Abbuchung in der Datei gehört – für die Spalte „Doppelt?“
  await nav('Arbeitsvorrat');
  await page.locator('#vorrat-neu').click();
  await page.locator('#neu-betreff').fill('Rechnung Musterlieferant');
  await page.locator('#neu-gegenpartei').fill('Musterlieferant GmbH');
  await page.locator('#neu-betrag').fill('109,43');
  await page.locator('#neu-speichern').click();
  await page.locator('#vorratsliste tr[data-id]').first().waitFor();

  await nav('Datei einlesen');
  await page.locator('#import-datei').setInputFiles({ name: 'umsaetze-maerz.csv', mimeType: 'text/csv', buffer: Buffer.from(csv, 'utf8') });
  await page.locator('#imp-kopfzeile').waitFor();
  ist(await page.locator('#imp-kopfzeile').inputValue() === '4' && await page.locator('#imp-trenner').inputValue() === ';', 'Aufbau erkannt: Kopfzeile 4, Semikolon');
  ist(await page.locator('select[data-spalte="Betrag"]').inputValue() === 'betrag' && await page.locator('select[data-spalte="Valuta"]').inputValue() === 'zahltag', 'Spalten vorgeschlagen');
  ist(await page.locator('#imp-richtung-vorzeichen').isChecked(), 'Richtung vorgeschlagen: Vorzeichen');
  await bild('1-einlesen');
  await page.locator('#imp-vorschau').click();
  await meldung('Bitte ein vorhandenes Geldkonto wählen.');
  await page.locator('#imp-geldkonto').fill('1200');
  await page.locator('#imp-vorschau').click();
  await page.locator('#imp-bericht').waitFor();
  const kacheln = await page.locator('#imp-bericht .kachel strong').allTextContents();
  ist(kacheln.join(',') === '3,0,1', `Vorschau: drei neue Zeilen, davon eine mit Fehler: ${kacheln}`);
  ist((await page.locator('#imp-fehler').innerText()).includes('Zeile 7'), 'Fehler nennt die Zeile der Datei');
  await bild('2-vorschau');
  await page.locator('#imp-profil-name').fill('Musterbank Giro');
  await page.locator('#imp-profil-speichern').click();
  await meldung('Profil „Musterbank Giro“ gespeichert.');
  await page.locator('#imp-einliefern').click();
  await meldung('3 Zeilen in den Arbeitsvorrat eingeliefert.');
  await page.locator('#imp-zum-vorrat').click();
  await page.locator('#vorratsliste tr[data-id]').nth(3).waitFor();
  const zeilen = await page.locator('#vorratsliste tr[data-id]').allInnerTexts();
  ist(zeilen.filter((z) => z.includes('≈ Gruppe 1')).length === 2, `Abbuchung und Rechnung sind als vermutlich doppelt markiert: ${zeilen.length} Zeilen`);
  const abbuchung = page.locator('#vorratsliste tr[data-id]').filter({ hasText: 'RE-0815' });
  ist(await abbuchung.locator('input[data-feld=gegenkonto]').inputValue() === '1200' && await abbuchung.locator('input[data-feld=konto]').inputValue() === '', 'Ausgabe: Geldkonto im Haben vorbelegt');
  const honorar = page.locator('#vorratsliste tr[data-id]').filter({ hasText: 'Honorar' });
  ist(await honorar.locator('input[data-feld=konto]').inputValue() === '1200', 'Einnahme: Geldkonto im Soll vorbelegt');
  ist((await page.locator('#vorratsliste tr[data-id]').filter({ hasText: 'kaputt' }).innerText()).includes('Hinweis'), 'fehlerhafte Zeile liegt markiert im Vorrat');
  await bild('3-vorrat');
  // Profil wird beim nächsten Mal wiedererkannt; dieselbe Datei wird gemeldet
  await nav('Datei einlesen');
  await page.locator('#import-datei').setInputFiles({ name: 'umsaetze-maerz.csv', mimeType: 'text/csv', buffer: Buffer.from(csv, 'utf8') });
  await page.locator('#imp-schon').waitFor();
  await page.locator('#imp-profil').selectOption({ label: 'Musterbank Giro' });
  await meldung('Profil „Musterbank Giro“ angewendet.');
  ist(await page.locator('#imp-geldkonto').inputValue() === '1200', 'Profil bringt das Geldkonto mit');
  await page.locator('#imp-vorschau').click();
  await page.locator('#imp-bericht').waitFor();
  ist((await page.locator('#imp-bericht .kachel strong').allTextContents()).join(',') === '0,3,0', 'dieselbe Datei: alles schon vorhanden');
} finally {
  await browser.close();
  server.kill();
  execFileSync('php', [path.join(wurzel, 'tests/browser/aufbau.php'), '--abbau']);
}
const echteFehler = fehler.filter((f) => !/status of (401|404)/.test(f));
if (echteFehler.length) {
  console.error(echteFehler.join('\n'));
  console.error(serverLog.split('\n').filter((z) => /error|warn|kumero\[/i.test(z)).slice(-20).join('\n'));
  process.exit(1);
}
console.log(`${pruefungen} Prüfungen im Browser, alle grün`);
