// SPDX-License-Identifier: AGPL-3.0-or-later
// Browsertest des Moduls import (IMPORT.md, F104): CSV wählen, Aufbau, Spalten, Richtung, Geldkonto, Vorschau, einliefern,
// Arbeitsvorrat mit Geldkonto als Vorauswahl, markierter Fehlerzeile und der Spalte „Doppelt?“. Nur synthetische Daten.
//
//   … node tests/browser/einlesen.mjs [Bildverzeichnis]   (Umgebung wie tests/browser/buchen.mjs)
import { spawn, execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { mkdirSync, mkdtempSync, writeFileSync } from 'node:fs';
import os from 'node:os';
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
  // Vergleichen und zusammenführen (X3, F105): links und rechts, je Feld wählbar; „Kein Duplikat“ und „Trennen“
  await abbuchung.click({ position: { x: 5, y: 5 } });
  // F106: Knöpfe werden gesperrt, nie ausgeblendet; gesperrt nennen sie den Grund
  ist(await page.locator('#vorrat-auto').isVisible() && await page.locator('#vorrat-auto').isDisabled() && (await page.locator('#vorrat-auto').getAttribute('title')).includes('nicht eingeschaltet'),
    'Zusammenführen von selbst ist nicht eingeschaltet: Knopf gesperrt, mit Grund');
  ist(await page.locator('#vorrat-trennen').isDisabled() && (await page.locator('#vorrat-trennen').getAttribute('title')).includes('kein anderer Eintrag aufgegangen'), '„Trennen“ gesperrt, mit Grund');
  const leiste = await page.locator('#vorrat-leiste-zeile').boundingBox();
  await meldung('3 Zeilen in den Arbeitsvorrat eingeliefert.').catch(() => {});
  ist(await page.evaluate(() => document.querySelector('.kopf .meldung') !== null && document.querySelector('.inhalt .meldung') === null), 'Meldungen stehen in der Statusleiste, nicht im Inhalt');
  await page.locator('#vorrat-vergleichen').click();
  await page.locator('#vergleich-dialog').waitFor();
  ist(await page.locator('#vergleich-dialog tr[data-feld=zahltag] input[type=radio]:checked').count() === 1 && (await page.locator('#vergleich-dialog tr[data-feld=zahltag]').innerText()).includes('03.03.2026'), 'Tag des Geldflusses kommt vom Umsatz');
  ist((await page.locator('#vergleich-dialog tr[data-feld=gegenpartei]').innerText()).includes('Musterlieferant GmbH') && (await page.locator('#vergleich-dialog .vgl-angaben').first().innerText()).length > 0, 'beide Seiten mit Werten und Angaben der Quelle');
  await bild('4-vergleich');
  await page.locator('#vergleich-ja').click();
  await meldung('Zusammengeführt.');
  const danach = await page.locator('#vorrat-leiste-zeile').boundingBox();
  ist(leiste.y === danach.y && leiste.x === danach.x, `die Werkzeugleiste steht nach einer Meldung an derselben Stelle: ${leiste.y} / ${danach.y}`);
  await page.waitForFunction(() => document.querySelectorAll('#vorratsliste tr[data-id]').length === 3);
  const zusammen = page.locator('#vorratsliste tr[data-id]').filter({ hasText: '⊕ 2 zusammen' });
  ist(await zusammen.count() === 1 && await zusammen.locator('input[data-feld=gegenkonto]').inputValue() === '1200', 'ein Eintrag bleibt, mit dem Geldkonto des Umsatzes');
  await zusammen.click({ position: { x: 5, y: 5 } });
  await page.locator('#vorrat-trennen').click();
  await meldung('1 Einträge wieder getrennt.');
  await page.waitForFunction(() => document.querySelectorAll('#vorratsliste tr[data-id]').length === 4);
  await page.locator('#vorratsliste tr[data-id]').filter({ hasText: 'RE-0815' }).click({ position: { x: 5, y: 5 } });
  await page.locator('#vorrat-vergleichen').click();
  await page.locator('#vergleich-kein-duplikat').click();
  await meldung('Als „kein Duplikat“ gekennzeichnet.');
  await page.waitForFunction(() => !document.querySelector('#vorratsliste')?.innerText.includes('≈ Gruppe'));
  ist(true, 'nach „Kein Duplikat“ ist die Markierung weg');
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

  // Blättern (F107): eine große Datei einlesen; die Liste zeigt eine Seite, Filter und Zähler gelten für alle Einträge
  let viele = 'Datum;Name;Buchungstext;Betrag\n';
  for (let n = 1; n <= 120; n++) viele += `${String((n % 28) + 1).padStart(2, '0')}.04.2026;Musterkunde ${n};Vorgang ${n};-${n},00\n`;
  await nav('Datei einlesen');
  await page.locator('#import-datei').setInputFiles({ name: 'viele.csv', mimeType: 'text/csv', buffer: Buffer.from(viele, 'utf8') });
  // die Seite zeigt noch die vorige Datei: warten, bis die neue gelesen ist (Kopfzeile in Zeile 1)
  await page.waitForFunction(() => document.querySelector('select[data-spalte="Buchungstext"]') !== null);
  await page.locator('#imp-geldkonto').fill('1200');
  await page.locator('#imp-vorschau').click();
  await page.locator('#imp-einliefern').click();
  await meldung('120 Zeilen in den Arbeitsvorrat eingeliefert.');
  await page.locator('#imp-zum-vorrat').click();
  await page.locator('#vorrat-blaettern-bereich').filter({ hasText: '1–100 von 124' }).waitFor();
  ist(await page.locator('#vorratsliste tr[data-id]').count() === 100, 'eine Seite mit 100 Zeilen');
  ist(await page.locator('#vorrat-blaettern-vorher').isDisabled() && (await page.locator('#vorrat-blaettern-vorher').getAttribute('title')) === 'Das ist die erste Seite.', '„Vorher“ ist auf der ersten Seite gesperrt, mit Grund');
  await page.locator('#vorrat-blaettern-weiter').click();
  await page.locator('#vorrat-blaettern-bereich').filter({ hasText: '101–124 von 124' }).waitFor();
  ist(await page.locator('#vorratsliste tr[data-id]').count() === 24 && await page.locator('#vorrat-blaettern-weiter').isDisabled(), 'zweite und letzte Seite');
  await page.locator('#vorrat-blaettern-groesse').selectOption('50');
  await page.locator('#vorrat-blaettern-bereich').filter({ hasText: '101–124 von 124' }).waitFor();
  ist((await page.locator('.blaettern-von').innerText()) === 'von 3', 'Seitengröße 50: drei Seiten, die Stelle bleibt');
  await page.locator('#vorrat-blaettern-seite').fill('2');
  await page.locator('#vorrat-blaettern-seite').press('Enter');
  await page.locator('#vorrat-blaettern-bereich').filter({ hasText: '51–100 von 124' }).waitFor();
  ist(true, 'Seite eintippen');
  await page.locator('#vorrat-suche').fill('Vorgang 12');
  await page.locator('#vorrat-blaettern-bereich').filter({ hasText: '1–2 von 2' }).waitFor();
  ist(await page.locator('#vorratsliste tr[data-id]').count() === 2, 'die Suche gilt für alle Einträge, nicht nur für die Seite – und springt auf Seite 1');
  await bild('5-blaettern');

  // Aktionen der Auswahl: Vorbelegen zeigt, was an allen gleich ist; Verwerfen gilt für alle markierten Einträge
  await page.locator('#vorrat-alle').click();
  await page.locator('#vorrat-verwerfen').filter({ hasText: 'Verwerfen (2)' }).waitFor();
  await page.locator('#vorrat-vorbelegen').click();
  const vorbelegt = await page.evaluate(() => ['konto', 'gegenkonto'].map((f) => document.getElementById(`stapel-${f}`).value).join('|'));
  ist(vorbelegt.split('|').includes('1200'), `Vorbelegen zeigt das gemeinsame Konto der Auswahl: ${vorbelegt}`);
  ist((await page.locator('#stapel-dialog .stapel-name').allInnerTexts()).some((x) => x.trim() !== ''), 'mit der Bezeichnung des Kontos');
  await page.locator('#stapel-dialog').getByText('Abbrechen').click();
  await page.locator('#vorrat-verwerfen').click();
  await page.locator('#verwerfen-dialog h2').filter({ hasText: '2 Einträge verwerfen' }).waitFor();
  await page.locator('#verwerfen-grund').fill('Testlauf');
  await page.locator('#verwerfen-ja').click();
  await meldung('2 Einträge verworfen.');
  await page.locator('#vorrat-suche').fill('');
  await page.locator('#vorrat-blaettern-bereich').filter({ hasText: 'von 122' }).waitFor();
  ist(true, 'beide markierten Einträge sind verworfen, nicht nur die gewählte Zeile');

  // Menü (F108): „Datei einlesen“ steht als Unterpunkt unter dem Arbeitsvorrat – nicht neben der Buchungsliste
  const unter = await page.evaluate(() => {
    const vorrat = [...document.querySelectorAll('nav a.nav-eintrag')].find((a) => a.textContent.trim().startsWith('Arbeitsvorrat'));
    return [...vorrat.closest('li').querySelectorAll(':scope > ul a.nav-eintrag')].map((a) => `${a.textContent.trim()}|${a.className.includes('tiefe-2')}`);
  });
  ist(JSON.stringify(unter) === JSON.stringify(['Datei einlesen|true']), `Einlesen steht unter dem Arbeitsvorrat: ${JSON.stringify(unter)}`);

  // E-Rechnungen (IMPORT.md Abschnitt 15): XRechnung als CII und UBL, eine Gutschrift, ZUGFeRD-PDF und ein PDF ohne Rechnung
  const muster = mkdtempSync(path.join(os.tmpdir(), 'kumero-erechnung-'));
  execFileSync('php', [path.join(wurzel, 'werkzeuge/e-rechnung-muster.php'), muster]);
  // derselbe Importer: Die Seite erkennt an den Dateien, dass es E-Rechnungen sind
  await nav('Datei einlesen');
  await page.locator('h1').filter({ hasText: 'Datei in den Arbeitsvorrat einlesen' }).waitFor();
  writeFileSync(path.join(muster, 'umsatz.csv'), 'Datum;Betrag\n01.03.2026;1,00\n');
  await page.locator('#import-datei').setInputFiles([path.join(muster, 'zugferd.pdf'), path.join(muster, 'umsatz.csv')]);
  await meldung('Die Auswahl enthält E-Rechnungen und andere Dateien.');
  ist(await page.locator('#rechnung-teil').count() === 0, 'gemischte Auswahl wird nicht geraten');
  await page.locator('#import-datei').setInputFiles(['xrechnung-cii.xml', 'xrechnung-ubl.xml', 'gutschrift-ubl.xml', 'zugferd.pdf', 'zwei-saetze.pdf', 'ohne-rechnung.pdf'].map((n) => path.join(muster, n)));
  await page.locator('#rechnung-einliefern').filter({ hasText: '5 Rechnungen in den Arbeitsvorrat einliefern' }).waitFor();
  const zeile = (text) => page.locator('#rechnung-liste tbody tr').filter({ hasText: text });
  ist((await zeile('xrechnung-cii.xml').innerText()).replace(/\s+/g, ' ').includes('XRechnung, CII Rechnung · Ausgabe Musterlieferant GmbH RE-MUSTER-1001'), 'XRechnung (CII) gelesen');
  ist(/100,00\s+19,00\s+19 %: 19,00 auf 100,00\s+119,00/.test(await zeile('xrechnung-cii.xml').innerText()), 'Netto, Steuer, Steuer je Satz und Brutto');
  ist((await zeile('gutschrift-ubl.xml').innerText()).replace(/\s+/g, ' ').includes('Gutschrift · Einnahme'), 'die Gutschrift des Lieferanten ist eine Einnahme');
  ist((await zeile('zugferd.pdf').innerText()).includes('ZUGFeRD / Factur-X (Extended), CII im PDF'), 'ZUGFeRD: XML aus dem PDF');
  await zeile('ohne-rechnung.pdf').locator('[data-stand="abgelehnt"]').waitFor();
  ist((await zeile('ohne-rechnung.pdf').innerText()).includes('nicht lesbar: Das PDF enthält kein eingebettetes Rechnungs-XML'), 'ein PDF ohne Rechnung wird mit Grund abgelehnt');
  ist((await zeile('zwei-saetze.pdf').innerText()).replace(/\s+/g, ' ').includes('19 %: 19,00 auf 100,00 7 %: 3,50 auf 50,00'), 'Steuer je Satz steht schon in der Prüftabelle');
  ist((await zeile('zwei-saetze.pdf').innerText()).includes('ZUGFeRD / Factur-X (EN 16931), CII im PDF') && (await zeile('zwei-saetze.pdf').innerText()).includes('1: Musterartikel'), 'Format und Positionen');
  await bild('6-e-rechnung');
  // Ausgangsrechnungen: Gegenpartei ist der Empfänger
  await page.locator('#rechnung-seite').selectOption('ausgang');
  await zeile('xrechnung-cii.xml').filter({ hasText: 'Rechnung · Einnahme' }).waitFor();
  ist((await zeile('xrechnung-cii.xml').innerText()).includes('Musterpraxis (Test)'), 'als Ausgangsrechnung: Empfänger als Gegenpartei, Richtung Einnahme');
  await page.locator('#rechnung-seite').selectOption('eingang');
  await zeile('xrechnung-cii.xml').filter({ hasText: 'Rechnung · Ausgabe' }).waitFor();
  await page.locator('#rechnung-einliefern').filter({ hasText: '5 Rechnungen' }).click();
  await meldung('5 Rechnungen in den Arbeitsvorrat eingeliefert.');
  ist(await page.locator('#rechnung-liste [data-stand="eingeliefert"]').count() === 5, 'fünf eingeliefert');
  await page.waitForFunction(() => [...document.querySelectorAll('#import-verlauf tbody tr')].filter((z) => z.textContent.includes('E-Rechnung')).length === 5);
  ist(true, 'fünf E-Rechnungen im Verlauf der Importe');
  // dieselben Dateien noch einmal: nichts Neues
  await page.locator('#import-datei').setInputFiles([path.join(muster, 'zugferd.pdf')]);
  await page.locator('#rechnung-liste [data-stand="vorhanden"]').waitFor();
  ist(await page.locator('#rechnung-einliefern').isDisabled(), 'schon vorhanden, Original liegt in der Zwischenablage: nichts einzuliefern');
  // dieselbe Rechnung kam erst als XML: Das PDF wird für die Vorschau nachgeliefert
  writeFileSync(path.join(muster, 'cii-als.pdf'), execFileSync('php', ['-r', `require '${path.join(wurzel, 'werkzeuge/e-rechnung-muster.php')}'; echo ERechnungMuster::pdf(ERechnungMuster::cii());`]));
  await page.locator('#import-datei').setInputFiles([path.join(muster, 'cii-als.pdf')]);
  await page.locator('#rechnung-liste [data-stand="nachliefern"]').waitFor();
  await page.locator('#rechnung-einliefern').click();
  await page.locator('#rechnung-liste [data-stand="nachgeliefert"]').waitFor();
  ist(true, 'PDF zu einem Eintrag ohne Original nachgeliefert');
  await nav('Arbeitsvorrat');
  await page.locator('#vorrat-blaettern-bereich').filter({ hasText: 'von 127' }).waitFor();
  await page.locator('#vorrat-suche').fill('RE-MUSTER-4004');
  const e4 = page.locator('#vorratsliste tr[data-id]').filter({ hasText: 'Mustertechnik KG' });
  await e4.waitFor();
  ist((await e4.innerText()).includes('238,00') && (await e4.innerText()).includes('rechnung'), 'die ZUGFeRD-Rechnung liegt im Arbeitsvorrat');
  await bild('7-e-rechnung-vorrat');

  // Zwei Steuersätze (F109): mit Steuerkonten je Satz wird in der Maske je Satz aufgeteilt
  const api = (methode, pfad, json) => page.evaluate(async ([m, p, j]) => {
    const r = await fetch(`/m/browserpraxis/api/v1${p}`, { method: m, credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'Idempotency-Key': crypto.randomUUID() }, body: JSON.stringify(j) });
    return r.status;
  }, [methode, pfad, json]);
  ist(await api('POST', '/konten', { nr: '1401', bezeichnung: 'Abziehbare Vorsteuer 7 %', kontoart: 'aktiv' }) === 201, 'Konto für 7 % angelegt');
  ist(await api('PUT', '/buchen/steuerkonten', { saetze: { 1400: '19.00', 1401: '7.00' } }) === 200, 'Steuersätze an den Konten');
  await page.reload();
  await nav('Arbeitsvorrat');
  await page.locator('#vorrat-suche').fill('RE-MUSTER-5005');
  const e5 = page.locator('#vorratsliste tr[data-id]').filter({ hasText: 'Mustermarkt eG' });
  await e5.click();
  for (const [feld, nr] of [['konto', '4930'], ['gegenkonto', '1200']]) {
    await e5.locator(`input[data-feld="${feld}"]`).fill(nr);
    await e5.locator(`input[data-feld="${feld}"]`).dispatchEvent('change');
  }
  await page.locator('#vorrat-vorbelegen').click();
  await page.locator('#stapel-steuerkonto').fill('1400');
  await page.locator('#stapel-ja').click();
  await meldung('1 Einträge vorbelegt.');
  await e5.locator('td').nth(3).click();
  await page.locator('#vorrat-buchen').click();
  await page.locator('.split-tabelle tbody tr').nth(4).waitFor();
  await page.locator('#split-rest.ausgeglichen').waitFor();
  await page.waitForFunction(() => [...document.querySelectorAll('.split-tabelle tbody tr')].filter((tr) => [...tr.querySelectorAll('input')].some((i) => i.value.includes(','))).length >= 5);
  const split = await page.evaluate(() => [...document.querySelectorAll('.split-tabelle tbody tr')].map((tr) => [...tr.querySelectorAll('input')].map((i) => i.value).filter((w) => w !== '').join(' ')));
  ist(JSON.stringify(split.slice(0, 5).map((z) => z.replace(/\s+/g, ' '))) === JSON.stringify(['4930 100,00', '1400 19,00', '4930 50,00', '1401 3,50', '1200 172,50']), `Split je Steuersatz: ${JSON.stringify(split)}`);
  await page.locator('#split-rest.ausgeglichen').waitFor();
  ist(true, 'die Splitbuchung ist ausgeglichen');
  await bild('8-zwei-steuersaetze');
  // derselbe Eintrag über den Stapel: fünf Zeilen, je Satz eine Sach- und eine Steuerzeile
  await nav('Arbeitsvorrat');
  await page.locator('#vorrat-suche').fill('RE-MUSTER-5005');
  await e5.locator('td').nth(3).click();
  await page.locator('#vorrat-stapel').click();
  await meldung('1 Einträge liegen freigabebereit im Stapel.');
  const stapelZeilen = await page.evaluate(async () => {
    const holen = async (p) => (await fetch(`/m/browserpraxis/api/v1${p}`, { headers: { Accept: 'application/json' } })).json();
    const liste = await holen('/buchungen');
    const b = (liste.buchungen || liste.eintraege || liste).find((x) => (x.text || '').includes('RE-MUSTER-5005'));
    return (await holen(`/buchungen/${b.id}`)).zeilen.map((z) => `${z.konto} ${z.seite} ${z.betrag}`);
  });
  ist(JSON.stringify(stapelZeilen) === JSON.stringify(['4930 S 100.00', '1400 S 19.00', '4930 S 50.00', '1401 S 3.50', '1200 H 172.50']), `Stapel je Steuersatz: ${JSON.stringify(stapelZeilen)}`);
  // Der XML-Namensraum steuert die Auswahl auch bei einer neutralen Dateiendung.
  await nav('Datei einlesen');
  for (const [typ, container, bericht] of [['053', 'BkToCstmrStmt', 'Stmt'], ['054', 'BkToCstmrDbtCdtNtfctn', 'Ntfctn']]) {
    const xml = `<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.${typ}.001.02"><${container}><${bericht}>
      <Acct><Id><Othr><Id>BROWSER-TEST</Id></Othr></Id></Acct><Ntry><Amt Ccy="EUR">12.50</Amt><CdtDbtInd>DBIT</CdtDbtInd>
      <Sts>BOOK</Sts><BookgDt><Dt>2026-11-02</Dt></BookgDt><AcctSvcrRef>TEST-BROWSER</AcctSvcrRef>
      </Ntry></${bericht}></${container}></Document>`;
    await page.locator('#import-datei').setInputFiles({ name: `muster-${typ}.txt`, mimeType: 'text/plain', buffer: Buffer.from(xml) });
    await page.getByRole('heading', { name: `camt.${typ}.001.02` }).waitFor();
    await page.locator('#imp-geldkonto').selectOption('1200');
    await page.locator('#imp-vorschau').click();
    await page.getByText('Bankkonto BROWSER-TEST → Geldkonto 1200').waitFor();
    if (typ === '053') {
      await page.locator('#imp-einliefern').click();
      await page.locator('#imp-zum-vorrat').waitFor();
    } else {
      ist(await page.locator('#imp-einliefern').isDisabled(), 'CAMT.054 erkennt den zuvor übernommenen CAMT.053-Umsatz');
    }
  }
} finally {
  await browser.close();
  server.kill();
  execFileSync('php', [path.join(wurzel, 'tests/browser/aufbau.php'), '--abbau']);
}
const echteFehler = fehler.filter((f) => !/status of (401|404|422)/.test(f) && !/HTTP 422 .*\/import\/rechnung\/vorschau/.test(f));
if (echteFehler.length) {
  console.error(echteFehler.join('\n'));
  console.error(serverLog.split('\n').filter((z) => /error|warn|kumero\[/i.test(z)).slice(-20).join('\n'));
  process.exit(1);
}
console.log(`${pruefungen} Prüfungen im Browser, alle grün`);
