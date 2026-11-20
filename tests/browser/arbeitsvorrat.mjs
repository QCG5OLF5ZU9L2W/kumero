// SPDX-License-Identifier: AGPL-3.0-or-later
// Browsertest des Moduls arbeitsvorrat (ARBEITSVORRAT.md, F89): Posteingang, Eintrag von Hand, Regeln,
// vorbefüllte Buchungserfassung mit Sprung zum nächsten Eintrag, Verwerfen und Wieder öffnen, Zähler im Menü.
//
//   … node tests/browser/arbeitsvorrat.mjs [Bildverzeichnis]   (Umgebung wie tests/browser/buchen.mjs)
import { spawn, execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { mkdirSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const require = createRequire(import.meta.url);
const { chromium } = require(process.env.PLAYWRIGHT || 'playwright');
const wurzel = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const PORT = 8099;
const ADRESSE = `http://localhost:${PORT}`;
const bilder = process.argv[2] || null;
if (bilder) mkdirSync(bilder, { recursive: true });

let pruefungen = 0;
function ist(b, was) { if (!b) throw new Error(`fehlgeschlagen: ${was}`); pruefungen++; }

const umgebung = JSON.parse(execFileSync('php', [path.join(wurzel, 'tests/browser/aufbau.php'), ADRESSE, '--buchen', '--vorrat'], { encoding: 'utf8' }).trim().split('\n').pop());
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
  ist(bereiche.includes('Buchführung'), `Bereiche im Menü: ${bereiche}`);


  const heuteText = new Intl.DateTimeFormat('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' }).format(new Date());
  // Buchen einrichten und einen Geschäftsvorfall für die Regel anlegen
  await nav('Buchen einrichten');
  await page.locator('#schnell-knopf').click();
  await page.locator('#einrichtung-bereit').waitFor();
  await nav('Geschäftsvorfälle');
  await page.locator('#v-gruppe').fill('Zahlungsausgang');
  await page.locator('#v-name').fill('Rechnung per Bank');
  await page.locator('#v-gegenkonto').fill('1200');
  await page.locator('#vorlage-speichern').click();
  await meldung('Vorlage gespeichert.');

  // Arbeitsvorrat: leerer Posteingang, Eintrag von Hand (Einfg)
  await nav('Arbeitsvorrat');
  await page.locator('#vorratsliste tr.leer').filter({ hasText: 'Der Arbeitsvorrat ist leer' }).waitFor();
  ist(await page.locator('.nav-zaehler:visible').count() === 0, 'kein Zähler bei leerem Vorrat');
  await bild('1-vorrat-leer');
  await page.locator('#vorrat-neu').click();
  await page.locator('#neu-speichern').click();
  await page.locator('#vorrat-dialog .fehler-text').filter({ hasText: 'Der Betreff fehlt.' }).waitFor();
  await page.locator('#neu-betreff').fill('Rechnung Musterlieferant RE-1');
  await page.locator('#neu-art').fill('rechnung');
  await page.locator('#neu-gegenpartei').fill('Musterlieferant GmbH');
  await page.locator('#neu-datum').fill(heuteText);
  await page.locator('#neu-betrag').fill('109,43');
  await page.locator('#neu-zahlweise').selectOption('bar');
  await page.locator('#neu-belegnummer').fill('RE-1');
  await page.locator('#neu-link').fill('https://paperless.example.org/documents/5210/');
  await page.locator('#neu-notiz').fill('Bitte prüfen (Muster)');
  await bild('2-vorrat-neu');
  await page.locator('#neu-speichern').click();
  await meldung('Eintrag angelegt.');
  await page.locator('#vorratsliste tr[data-id]').first().waitFor();
  await page.locator('#vorrat-neu').click();
  await page.locator('#neu-betreff').fill('Abbuchung Mustertelefon');
  await page.locator('#neu-betrag').fill('39,90');
  await page.locator('#neu-zahlweise').selectOption('unbar');
  await page.locator('#neu-speichern').click();
  await meldung('Eintrag angelegt.');
  await page.locator('#vorratsliste tr[data-id]').nth(1).waitFor();
  ist(await page.locator('#vorratsliste tr[data-id]').count() === 2, 'zwei Einträge im Posteingang');
  ist((await page.locator('#vorrat-fuss').innerText()).includes('149,33'), 'Summe der Beträge im Fuß');
  await page.locator('.nav-zaehler').filter({ hasText: '2' }).waitFor();
  ist(true, 'Zähler im Menü zeigt zwei offene Einträge');
  await bild('3-vorrat-liste');

  // Regel: Art rechnung → Geschäftsvorfall
  await nav('Regeln für den Arbeitsvorrat');
  await page.locator('#regel-art').fill('rechnung');
  await page.locator('#regel-vorlage').selectOption({ label: 'Zahlungsausgang – Rechnung per Bank' });
  await page.locator('#regel-speichern').click();
  await meldung('Regel gespeichert.');
  ist(await page.locator('#regeln-arten tbody tr td.nr').first().innerText() === 'rechnung', 'Regel steht in der Liste');

  // Buchen aus dem Arbeitsvorrat: Maske vorbefüllt aus Eintrag und Regel
  await nav('Arbeitsvorrat');
  await page.locator('#vorratsliste tr[data-id]').first().click();
  await page.keyboard.press('Enter');
  await page.locator('#vorrat-angaben').filter({ hasText: 'Rechnung Musterlieferant RE-1' }).waitFor();
  ist(await page.locator('#betrag').inputValue() === '109,43', 'Betrag aus dem Eintrag');
  ist(await page.locator('#gegenkonto').inputValue() === '1200', 'Gegenkonto aus der Regel Art → Geschäftsvorfall');
  ist(await page.locator('#belegnummer').inputValue() === 'RE-1', 'Belegnummer aus dem Eintrag');
  ist(await page.locator('#text').inputValue() === 'Musterlieferant GmbH RE-1', 'Buchungstext aus Gegenpartei und Belegnummer');
  ist(await page.locator('#beleg').inputValue() === 'https://paperless.example.org/documents/5210/', 'Beleglink aus dem Eintrag');
  ist(await page.locator('#belegdatum').inputValue() === heuteText && await page.locator('#buchungsdatum').inputValue() === heuteText, 'bar: Buchungsdatum = Belegdatum');
  ist((await page.locator('#vorrat-angaben').innerText()).includes('Bitte prüfen (Muster)'), 'Angaben der Quelle stehen neben der Maske');
  await bild('4-maske-vorbefuellt');
  await page.locator('#konto').fill('4930');
  await page.locator('#konto').press('Tab');
  await page.locator('#buchen').click();
  // nach dem Buchen: nächster offener Eintrag, die Meldung bleibt stehen
  await page.locator('#vorrat-angaben').filter({ hasText: 'Abbuchung Mustertelefon' }).waitFor();
  await meldung('Gebucht als Nr.');
  ist(await page.locator('#betrag').inputValue() === '39,90', 'nächster Eintrag: Betrag');
  ist(await page.locator('#buchungsdatum').inputValue() === '', 'unbar ohne Zahltag: Buchungsdatum bleibt leer');
  await page.locator('#geldfluss-hinweis').filter({ hasText: 'Tag des Geldflusses eintragen' }).waitFor();
  ist(await page.locator('#konto').inputValue() === '' && await page.locator('#gegenkonto').inputValue() === '', 'ohne Regel keine Konten');
  await bild('5-maske-naechster');
  await page.locator('#vorrat-ueberspringen').click();
  await meldung('Es gibt keinen weiteren offenen Eintrag.');
  ist(await page.locator('#vorrat-angaben').isHidden(), 'kein weiterer Eintrag: leere Maske');

  // Der gebuchte Eintrag steht unter „Gebucht“, das Konto der Gegenpartei ist gemerkt
  await nav('Arbeitsvorrat');
  await page.locator('#vorratsliste tr[data-id]').first().waitFor();
  ist(await page.locator('#vorratsliste tr[data-id]').count() === 1, 'ein Eintrag bleibt offen');
  await page.locator('#vorrat-ansicht').selectOption('gebucht');
  await page.locator('#vorratsliste tr[data-id]').filter({ hasText: 'Rechnung Musterlieferant RE-1' }).waitFor();
  ist(await page.locator('#vorrat-buchen').innerText() === 'Buchung öffnen', 'gebuchter Eintrag führt zur Buchung');
  await nav('Regeln für den Arbeitsvorrat');
  await page.locator('#regeln-gegenparteien tbody tr').filter({ hasText: 'Musterlieferant GmbH' }).filter({ hasText: '4930' }).waitFor();
  ist(true, 'Konto der Gegenpartei gemerkt');
  await bild('6-regeln');

  // Verwerfen mit Grund, wieder öffnen
  await nav('Arbeitsvorrat');
  await page.locator('#vorratsliste tr[data-id]').first().click();
  await page.locator('#vorrat-verwerfen').click();
  await page.locator('#verwerfen-ja').click();
  await page.locator('dialog .fehler-text').filter({ hasText: 'Bitte einen Grund angeben.' }).waitFor();
  await page.locator('#verwerfen-grund').fill('doppelt');
  await page.locator('#verwerfen-ja').click();
  await meldung('Eintrag verworfen.');
  await page.locator('#vorratsliste tr.leer').waitFor();
  await page.locator('#vorrat-ansicht').selectOption('verworfen');
  await page.locator('#vorratsliste tr[data-id]').filter({ hasText: 'Abbuchung Mustertelefon' }).waitFor();
  await page.locator('#vorratsliste tr[data-id]').first().click();
  await page.locator('#vorrat-oeffnen').click();
  await meldung('Eintrag liegt wieder im Arbeitsvorrat.');
  await page.locator('#vorrat-ansicht').selectOption('offen');
  await page.locator('#vorratsliste tr[data-id]').filter({ hasText: 'Abbuchung Mustertelefon' }).waitFor();
  ist(true, 'verworfen und wieder geöffnet');
  await bild('7-vorrat-ende');

  // 0.7.6 – Spaltenfilter (F91), Konten in der Liste (X7), Massenauswahl, Stapelvorbelegung (X1), Übergabe in den Stapel (X1a)
  await page.locator('#vorrat-neu').click();
  await page.locator('#neu-betreff').fill('Büromaterial Musterhandel');
  await page.locator('#neu-art').fill('kassenbon');
  await page.locator('#neu-datum').fill(heuteText);
  await page.locator('#neu-betrag').fill('12,50');
  await page.locator('#neu-zahlweise').selectOption('bar');
  await page.locator('#neu-speichern').click();
  await meldung('Eintrag angelegt.');
  await page.locator('#vorratsliste tr[data-id]').nth(1).waitFor();
  // Filter je Spalte: einen Wert finden, einen Wert ausschließen
  await page.locator('details[data-spalte=art] summary').click();
  await page.locator('details[data-spalte=art] li').filter({ hasText: 'kassenbon' }).locator('.sp-ein').click();
  ist(await page.locator('#vorratsliste tr[data-id]').count() === 1, 'Filter „finden“: nur die Art kassenbon');
  ist((await page.locator('details[data-spalte=art] summary').innerText()).includes('gefiltert'), 'aktiver Filter ist am Spaltenkopf benannt');
  await page.locator('details[data-spalte=art] li').filter({ hasText: 'kassenbon' }).locator('.sp-aus').click();
  ist(await page.locator('#vorratsliste tr[data-id]').count() === 1 && (await page.locator('#vorratsliste tr[data-id]').innerText()).includes('Abbuchung Mustertelefon'), 'Filter „ausschließen“: alles außer kassenbon');
  await page.locator('details[data-spalte=art] .sp-aufheben').click();
  await page.locator('h1, h2').first().click();
  ist(await page.locator('#vorratsliste tr[data-id]').count() === 2, 'Filter aufgehoben');
  // Sortieren: Klick auf den Spaltentitel
  await page.locator('th.sp-kopf .sp-titel').filter({ hasText: 'Betrag' }).click();
  ist((await page.locator('#vorratsliste tr[data-id]').first().innerText()).includes('12,50'), 'aufsteigend nach Betrag sortiert');
  await bild('8-vorrat-filter');
  // Konto direkt in der Zeile wählen
  const zeile = page.locator('#vorratsliste tr[data-id]').filter({ hasText: 'Büromaterial Musterhandel' });
  await zeile.locator('input[data-feld=konto]').fill('4930');
  await zeile.locator('input[data-feld=konto]').press('Tab');
  await page.waitForFunction(() => document.querySelector('#vorratsliste input[data-feld=konto]')?.title === 'Bürobedarf');
  ist(true, 'Konto in der Liste gewählt und gespeichert');
  // Massenauswahl und Stapelvorbelegung des Gegenkontos
  await page.locator('#vorrat-alle').click();
  ist((await page.locator('#vorrat-vorbelegen').innerText()) === 'Konten vorbelegen (2)', 'alle angezeigten Einträge markiert');
  await page.locator('#vorrat-vorbelegen').click();
  await page.locator('#stapel-gegenkonto').fill('1200');
  await bild('9-stapelvorbelegung');
  await page.locator('#stapel-ja').click();
  await meldung('2 Einträge vorbelegt.');
  await page.locator('#vorratsliste tr[data-id]').nth(1).waitFor();
  await page.waitForFunction(() => [...document.querySelectorAll('#vorratsliste input[data-feld=gegenkonto]')].length === 2 && [...document.querySelectorAll('#vorratsliste input[data-feld=gegenkonto]')].every((e) => e.value === '1200'));
  ist(true, 'Gegenkonto steht an den Einträgen');
  // Übergabe in den Stapel: vollständige gehen freigabebereit hinüber, unvollständige bleiben und werden genannt
  ist((await page.locator('#vorrat-stapel').innerText()) === 'In den Stapel (2)', 'die Markierung bleibt nach dem Vorbelegen erhalten');
  await page.locator('#vorrat-stapel').click();
  await meldung('1 Einträge liegen freigabebereit im Stapel.');
  ist((await page.locator('.meldung').first().innerText()).includes('Abbuchung Mustertelefon'), 'der unvollständige Eintrag bleibt im Vorrat und wird genannt');
  await page.locator('#vorratsliste tr[data-id]').first().waitFor();
  ist(await page.locator('#vorratsliste tr[data-id]').count() === 1, 'ein Eintrag bleibt offen');
  // 0.7.8 – ohne Haken gelten die Aktionen für die gewählte Zeile; die Vorbelegung lässt sich wieder leeren
  await page.locator('#vorratsliste tr[data-id] input[type=checkbox]').first().uncheck();
  await page.locator('#vorratsliste tr[data-id]').first().click({ position: { x: 5, y: 5 } });
  ist(await page.locator('#vorratsliste tr[data-id] input[type=checkbox]:checked').count() === 0 && !(await page.locator('#vorrat-stapel').isDisabled()), 'ohne Haken: „In den Stapel“ gilt für die gewählte Zeile');
  ist((await page.locator('#vorratsliste input[data-feld=gegenkonto]').first().inputValue()) === '1200', 'Gegenkonto ist noch vorbelegt');
  await page.locator('#vorrat-leeren').click();
  await meldung('Vorbelegung bei 1 Einträgen gelöscht.');
  await page.waitForFunction(() => document.querySelector('#vorratsliste input[data-feld=gegenkonto]')?.value === '');
  ist(true, 'Vorbelegung der gewählten Zeile geleert');
  await page.locator('#vorrat-stapel').click();
  await meldung('0 Einträge liegen freigabebereit im Stapel.');
  ist(await page.locator('#vorratsliste tr[data-id]').count() === 1, 'ohne Konten bleibt der Eintrag im Vorrat');
  await nav('Buchungsliste');
  await page.locator('#ansicht').selectOption('stapel');
  await page.locator('#buchungsliste tr[data-id]').first().waitFor();
  await page.locator('#alle-markieren').check();
  ist(await page.locator('#buchungsliste tr[data-id] input[type=checkbox]:not(:checked)').count() === 0, 'Haken im Kopf markiert alle Entwürfe');
  await page.locator('#alle-markieren').uncheck();
  ist(await page.locator('#buchungsliste tr[data-id] input[type=checkbox]:checked').count() === 0, 'und hebt die Markierung wieder auf');
  // Spaltenfilter auch in der Buchungsliste (F91): Wert ausschließen leert die Liste, Aufheben bringt sie zurück
  const vorher = await page.locator('#buchungsliste tr[data-id]').count();
  await page.locator('#buchungsliste details[data-spalte=konto] summary').click();
  await page.locator('#buchungsliste details[data-spalte=konto] li').filter({ hasText: '4930' }).locator('.sp-aus').click();
  await page.waitForFunction((n) => document.querySelectorAll('#buchungsliste tr[data-id]').length < n, vorher);
  ist(true, 'Buchungsliste: Konto 4930 ausgeschlossen');
  await page.locator('#buchungsliste details[data-spalte=konto] li').filter({ hasText: '4930' }).locator('.sp-aus').click();
  await page.waitForFunction((n) => document.querySelectorAll('#buchungsliste tr[data-id]').length === n, vorher);
  ist(true, 'Filter aufgehoben, alle Buchungen wieder da');
  await page.keyboard.press('Escape');
  const imStapel = page.locator('#buchungsliste tr[data-id]').filter({ hasText: 'Büromaterial Musterhandel' });
  await imStapel.waitFor();
  ist((await imStapel.innerText()).includes('freigabebereit'), `im Erfassungsstapel liegt der Entwurf freigabebereit: ${await imStapel.innerText()}`);
  await bild('10-stapel');
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
