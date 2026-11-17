// SPDX-License-Identifier: AGPL-3.0-or-later
// Browsertest des Moduls buchen (BUCHUNGSMASKE.md, F83): Einrichtung, Geschäftsvorfälle, Buchungserfassung
// mit Tastatur, Rechnen im Betragsfeld, Splitbuchung, Stapelfreigabe, Storno, Kontoblatt.
// Prüft nebenbei, dass die strenge CSP nichts blockiert und keine Skriptfehler auftreten.
//
//   KUMERO_AUTOLOAD=… KUMERO_TEST_PGHOST=/tmp/pgtest KUMERO_TEST_PGUSER=postgres \
//   PLAYWRIGHT=/opt/npm-tools/node_modules/playwright CHROME=/usr/bin/google-chrome node tests/browser/buchen.mjs [Bildverzeichnis]
import { spawn, execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { mkdirSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const require = createRequire(import.meta.url);
const { chromium } = require(process.env.PLAYWRIGHT || 'playwright');
const wurzel = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const PORT = 8098;
const ADRESSE = `http://localhost:${PORT}`;
const bilder = process.argv[2] || null;
if (bilder) mkdirSync(bilder, { recursive: true });

let pruefungen = 0;
function ist(b, was) { if (!b) throw new Error(`fehlgeschlagen: ${was}`); pruefungen++; }

const umgebung = JSON.parse(execFileSync('php', [path.join(wurzel, 'tests/browser/aufbau.php'), ADRESSE, '--buchen'], { encoding: 'utf8' }).trim().split('\n').pop());
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
  ist(JSON.stringify(bereiche) === JSON.stringify(['Start', 'Buchen', 'Stammdaten', 'Einstellungen', 'Verwaltung']), `Bereiche im Menü: ${bereiche}`);

  // Einrichtung: die Erfassung sagt, was fehlt, und führt hin
  await nav('Buchungserfassung');
  await page.locator('#einrichtung-fehlt').waitFor();
  ist(await page.locator('#einrichtung-fehlt li').count() === 2, 'Hinweis: Geschäftsjahr und Buchungsart fehlen');
  await page.locator('#einrichtung-fehlt').getByRole('button', { name: 'Buchen einrichten' }).click();
  await page.locator('#schnell-knopf').waitFor();
  await bild('1-einrichtung-leer');
  await page.locator('#schnell-knopf').click();
  await page.locator('#einrichtung-bereit').waitFor();
  ist(await page.locator('#schnell').count() === 0, 'Schnelleinrichtung nur am Anfang');
  await page.locator('#tag-name').fill('Praxis');
  await page.locator('#tag-anlegen').click();
  await meldung('Tag angelegt.');
  const jahr = String(new Date().getFullYear());
  ist(await page.locator('code').filter({ hasText: 'ALLG-{JJJJ}-{NR:5}' }).count() === 1, 'Nummernvorlage festgelegt');
  await bild('2-einrichtung');

  // Geschäftsvorfälle: mitgelieferte übernehmen, eine eigene anlegen
  await nav('Geschäftsvorfälle');
  await page.locator('#vorgaben').click();
  await meldung('7 mitgelieferte Vorlagen übernommen.');
  await page.locator('#v-gruppe').fill('Zahlungseingang');
  await page.locator('#v-name').fill('Honorar Bank');
  await page.locator('#v-konto').fill('1200');
  await page.locator('#v-gegenkonto').fill('8100');
  await page.locator('#v-text').fill('Honorar');
  await page.locator('#v-tags').fill('Praxis');
  await page.locator('#v-reihenfolge').fill('5');
  await page.locator('#vorlage-speichern').click();
  await meldung('Vorlage gespeichert.');
  await page.locator('table.liste tbody tr').nth(7).waitFor();
  ist(await page.locator('table.liste tbody tr').count() === 8, 'acht Vorlagen');
  await bild('3-geschaeftsvorfaelle');

  // Buchungserfassung: Geschäftsvorfall, Rechnen im Betragsfeld, Buchen mit Strg+Umschalt+Enter
  await nav('Buchungserfassung');
  await page.locator('#erfassung').waitFor();
  ist(await page.locator('#einrichtung-fehlt').count() === 0, 'nichts fehlt mehr');
  ist(await page.evaluate(() => document.activeElement?.id) === 'konto', 'Fokus im Feld Konto');
  await page.getByRole('button', { name: 'Honorar Bank' }).click();
  ist(await page.locator('#konto').inputValue() === '1200' && await page.locator('#gegenkonto').inputValue() === '8100', 'Vorlage belegt Konto und Gegenkonto');
  await page.locator('.konto-name').filter({ hasText: 'Honorare Privatpatienten' }).waitFor();
  ist(await page.evaluate(() => document.activeElement?.id) === 'betrag', 'danach steht der Fokus im Betrag');
  await page.keyboard.type('100+9,43');
  await page.keyboard.press('Enter');
  ist(await page.locator('#betrag').inputValue() === '109,43', 'Rechnen im Betragsfeld');
  ist(await page.evaluate(() => document.activeElement?.id) === 'gegenkonto', 'Enter geht zum nächsten Feld');
  await page.locator('#belegnummer').fill('RE-0815');
  await bild('4-erfassung');
  await page.locator('#text').focus();
  await page.keyboard.press('Control+Shift+Enter');
  await meldung(`Gebucht als Nr. ALLG-${jahr}-00001.`);
  ist(await page.locator('#konto').inputValue() === '' && await page.locator('#belegnummer').inputValue() === 'RE-0815', 'Formular bleibt offen, Belegnummer bleibt');

  // Entwurf mit Strg+Enter; Belegnummer hochzählen; Soll und Haben umkehren mit F4
  await page.locator('#konto').fill('1200');
  await page.locator('#betrag').fill('23,80');
  await page.locator('#gegenkonto').fill('4930');
  await page.locator('#gegenkonto').press('F4');
  ist(await page.locator('#konto').inputValue() === '4930' && await page.locator('#gegenkonto').inputValue() === '1200', 'F4 kehrt Soll und Haben um');
  await page.getByTitle('Belegnummer hochzählen').click();
  ist(await page.locator('#belegnummer').inputValue() === 'RE-0816', 'Belegnummer hochgezählt');
  await page.locator('#text').fill('Druckerpapier (Muster)');
  await page.locator('#text').press('Control+Enter');
  await meldung('Entwurf im Stapel gespeichert.');

  // Kontensuche mit F2
  await page.locator('#konto').press('F2');
  await page.locator('dialog .suche').fill('kass');
  await page.locator('.kontotreffer li.gewaehlt').filter({ hasText: 'Kasse' }).waitFor();
  await page.keyboard.press('Enter');
  await page.waitForFunction(() => document.getElementById('konto').value === '1000');
  ist(await page.locator('dialog').count() === 0, 'Kontensuche übernimmt mit Enter');

  // Splitbuchung als Zeilentabelle
  await page.locator('#konto').fill('1200');
  await page.locator('#betrag').fill('300');
  await page.locator('#gegenkonto').fill('8100');
  await page.locator('#gegenkonto').press('Tab');
  await page.locator('#aufteilen').click();
  ist(await page.locator('.split-tabelle tbody tr').count() === 3, 'Tabelle mit den beiden Zeilen und einer leeren');
  const zelle = (zeile, spalte) => page.locator('.split-tabelle tbody tr').nth(zeile).locator('td').nth(spalte).locator('input').first();
  await zelle(1, 3).fill('250');
  await zelle(1, 3).press('Tab');
  await page.locator('#split-rest').filter({ hasText: 'Rest 50,00 ins Haben' }).waitFor();
  await zelle(2, 0).fill('1000');
  await zelle(2, 3).fill('50');
  await zelle(2, 3).press('Tab');
  await page.locator('#split-rest.ausgeglichen').waitFor();
  await page.locator('#text').fill('Honorare gemischt');
  await bild('5-split');
  await page.locator('#speichern').click();
  await meldung('Entwurf im Stapel gespeichert.');

  // Buchungsliste: Stapel, eine Freigabe für beide Entwürfe
  await nav('Buchungsliste');
  await page.locator('#buchungsliste').waitFor();
  await page.locator('#ansicht').selectOption('stapel');
  await page.locator('#buchungsliste tr[data-id]').nth(1).waitFor();
  ist(await page.locator('#buchungsliste tr[data-id]').count() === 2, 'zwei Entwürfe im Stapel');
  await page.locator('#buchungsliste tr[data-id]').first().click();
  await page.keyboard.press(' ');
  await page.keyboard.press('ArrowDown');
  await page.keyboard.press(' ');
  await page.locator('#liste-fuss').filter({ hasText: '2 markiert' }).waitFor();
  await bild('6-stapel');
  await page.locator('#freigeben').click();
  await page.locator('dialog .freigabe-buchung').nth(1).waitFor();
  ist(await page.locator('dialog h2').innerText() === '2 Buchungen freigeben', 'Freigabe zeigt den Inhalt des Stapels (F38, F67)');
  await bild('7-freigabe');
  await page.locator('#freigabe-ja').click();
  await meldung('2 Buchungen festgeschrieben.');
  await page.locator('#buchungsliste tr.leer').waitFor();
  ist(await page.locator('#buchungsliste tr[data-id]').count() === 0, 'Stapel ist leer');

  // Journal: Kontrollsumme, Splitt aufklappen, Suche
  await page.locator('#ansicht').selectOption('journal');
  await page.locator('#buchungsliste tr[data-id]').nth(2).waitFor();
  ist(await page.locator('#kontrollsumme').innerText() === 'Kontrollsumme Soll − Haben: 0,00', 'Kontrollsumme 0,00');
  await page.locator('.klappe').click();
  ist(await page.locator('#buchungsliste tr.unterzeile').count() === 3, 'Splitbuchung aufgeklappt');
  await bild('8-journal');
  await page.keyboard.press('Control+f');
  ist(await page.evaluate(() => document.activeElement?.id) === 'suche', 'Strg+F springt in die Suche');
  await page.keyboard.type('drucker');
  ist(await page.locator('#buchungsliste tr[data-id]').count() === 1, 'Suche filtert');
  await page.locator('#suche').fill('');

  // Storno: Entwurf im Stapel, eigene Freigabe
  await page.locator('#buchungsliste tr[data-id]').first().click();
  await page.locator('#stornieren').click();
  await page.locator('dialog').getByRole('button', { name: 'Stornieren' }).click();
  await meldung('Storno-Entwurf angelegt.');
  ist(await page.locator('#ansicht').inputValue() === 'stapel' && await page.locator('#buchungsliste tr[data-id]').count() === 1, 'Storno liegt im Stapel');
  await page.locator('#freigeben').click();
  await page.locator('#freigabe-ja').click();
  await meldung('Buchung festgeschrieben.');
  await page.locator('#ansicht').selectOption('journal');
  await page.locator('#buchungsliste tr[data-id]').nth(3).waitFor();
  ist(await page.locator('#buchungsliste .marke-klein.fehler').filter({ hasText: 'storniert' }).count() === 1, 'Original als storniert gekennzeichnet');

  // Festgeschriebene Buchung ansehen: nichts änderbar
  await page.locator('#buchungsliste tr[data-id]').nth(1).click();
  await page.keyboard.press('Enter');
  await page.locator('#buchungsstatus').filter({ hasText: 'Korrektur nur per Storno' }).waitFor();
  ist(await page.locator('#konto').isDisabled() && await page.locator('#speichern').isHidden(), 'festgeschrieben: nur ansehen');
  await bild('9-ansehen');
  await page.keyboard.press('Escape').catch(() => {});
  await page.locator('#abbrechen').click();

  // Kontoblatt
  await page.locator('#buchungsliste tr[data-id]').nth(1).waitFor();
  await page.locator('#buchungsliste tr[data-id]').nth(1).click();
  await page.locator('#kontoblatt').click();
  await page.locator('#kontoblatt h2').waitFor();
  await page.locator('#kb-konto').fill('1200');
  await page.locator('#kb-konto').press('Tab');
  await page.locator('#kontoblatt h2').filter({ hasText: '1200 Bank' }).waitFor();
  const letzte = await page.locator('#kontoblatt tr.saldozeile').last().innerText();
  ist(/Endsaldo/.test(letzte) && /276,20 S/.test(letzte), `Endsaldo der Bank: ${letzte}`);
  await bild('10-kontoblatt');

  await page.getByRole('button', { name: 'Abmelden' }).click();
  await page.locator('#anmelden').waitFor();
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
