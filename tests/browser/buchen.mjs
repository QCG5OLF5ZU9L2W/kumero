// SPDX-License-Identifier: AGPL-3.0-or-later
// Browsertest des Moduls buchen (BUCHUNGSMASKE.md, F83): Einrichtung, Geschäftsvorfälle, Buchungserfassung
// mit Tastatur, Kontenvorschau, Datumsfeld, Rechnen im Betragsfeld, Splitbuchung, Stapelfreigabe, Storno,
// feste Ansicht, Kontoblatt, Profil und Einstellungen (0.7.1: M9–M15, PROFIL.md).
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

  // Kontenvorschau beim Tippen (M9): Nummernanfang oder Teil der Bezeichnung, Enter übernimmt und geht weiter
  await page.locator('#konto').focus();
  await page.keyboard.type('12');
  await page.locator('.kontovorschau li').filter({ hasText: 'Bank' }).waitFor();
  await bild('4a-kontenvorschau');
  await page.keyboard.press('Enter');
  ist(await page.locator('#konto').inputValue() === '1200', 'Vorschau: Enter übernimmt den ersten Treffer');
  ist(await page.evaluate(() => document.activeElement?.id) === 'betrag', 'und geht zum nächsten Feld');
  await page.locator('.konto-saldo').filter({ hasText: 'Saldo 109,43 S' }).waitFor();
  await page.locator('#konto').focus();
  await page.keyboard.press('Control+a');
  await page.keyboard.type('hono');
  await page.locator('.kontovorschau li.gewaehlt').filter({ hasText: '8100' }).waitFor();
  ist(await page.locator('#konto').locator('xpath=ancestor::div[contains(@class,"formzeile")]').locator('.konto-saldo').innerText() === '', 'kein Saldo des vorigen Kontos, solange kein Konto dasteht');
  await page.keyboard.press('Escape');
  ist(await page.locator('.kontovorschau').isHidden() && await page.locator('#erfassung').count() === 1, 'Esc schließt nur die Vorschau');
  await page.locator('#konto').fill('');

  // Datumsfeld (M11, M15): Schreibweise der Umgebung, Kurzeingabe, ein Halt – danach der Kalender, dann das nächste Feld
  const jahrJetzt = new Date().getFullYear();
  await page.locator('#belegdatum').focus();
  await page.keyboard.press('Control+a');
  await page.keyboard.type('0203');
  await page.keyboard.press('Tab');
  ist(await page.locator('#belegdatum').inputValue() === `02.03.${jahrJetzt}`, `Kurzeingabe 0203 wird 02.03.${jahrJetzt}`);
  ist(await page.locator('#buchungsdatum').inputValue() === `02.03.${jahrJetzt}`, 'Buchungsdatum folgt dem Belegdatum (M3)');
  ist(await page.evaluate(() => document.activeElement?.classList.contains('datum-knopf')), 'nach dem Datum kommt der Kalender');
  await page.keyboard.press('Tab');
  ist(await page.evaluate(() => document.activeElement?.id) === 'buchungsdatum', 'dann das nächste Feld – ein Halt je Datum');
  await page.locator('#belegdatum').focus();
  await page.keyboard.press('ArrowUp');
  ist(await page.locator('#belegdatum').inputValue() === `03.03.${jahrJetzt}`, 'Pfeil hoch: nächster Tag');
  await page.locator('#belegdatum').fill('');
  await page.locator('#belegdatum').type('99.99.');
  await page.keyboard.press('Tab');
  await page.locator('.datumfeld .feldhinweis').filter({ hasText: 'Kein gültiges Datum.' }).waitFor();
  const heuteText = new Intl.DateTimeFormat('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' }).format(new Date());
  await page.locator('#belegdatum').fill(heuteText);
  await page.locator('#belegdatum').press('Tab');
  ist(await page.locator('#buchungsdatum').inputValue() === heuteText, 'wieder heute');
  // Hilfen beim Überfahren (M13) und Belegnummer ohne stilles Abschneiden
  ist((await page.locator('#buchen').getAttribute('title')).includes('Strg+Umschalt+Enter'), 'Hilfe am Knopf nennt das Tastenkürzel');
  ist((await page.locator('#konto').getAttribute('title')).includes('F2'), 'Hilfe am Feld');
  await page.locator('#belegnummer').fill('https://paperless.example.org/documents/5210/');
  await page.locator('.feldhinweis').filter({ hasText: 'Ein Link gehört in das Feld „Beleg / Link“.' }).waitFor();
  await page.locator('#belegnummer').fill('x'.repeat(37));
  await page.locator('.feldhinweis').filter({ hasText: 'Höchstens 36 Zeichen' }).waitFor();
  await page.locator('#belegnummer').fill('RE-0815');

  // Entwurf mit Strg+Enter; Belegnummer hochzählen; Soll und Haben umkehren mit F4
  await page.locator('#konto').fill('1200');
  await page.locator('#betrag').fill('23,80');
  await page.locator('#gegenkonto').fill('4930');
  await page.locator('#gegenkonto').press('F4');
  ist(await page.locator('#konto').inputValue() === '4930' && await page.locator('#gegenkonto').inputValue() === '1200', 'F4 kehrt Soll und Haben um');
  await page.getByTitle('Belegnummer hochzählen').click();
  ist(await page.locator('#belegnummer').inputValue() === 'RE-0816', 'Belegnummer hochgezählt');
  // Beleg als ganzer Link (F88): geprüfte Schreibweise, Host wird klein geschrieben, Link zum Öffnen
  await page.locator('#beleg').fill('https://paperless.example.org/documents/5210/?x=1');
  await page.locator('.feldhinweis').filter({ hasText: 'Kein gültiger Link' }).waitFor();
  await page.locator('#beleg').fill('https://Paperless.Example.org/documents/5210/');
  await page.locator('#beleg').press('Tab');
  ist(await page.locator('#beleg').inputValue() === 'https://paperless.example.org/documents/5210/', 'Link: Host klein geschrieben');
  ist(await page.locator('.beleg-oeffnen').getAttribute('href') === 'https://paperless.example.org/documents/5210/', 'Link zum Öffnen neben dem Feld');
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
  await page.locator('#festgeschrieben').filter({ hasText: 'Festgeschrieben – Korrektur nur per Storno' }).waitFor();
  ist(await page.locator('#erfassung').count() === 0 && await page.locator('#festgeschrieben input').count() === 0, 'festgeschrieben: feste Ansicht ohne Eingabefelder (M14)');
  ist(/^Hash [0-9a-f]{64}$/.test(await page.locator('#festgeschrieben .hash').innerText()), 'mit Hash');
  ist(await page.locator('#fest-belege a').getAttribute('href') === 'https://paperless.example.org/documents/5210/', 'Beleg als Link an der festgeschriebenen Buchung (F88)');
  ist((await page.locator('#fest-belege').innerText()).includes('ungeprüft'), 'ohne Prüfsummen als ungeprüft gekennzeichnet');
  await bild('9-ansehen');
  await page.keyboard.press('Escape');

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

  // Profil und Einstellungen (PROFIL.md): Betragseingabe kaufmännisch, Schriftgröße, Sprache
  await page.locator('#nutzermenue summary').click();
  await page.locator('#zum-profil').click();
  await page.locator('#kern-betragseingabe').waitFor();
  ist(await page.locator('#profil-sprache').inputValue() === '', 'Sprache nicht gesetzt: wie der Browser');
  await page.locator('#kern-betragseingabe').selectOption('kaufmaennisch');
  await meldung('Gespeichert.');
  await page.locator('#kern-schriftgroesse').selectOption('gross');
  await page.waitForFunction(() => document.documentElement.classList.contains('schrift-gross'));
  ist(true, 'Schriftgröße wirkt sofort');
  await page.locator('#profil-sprache').selectOption('de');
  await page.locator('#profil-datumsbeispiel').filter({ hasText: heuteText }).waitFor();
  await bild('11-profil');
  await nav('Buchungserfassung');
  await page.locator('#erfassung').waitFor();
  await page.locator('#betrag').focus();
  await page.keyboard.type('1000');
  await page.keyboard.press('Tab');
  ist(await page.locator('#betrag').inputValue() === '10,00', 'kaufmännisch: 1000 wird 10,00');
  await page.locator('#betrag').fill('100+9,43');
  await page.locator('#betrag').press('Tab');
  ist(await page.locator('#betrag').inputValue() === '109,43', 'Rechnen im Feld auch kaufmännisch');
  await page.locator('#betrag').fill('123456,7');
  await page.locator('#betrag').press('Tab');
  ist(await page.locator('#betrag').inputValue() === '123.456,70', 'Anzeige mit Tausenderpunkt');
  await bild('12-erfassung-neu');

  // 0.7.10 – Steuerkonten (F101): Satz am Konto, die Splitbuchung rechnet aus dem Brutto; die Summe bleibt der Rechnungsbetrag
  await nav('Buchen einrichten');
  await page.locator('#steuerkonten').waitFor();
  await page.locator('#steuer-neu-konto').fill('1400');
  await page.locator('#steuer-neu-satz').fill('19');
  await page.locator('#steuer-speichern').click();
  await meldung('Steuersätze gespeichert.');
  ist(await page.locator('input[data-steuerkonto="1400"]').inputValue() === '19', 'Steuersatz steht am Konto');
  await nav('Buchungserfassung');
  await page.locator('#konto').fill('4930');
  await page.locator('#betrag').fill('11900');
  await page.locator('#gegenkonto').fill('1200');
  await page.locator('#gegenkonto').press('Tab');
  await page.locator('#aufteilen').click();
  const sz = (zeile, spalte) => page.locator('.split-tabelle tbody tr').nth(zeile).locator('td').nth(spalte).locator('input').first();
  await sz(2, 0).fill('1400');
  await sz(2, 0).press('Tab');
  await page.locator('#split-rest.ausgeglichen').waitFor();
  ist(await sz(0, 2).inputValue() === '100,00' && await sz(2, 2).inputValue() === '19,00' && await sz(1, 3).inputValue() === '119,00', `Vorsteuer aus dem Brutto gerechnet: ${await sz(0, 2).inputValue()} / ${await sz(2, 2).inputValue()} / ${await sz(1, 3).inputValue()}`);
  // weitere Aufwandszeile: Die Restzeile gleicht aus, die Summe bleibt 119,00
  await page.locator('#split-zeile').click();
  await sz(3, 0).fill('4930');
  await sz(3, 2).fill('4000');
  await sz(3, 2).press('Tab');
  await page.locator('#split-rest.ausgeglichen').waitFor();
  ist(await sz(0, 2).inputValue() === '60,00' && await sz(2, 2).inputValue() === '19,00', `Rest in der ersten Zeile: ${await sz(0, 2).inputValue()}`);
  await bild('13-split-steuer');
  // netto: Steuer und Zahlbetrag werden dazugerechnet
  await page.locator('#split-netto').check();
  await sz(0, 2).fill('10000');
  await sz(0, 2).press('Tab');
  await page.locator('#split-rest.ausgeglichen').waitFor();
  ist(await sz(2, 2).inputValue() === '26,60' && await sz(1, 3).inputValue() === '166,60', `netto: Steuer ${await sz(2, 2).inputValue()}, Zahlbetrag ${await sz(1, 3).inputValue()}`);
  await page.locator('#abbrechen').click();

  await page.locator('#nutzermenue summary').click();
  await page.locator('#abmelden').click();
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
