// SPDX-License-Identifier: AGPL-3.0-or-later
// Browsertest der Seiten „Bestand“ und „Wareneingang“ (LAGER.md Stufe 1, F131): Lagerort und Artikel anlegen, Zugang
// ohne Beleg mit gescanntem Code, Entnahme mit Vorschlag, abgelaufene und gesperrte Charge, Rückverfolgung,
// Wareneingang aus den Positionen eines Belegs. Alle Artikel, Lieferanten und Chargen sind erfunden.
//
//   KUMERO_TEST_PGHOST=/tmp/pgtest KUMERO_TEST_PGUSER=postgres \
//   PLAYWRIGHT=/opt/npm-tools/node_modules/playwright CHROME=/usr/bin/google-chrome node tests/browser/lager.mjs [Bildverzeichnis]
import { spawn, execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { mkdirSync, readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const require = createRequire(import.meta.url);
const { chromium } = require(process.env.PLAYWRIGHT || 'playwright');
const wurzel = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const PORT = 8097;
const ADRESSE = `http://localhost:${PORT}`;
const bilder = process.argv[2] || null;
if (bilder) mkdirSync(bilder, { recursive: true });

let pruefungen = 0;
function ist(b, was) { if (!b) throw new Error(`fehlgeschlagen: ${was}`); pruefungen++; }

const umgebung = JSON.parse(execFileSync('php', [path.join(wurzel, 'tests/browser/aufbau.php'), ADRESSE, '--buchen', '--vorrat', '--anlagen', '--lager'], { encoding: 'utf8' }).trim().split('\n').pop());
const server = spawn('php', ['-S', `localhost:${PORT}`, path.join(wurzel, 'werkzeuge/entwicklungsserver.php')], {
  cwd: wurzel, env: { ...process.env, KUMERO_KONFIG: umgebung.konfig, KUMERO_ENTWICKLUNG: '1' }, stdio: ['ignore', 'ignore', 'pipe'],
});
let serverLog = '';
server.stderr.on('data', (d) => { serverLog += d; });
await new Promise((r) => setTimeout(r, 700));

const browser = await chromium.launch({ executablePath: process.env.CHROME || undefined, headless: true });
const fehler = [];
try {
  const context = await browser.newContext({ viewport: { width: 1400, height: 1000 }, locale: 'de-DE', acceptDownloads: true });
  const page = await context.newPage();
  page.on('console', (m) => { if (m.type() === 'error') fehler.push(`console: ${m.text()}`); });
  page.on('pageerror', (e) => fehler.push(`pageerror: ${e.message}`));
  page.on('response', (r) => { if (r.status() >= 400 && r.status() !== 401) fehler.push(`HTTP ${r.status()} ${r.url()}`); });
  const cdp = await context.newCDPSession(page);
  await cdp.send('WebAuthn.enable');
  await cdp.send('WebAuthn.addVirtualAuthenticator', { options: {
    protocol: 'ctap2', transport: 'internal', hasResidentKey: true, hasUserVerification: true, isUserVerified: true, automaticPresenceSimulation: true,
  } });
  const bild = async (name) => { if (bilder) await page.screenshot({ path: path.join(bilder, `${name}.png`), fullPage: true }); };
  const meldung = (text) => page.locator('.meldung').filter({ hasText: text }).first().waitFor();
  const gesperrt = async (id, grund) => await page.locator(`#${id}`).isDisabled() && (await page.locator(`#${id}`).getAttribute('title')).includes(grund);
  // Zellen einer Tabelle als Text – ohne die Spalte mit dem Dreieck (Bestand) und ohne die Spalte „Artikel“ der Chargen,
  // damit die Prüfungen bei den fachlichen Spalten bleiben
  const zeilen = (id) => page.locator(`#${id} tbody tr`).evaluateAll((trs, id2) => trs.map((tr) => {
    const z = [...tr.children].map((td) => td.textContent);
    if (z.length > 1 && id2 === 'lg-tabelle') return z.slice(1);
    if (z.length > 1 && id2 === 'lg-chargen-tabelle') z.splice(1, 1);
    return z;
  }), id);

  await page.goto(umgebung.link);
  await page.getByText('Passkey registrieren', { exact: true }).first().waitFor();
  await page.locator('#registrierung-bezeichnung').fill('Virtueller Passkey');
  await page.getByRole('button', { name: 'Passkey registrieren' }).click();
  await page.getByText('Passkey registriert. Jetzt anmelden.').waitFor();
  await page.locator('#anmelden').click();
  await page.getByRole('navigation').getByText('Konten', { exact: true }).waitFor();

  const api = (m, p, j) => page.evaluate(async ([m2, p2, j2]) => {
    const r = await fetch(`/m/browserpraxis/b/haupt/api/v1${p2}`, { method: m2, credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'Idempotency-Key': crypto.randomUUID(), 'If-Match': '*' }, body: j2 === undefined ? undefined : JSON.stringify(j2) });
    return [r.status, r.status === 204 ? null : await r.json()];
  }, [m, p, j]);
  const ok = async (m, p, j) => { const [s, r] = await api(m, p, j); if (s >= 300) throw new Error(`${m} ${p}: ${s} ${JSON.stringify(r)}`); return r; };
  const tag = (n) => { const d = new Date(); d.setDate(d.getDate() + n); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`; };
  const de = (iso) => `${iso.slice(8, 10)}.${iso.slice(5, 7)}.${iso.slice(0, 4)}`;
  const kurz = (iso) => `${iso.slice(2, 4)}${iso.slice(5, 7)}${iso.slice(8, 10)}`;

  // Zerlegen des Codes einer Packung (WW16): Klammerschreibweise, Rohfolge des Scanners, Tag 00
  const codes = await page.evaluate(async () => {
    const m = await import('/module/lager/0.4.0/gemeinsam.js');
    return [m.codeLesen('(01)04012345678901(17)281231(10)T26A0001'), m.codeLesen('010401234567890117280200' + '10T26B\u001d21SN7'), m.codeLesen('Musterspritze'), m.mengeLesen('1.250,5'), m.mengeLesen('0'), m.mengeLesen('1,2345')];
  });
  ist(JSON.stringify(codes[0]) === JSON.stringify({ gtin: '04012345678901', verfall: '2028-12-31', charge: 'T26A0001' }), `Code in Klammern: ${JSON.stringify(codes[0])}`);
  ist(JSON.stringify(codes[1]) === JSON.stringify({ gtin: '04012345678901', verfall: '2028-02-29', charge: 'T26B', serie: 'SN7' }), `Rohfolge mit Tag 00 und Trennzeichen: ${JSON.stringify(codes[1])}`);
  ist(codes[2] === null && codes[3] === '1250.5' && codes[4] === null && codes[5] === null, 'kein Code; Mengen lesen');

  // Seite Bestand: leer, Knöpfe gesperrt mit Grund
  await page.getByRole('navigation').getByText('Bestand', { exact: true }).click();
  await page.locator('#lg-leer').waitFor();
  ist(await gesperrt('lg-entnahme', 'Erst eine Charge'), 'Entnahme gesperrt mit Grund');
  ist(await gesperrt('lg-zugang', 'Erst einen Artikel'), 'Zugang gesperrt mit Grund');
  await bild('1-leer');

  // Lagerorte
  await page.locator('#lg-orte').click();
  await page.locator('#lg-o-name').fill('Lager Keller');
  await page.locator('#lg-o-anlegen').click();
  await page.locator('#lg-o-liste').getByText('Lager Keller').waitFor();
  await page.locator('#lg-o-name').fill('Kühlschrank Labor');
  await page.locator('#lg-o-kalt').check();
  await page.locator('#lg-o-anlegen').click();
  await page.locator('#lg-o-liste').getByText('Kühlschrank Labor').waitFor();
  // Lagerorte als Tabelle mit Sortierung und Filter (LAGER.md Abschnitt 18): der neue Ort ist gewählt, noch liegt nichts dort
  ist(await page.locator('#lg-o-tabelle tbody tr').count() === 2 && (await page.locator('#lg-o-tabelle tbody tr.gewaehlt td').first().textContent()) === 'Kühlschrank Labor'
    && (await page.locator('#lg-o-bestand-titel').textContent()) === 'Bestand an diesem Lagerort: Kühlschrank Labor' && (await page.locator('#lg-o-bestand-leer').textContent()) === 'Hier liegt nichts.',
    'Lagerorte: Tabelle mit zwei Orten, der neue ist gewählt und leer');
  ist(await page.locator('#lg-o-tabelle thead details').count() >= 4 && await gesperrt('lg-o-speichern', 'Nichts geändert') && await gesperrt('lg-o-anlegen', 'Erst einen Namen eingeben'), 'Lagerorte: Filter am Spaltenkopf; Speichern und Anlegen gesperrt mit Grund');
  // umbenennen und zurück; stilllegen und wieder nutzen
  await page.locator('#lg-o-tabelle tbody tr', { hasText: 'Lager Keller' }).click();
  await page.locator('#lg-o-aendern-name').fill('Lager Keller, Regal 1');
  ist(await gesperrt('lg-o-still', 'Erst die Änderung speichern'), 'Lagerorte: mit ungespeicherter Änderung ist Stilllegen gesperrt');
  await page.locator('#lg-o-speichern').click();
  await meldung('Lagerort gespeichert.');
  await page.locator('#lg-o-liste').getByText('Lager Keller, Regal 1').waitFor();
  await page.locator('#lg-o-aendern-name').fill('Lager Keller');
  await page.locator('#lg-o-aendern-name').press('Enter');
  await page.locator('#lg-o-tabelle tbody tr.gewaehlt td', { hasText: /^Lager Keller$/ }).waitFor();
  await page.locator('#lg-o-still').click();
  await meldung('Lagerort stillgelegt.');
  ist((await page.locator('#lg-o-still').textContent()) === 'Wieder nutzen' && (await page.locator('#lg-o-tabelle tbody tr.gewaehlt').textContent()).includes('stillgelegt'), 'Lagerorte: stillgelegt, der Knopf heißt „Wieder nutzen“');
  await page.locator('#lg-o-still').click();
  await meldung('Lagerort wird wieder genutzt.');
  await page.locator('#lg-dialog-orte-abbrechen').click();

  // Artikel anlegen: Arzneimittel ist mit Charge und Verfall vorbelegt
  await page.locator('#lg-artikel-neu').click();
  await page.locator('#lg-f-bezeichnung').fill('Musterampulle 5 ml [TESTDATEN]');
  await page.locator('#lg-f-art').selectOption('arzneimittel');
  ist(await page.locator('#lg-f-verfall').isChecked() && await page.locator('#lg-f-fuehrung').inputValue() === 'charge', 'Arzneimittel: Charge und Verfall vorbelegt');
  await page.locator('#lg-f-fuehrung').selectOption('ohne');
  ist(!(await page.locator('#lg-f-verfall').isChecked()) && await page.locator('#lg-f-verfall').isDisabled(), 'ohne Charge kein Verfall');
  await page.locator('#lg-f-fuehrung').selectOption('charge');
  await page.locator('#lg-f-verfall').check();
  await page.locator('#lg-f-einheit').fill('Ampulle');
  await page.locator('#lg-f-gtin').fill('04012345678901');
  await page.locator('#lg-f-mindest').fill('20');
  await page.locator('#lg-dialog-artikel-senden').click();
  await meldung('Artikel angelegt.');
  await page.locator('#lg-tabelle tbody tr').first().waitFor();

  // Zugang ohne Beleg: der gescannte Code füllt Charge und Verfall
  const frueh = tag(40); const spaet = tag(400); const alt = tag(-5);
  const zugang = async (code, menge, ort) => {
    await page.locator('#lg-zugang').click();
    await page.locator('#lg-z-code').fill(code);
    await page.locator('#lg-z-code').press('Enter');
    if (ort) await page.locator('#lg-z-ort').selectOption({ label: ort });
    await page.locator('#lg-z-menge').fill(menge);
    return page.locator('#lg-dialog-zugang-senden');
  };
  let senden = await zugang(`(01)04012345678901(17)${kurz(frueh)}(10)T-FRUEH`, '12', 'Kühlschrank Labor');
  ist(await page.locator('#lg-z-charge').inputValue() === 'T-FRUEH' && await page.locator('#lg-z-verfall').inputValue() === de(frueh), 'Code füllt Charge und Verfall');
  await bild('2-zugang');
  await senden.click();
  await meldung('Zugang gebucht');
  await page.locator('#lg-chargen-tabelle tbody tr').first().waitFor();
  await (await zugang(`(01)04012345678901(17)${kurz(spaet)}(10)T-SPAET`, '20', 'Lager Keller')).click();
  await page.waitForFunction(() => document.querySelectorAll('#lg-chargen-tabelle tbody tr').length === 2);
  await (await zugang(`(01)04012345678901(17)${kurz(alt)}(10)T-ALT`, '6', 'Lager Keller')).click();
  await page.waitForFunction(() => document.querySelectorAll('#lg-chargen-tabelle tbody tr').length === 3);
  // ohne Charge wird abgewiesen, der Dialog bleibt offen und nennt den Grund
  await page.locator('#lg-zugang').click();
  await page.locator('#lg-z-menge').fill('1');
  await page.locator('#lg-dialog-zugang-senden').click();
  await page.locator('#lg-dialog-zugang-fehler').filter({ hasText: 'Charge fehlt' }).waitFor();
  await page.locator('#lg-dialog-zugang-abbrechen').click();

  // Bestand: Zustand in Worten, Vorschlag = frühester Verfall unter den freien Chargen
  let a = (await zeilen('lg-tabelle'))[0];
  ist(a[3].startsWith('38') && a[4] === '20' && a[5] === de(alt) && a[6].includes('abgelaufen'), `Artikelzeile: ${a.join(' | ')}`);
  let ch = await zeilen('lg-chargen-tabelle');
  ist(ch[0][0] === 'T-ALT' && ch[0][4].includes('abgelaufen – nur ausbuchen') && ch[1][0] === 'T-FRUEH' && ch[1][4].includes('läuft ab in 40 Tagen') && ch[1][4].includes('wird vorgeschlagen') && ch[2][4] === 'frei', `Chargen: ${JSON.stringify(ch)}`);
  ist(await page.locator('#lg-chargen-tabelle tbody tr.gewaehlt td').first().textContent() === 'T-FRUEH', 'vorgewählt ist die vorgeschlagene Charge');
  ist((await page.locator('#lg-k-abgelaufen strong').textContent()) === '1' && (await page.locator('#lg-k-laeuft_ab strong').textContent()) === '1', 'Kacheln zählen');
  await bild('3-bestand');

  // abgelaufene Charge: Entnahme gesperrt mit Grund; Ausbuchen geht
  await page.locator('#lg-chargen-tabelle tbody tr').nth(0).click();
  ist(await gesperrt('lg-entnahme', 'abgelaufen – sie lässt sich nur ausbuchen'), 'abgelaufene Charge: Entnahme gesperrt mit Grund');
  await page.locator('#lg-ausbuchen').click();
  ist(await page.locator('#lg-a-grund').inputValue() === 'verfallen' && await page.locator('#lg-a-menge').inputValue() === '6', 'Ausbuchen vorbelegt: verfallen, ganze Menge');
  await page.locator('#lg-dialog-ausbuchen-senden').click();
  await meldung('Ausgebucht.');
  await page.waitForFunction(() => document.querySelectorAll('#lg-chargen-tabelle tbody tr').length === 2);

  // Entnahme aus der späteren Charge: Hinweis auf die Abweichung, erlaubt
  await page.locator('#lg-chargen-tabelle tbody tr').nth(1).click();
  await page.locator('#lg-entnahme').click();
  await page.locator('#lg-e-abweichung').waitFor();
  await page.locator('#lg-e-menge').fill('25');
  await page.locator('#lg-e-rest').filter({ hasText: 'nur 20 Ampulle verfügbar' }).waitFor();
  await page.locator('#lg-e-menge').fill('2');
  await bild('4-entnahme');
  await page.locator('#lg-dialog-entnahme-senden').click();
  await meldung('abweichend vom Vorschlag');
  // Entnahme aus dem Vorschlag
  await page.locator('#lg-chargen-tabelle tbody tr').nth(0).click();
  await page.locator('#lg-entnahme').click();
  ist(await page.locator('#lg-e-abweichung').count() === 0, 'Vorschlag: kein Hinweis auf Abweichung');
  await page.locator('#lg-e-menge').fill('1,5');
  await page.locator('#lg-dialog-entnahme-senden').click();
  await meldung('Entnommen: 1,5 Ampulle');

  // Sperren: Entnahme gesperrt mit dem Grund der Sperre, Vorschlag weicht aus; Knopf heißt „Freigeben“
  await page.locator('#lg-sperre').click();
  await page.locator('#lg-s-grund').fill('Rückruf des Herstellers (Muster)');
  await page.locator('#lg-dialog-sperre-senden').click();
  await meldung('Charge gesperrt.');
  await page.waitForFunction(() => document.querySelector('#lg-chargen-tabelle tbody tr')?.textContent.includes('gesperrt – Rückruf'));
  ch = await zeilen('lg-chargen-tabelle');
  ist(ch[1][4].includes('wird vorgeschlagen') && !ch[0][4].includes('wird vorgeschlagen'), 'Vorschlag weicht auf die freie Charge aus');
  await page.locator('#lg-chargen-tabelle tbody tr').nth(0).click();
  ist(await gesperrt('lg-entnahme', 'gesperrt: Rückruf des Herstellers'), 'gesperrte Charge: Entnahme gesperrt mit Grund');
  ist((await page.locator('#lg-sperre').textContent()) === 'Freigeben …', 'Knopf bietet die Freigabe an');

  // Rückverfolgung
  await page.locator('#lg-verlauf').click();
  await page.locator('#lg-verlauf-tabelle tbody tr').nth(2).waitFor();
  const rv = await zeilen('lg-verlauf-tabelle');
  ist(rv.length === 3 && rv[0][1] === 'Zugang' && rv[0][2] === '+12' && rv[1][1] === 'Entnahme' && rv[1][2] === '−1,5' && rv[1][6] === '10,5' && rv[2][1] === 'Sperre' && rv[2][4].includes('Rückruf') && rv[1][5] === 'du',
    `Rückverfolgung: ${JSON.stringify(rv)}`);
  await bild('5-rueckverfolgung');
  await page.locator('#lg-dialog-verlauf-abbrechen').click();

  // Lagerorte zeigen, was dort liegt; ein Ort mit Bestand lässt sich nicht stilllegen
  await page.locator('#lg-orte').click();
  await page.locator('#lg-o-tabelle tbody tr', { hasText: 'Kühlschrank Labor' }).click();
  await page.locator('#lg-o-bestand-tabelle tbody tr').first().waitFor();
  const ortZeile = (await page.locator('#lg-o-tabelle tbody tr.gewaehlt td').allTextContents());
  ist(ortZeile[1] === 'gekühlt' && Number(ortZeile[3]) >= 1 && ortZeile[4] === 'mit Bestand' && (await page.locator('#lg-o-bestand-summe').textContent()).includes('Charge'),
    `Lagerorte: der Ort nennt Artikel, Chargen und Zustand – ${JSON.stringify(ortZeile)}`);
  ist(await page.locator('#lg-o-bestand-tabelle tbody tr').count() === Number(ortZeile[3]) && (await page.locator('#lg-o-bestand-tabelle tbody tr').first().textContent()).includes('[TESTDATEN]'), 'Lagerorte: darunter der Bestand des gewählten Orts, je Charge eine Zeile');
  ist(await gesperrt('lg-o-still', 'hat noch Bestand'), 'Lagerorte: Stilllegen bei Bestand gesperrt mit Grund');
  await bild('4a-lagerorte');
  await page.locator('#lg-dialog-orte-abbrechen').click();
  // Kachel als Filter; Suche über den gescannten Code
  ist((await page.locator('#lg-k-unter_mindest').getAttribute('title')).startsWith('Klick: die Liste auf diese Artikel einschränken'), 'Kachel: Die Hilfe nennt, was der Klick tut');
  await page.locator('#lg-k-unter_mindest').click();
  ist((await page.locator('#lg-k-unter_mindest').getAttribute('title')).includes('ein weiterer Klick löscht ihn'), 'Kachel mit Filter: Die Hilfe sagt, dass ein weiterer Klick den Filter löscht');
  // seit 0.7.60 (Y17) zählt für „unter Mindestbestand“ nur der verfügbare Bestand: Die gesperrte Charge zählt nicht mit
  ist((await page.locator('#lg-anzahl').textContent()) === '1 Artikel' && (await zeilen('lg-tabelle'))[0][6].includes('unter Mindestbestand'), `Filter „unter Mindestbestand“: ohne die gesperrte Charge liegt der Artikel darunter – ${JSON.stringify(await zeilen('lg-tabelle'))}`);
  await page.locator('#lg-k-unter_mindest').click();
  await page.locator('#lg-suche').fill(`(01)04012345678901(17)${kurz(spaet)}(10)T-SPAET`);
  await page.waitForFunction(() => document.querySelector('#lg-chargen-tabelle tbody tr.gewaehlt td')?.textContent === 'T-SPAET');
  await page.locator('#lg-suche').fill('');

  // --- Wareneingang aus den Positionen eines Belegs ---
  await ok('POST', '/arbeitsvorrat/eintraege', { betreff: 'Rechnung Musterlieferant R-1', gegenpartei: 'Musterlieferant GmbH', gegenpartei_kennung: 'DE000000000', belegnummer: 'R-1', datum: tag(-3), betrag: '57.36', netto: '48.20',
    positionen: [{ nr: '1', bezeichnung: 'Musteramp. 5ml 10 St. [TESTDATEN]', artikelnummer: 'ML-22017', menge: '2', einheit: 'XPK', einzelpreis: '18.9', netto: '37.80', gtin: '04012345678901' },
      { nr: '2', bezeichnung: 'Musterpapier A4 [TESTDATEN]', menge: '1', einheit: 'C62', netto: '3.50' }, { nr: '3', bezeichnung: 'Versandkosten', menge: '1', netto: '6.90' }] });
  await page.getByRole('navigation').getByText('Wareneingang', { exact: true }).click();
  await page.locator('#we-tabelle tbody tr').nth(2).waitFor();
  ist((await page.locator('#we-summe').textContent()).includes('entspricht dem Nettobetrag'), 'Summenprüfung in der Fußzeile');
  ist(!(await page.locator('#we-kopf').textContent()).includes('null'), 'Kopf des Belegs ohne Platzhalter');
  ist(await page.locator('#we-artikel-1').inputValue() !== '' && (await zeilen('we-tabelle'))[0][3].includes('Vorschlag über die GTIN'), 'Position 1: Artikel über die GTIN vorgeschlagen');
  ist((await page.locator('#we-stand-1').textContent()) === 'Charge fehlt' && (await page.locator('#we-stand-2').textContent()) === 'Artikel wählen', 'Stand je Position in Worten');
  ist((await page.locator('#we-grund').textContent()).includes('Position 1: Charge fehlt') && await gesperrt('we-buchen', 'Position 1: Charge fehlt'), '„Zugang buchen“ gesperrt und nennt die Position');
  // Faktor: 2 Packungen zu je 10 Ampullen
  await page.locator('#we-faktor-1').fill('10');
  await page.locator('#we-faktor-1').dispatchEvent('change');
  ist(await page.locator('#we-menge-1').inputValue() === '20', 'Faktor 10: Zugang 20');
  // Scannen füllt die Position mit der passenden Produktkennung
  const neu = tag(700);
  await page.locator('#we-scan').fill(`(01)04012345678901(17)${kurz(neu)}(10)T-NEU`);
  await page.locator('#we-scan').press('Enter');
  await meldung('Code gelesen: Position 1');
  ist(await page.locator('#we-charge-1').inputValue() === 'T-NEU' && await page.locator('#we-verfall-1').inputValue() === de(neu) && (await page.locator('#we-stand-1').textContent()) === 'vollständig · gescannt', 'Scan füllt Charge und Verfall');
  await page.locator('#we-ort-1').selectOption({ label: 'Lager Keller' });
  await page.locator('#we-artikel-3').selectOption('-');
  await meldung('kein Lagerartikel');
  ist((await page.locator('#we-stand-3').textContent()) === 'kein Lagerartikel' && await page.locator('#we-menge-3').isDisabled(), 'Versandkosten abgehakt, Felder gesperrt');
  await bild('6-wareneingang');
  await page.locator('#we-buchen').click();
  await meldung('Zugang gebucht: 1 Position(en).');
  await page.waitForFunction(() => document.querySelector('#we-stand-1')?.textContent === 'zugegangen');
  const w = await zeilen('we-tabelle');
  ist(w[0][7].includes('20 · T-NEU → Lager Keller') && w[0][3].includes('gemerkt') && w[2][3].includes('gemerkt'), `nach dem Buchen: ${w[0][7]} / ${w[0][3]}`);
  ist((await page.locator('#we-beleg option').first().textContent()).includes('1 von 3 Positionen zugegangen'), 'Auswahl nennt den Fortschritt');
  const stand = await ok('GET', '/lager');
  ist(stand.artikel[0].bestand === '48.5' && stand.artikel[0].chargen.some((c) => c.bezeichnung === 'T-NEU' && c.menge === '20'), `Bestand nach dem Wareneingang: ${stand.artikel[0].bestand}`);

  // --- Nachbestellen: Mindest- und Sollbestand am Artikel, Vorschlag in Bestelleinheiten des Lieferanten ---
  await page.getByRole('navigation').getByText('Nachbestellen', { exact: true }).click();
  await page.locator('#nb-leer').waitFor();
  ist(await gesperrt('nb-csv', 'Die Liste ist leer'), 'leere Bestellliste: CSV gesperrt mit Grund');
  await page.getByRole('navigation').getByText('Bestand', { exact: true }).click();
  await page.locator('#lg-tabelle tbody tr').first().waitFor();
  await page.locator('#lg-artikel-aendern').click();
  await page.locator('#lg-f-mindest').fill('60');
  await page.locator('#lg-f-soll').fill('50');
  await page.locator('#lg-dialog-artikel-senden').click();
  await page.locator('#lg-dialog-artikel-fehler').filter({ hasText: 'Sollbestand liegt unter dem Mindestbestand' }).waitFor();
  await page.locator('#lg-f-soll').fill('100');
  await page.locator('#lg-dialog-artikel-senden').click();
  await meldung('Artikel gespeichert.');
  await page.getByRole('navigation').getByText('Nachbestellen', { exact: true }).click();
  await page.locator('#nb-tabelle tbody tr').first().waitFor();
  const nb = (await zeilen('nb-tabelle'))[0];
  // verfügbar: 48,5 im Bestand, davon 10,5 in der gesperrten Charge
  ist(nb[0].includes('10,5 Ampulle gesperrt oder abgelaufen') && nb[2] === '38 Ampulle' && nb[5] === '62 Ampulle' && nb[6] === '7 × 10' && nb[7] === 'Musterlieferant GmbH' && nb[8] === 'ML-22017' && nb[9].startsWith('1,89 €'),
    `Bestellliste: ${nb.join(' | ')}`);
  const [herunter] = await Promise.all([page.waitForEvent('download'), page.locator('#nb-csv').click()]);
  const csv = readFileSync(await herunter.path(), 'utf8');
  ist(herunter.suggestedFilename().startsWith('Bestellliste-') && csv.includes('"Musterampulle 5 ml [TESTDATEN] (10,5 Ampulle gesperrt oder abgelaufen)"') && csv.includes('"7 × 10"'), 'CSV der Bestellliste');
  await bild('7-nachbestellen');

  // --- Inventur: Zählliste, jede Eingabe gespeichert, Abschluss erst, wenn alles gezählt ist ---
  await page.getByRole('navigation').getByText('Inventur', { exact: true }).click();
  await page.locator('#iv-leer').waitFor();
  ist(await gesperrt('iv-abschluss', 'Keine offene Inventur'), 'ohne Inventur: Abschluss gesperrt mit Grund');
  await page.locator('#iv-neu').click();
  await page.locator('#iv-n-ort').selectOption({ label: 'Lager Keller' });
  await page.locator('#iv-dialog-neu-senden').click();
  await meldung('Zählliste angelegt.');
  await page.locator('#iv-tabelle tbody tr').nth(1).waitFor();
  let iv = await zeilen('iv-tabelle');
  ist(iv.length === 2 && iv[0][2] === 'T-SPAET' && iv[0][4] === '18 Ampulle' && iv[1][2] === 'T-NEU' && iv[1][4] === '20 Ampulle' && iv[0][7] === 'offen', `Zählliste: ${JSON.stringify(iv)}`);
  ist(await gesperrt('iv-abschluss', '2 Zeilen sind noch nicht gezählt') && (await page.locator('#iv-grund').textContent()).includes('2 Zeilen sind noch nicht gezählt'), 'Abschluss gesperrt und nennt den Grund');
  const eingabe = page.locator('#iv-tabelle tbody input');
  await eingabe.nth(0).fill('17');
  await eingabe.nth(0).press('Enter');
  await page.waitForFunction(() => document.querySelector('#iv-tabelle tbody tr td:nth-child(7)')?.textContent === '−1');
  ist(await eingabe.nth(1).evaluate((el) => el === document.activeElement), 'Enter springt zur nächsten Zeile');
  // der Scan springt zur Zeile der Charge
  await page.locator('#iv-scan').fill(`(01)04012345678901(17)${kurz(neu)}(10)T-NEU`);
  await page.locator('#iv-scan').press('Enter');
  ist(await eingabe.nth(1).evaluate((el) => el === document.activeElement), 'Scan springt zur Zeile der Charge');
  await eingabe.nth(1).fill('20');
  await eingabe.nth(1).press('Tab');
  await page.waitForFunction(() => document.querySelector('#iv-kacheln strong')?.textContent === '2 von 2');
  iv = await zeilen('iv-tabelle');
  ist(iv[0][7] === 'Abweichung' && iv[1][6] === '0' && iv[1][7] === 'stimmt' && !(await page.locator('#iv-abschluss').isDisabled()), `gezählt: ${JSON.stringify(iv)}`);
  await bild('8-inventur');
  // fortsetzbar: nach dem Neuladen steht die Zählung noch da
  await page.reload();
  await page.locator('#iv-tabelle tbody tr').nth(1).waitFor();
  ist(await page.locator('#iv-tabelle tbody input').nth(0).inputValue() === '17', 'die Zählung ist gespeichert und fortsetzbar');
  await page.locator('#iv-abschluss').click();
  await page.getByRole('button', { name: 'Abschließen', exact: true }).click();
  await meldung('Inventur abgeschlossen: 1 Differenzbewegung(en).');
  ist((await page.locator('#iv-wahl option').first().textContent()).includes('abgeschlossen') && await page.locator('#iv-tabelle tbody input').nth(0).isDisabled(), 'abgeschlossene Inventur ist gesperrt');
  const nachher = await ok('GET', '/lager');
  ist(nachher.artikel[0].bestand === '47.5', `Bestand nach der Inventur: ${nachher.artikel[0].bestand}`);

  // --- Stufe 3: Hinweis „eher ein Anlagegut?“ im Wareneingang (Grenze aus dem Regelwerk der Anlagen) ---
  await ok('POST', '/arbeitsvorrat/eintraege', { betreff: 'Rechnung Mustertechnik R-2', gegenpartei: 'Mustertechnik AG', belegnummer: 'R-2', datum: tag(-1), betrag: '1130.50', netto: '950.00',
    positionen: [{ nr: '1', bezeichnung: 'Mustergerät [TESTDATEN]', menge: '1', einheit: 'C62', einzelpreis: '950', netto: '950.00', satz: '19' }] });
  await page.getByRole('navigation').getByText('Wareneingang', { exact: true }).click();
  await page.locator('#we-beleg').selectOption({ label: await page.locator('#we-beleg option').filter({ hasText: 'R-2' }).textContent() });
  await page.locator('#we-anlage-1').waitFor();
  ist((await page.locator('#we-anlage-1').textContent()).includes('über 800,00 € je Einheit – eher ein Anlagegut?') && (await page.locator('#we-anlage-1').getAttribute('href')).endsWith('/anlagen/anlagen'), 'Hinweis „Anlagegut?“ mit Sprung in die Anlagenbuchhaltung');

  // --- Stufe 3: Bestandsliste mit Wert; Bestandsführung mit Entwurf und Abstimmung ---
  const jahr = new Date().getFullYear();
  await ok('POST', '/konten', { nr: '3970', bezeichnung: 'Bestand Praxisbedarf', kontoart: 'aktiv' });
  await ok('POST', '/konten', { nr: '3960', bezeichnung: 'Bestandsveränderung Praxisbedarf', kontoart: 'aufwand' });
  await ok('POST', '/geschaeftsjahre', { kennung: String(jahr), beginn: `${jahr}-01-01`, ende: `${jahr}-12-31` });
  await ok('POST', '/nummernkreise', { kuerzel: 'ALLG', bezeichnung: 'Allgemein' });
  await ok('PUT', `/nummernkreise/ALLG/jahre/${jahr}`, { vorlage: 'ALLG-{JJJJ}-{NR:5}', zuruecksetzen: true });
  await ok('POST', '/buchungsarten', { kuerzel: 'ABSCHL', bezeichnung: 'Abschluss', nummernkreis: 'ALLG' });
  await page.getByRole('navigation').getByText('Bestandsliste und Wert', { exact: true }).click();
  await page.locator('#lb-tabelle tbody tr').nth(1).waitFor();
  let bl = await zeilen('lb-tabelle');
  ist(bl.length === 2 && bl[0][3] === '47,5 Ampulle' && bl[0][4] === '1,89 €' && bl[0][5] === '89,78 €' && bl[1][0] === 'Gesamt' && bl[1][5] === '89,78 €', `Bestandsliste: ${JSON.stringify(bl)}`);
  ist((await page.locator('#lb-abstimmung').textContent()).includes('sofort als Aufwand') && await gesperrt('lb-buchen', 'führt keinen Bestand'), 'Aufwand: Wert nachrichtlich, Buchen gesperrt mit Grund');
  // Stichtag gestern: kein Bestand
  await page.locator('#lb-stichtag').fill(de(tag(-1)));
  await page.locator('#lb-stichtag').press('Tab');
  await page.locator('#lb-leer').waitFor();
  await page.locator('#lb-stichtag').fill(de(tag(0)));
  await page.locator('#lb-stichtag').press('Tab');
  await page.locator('#lb-tabelle tbody tr').nth(1).waitFor();
  // Bestandsführung einstellen
  await page.locator('#lb-einstellung').click();
  ist(await page.locator('#lb-f-bestand').isDisabled(), 'Konten nur bei Bestandsführung');
  await page.locator('#lb-f-fuehrung').selectOption('bestand');
  await page.locator('#lb-f-vorrat').fill('3970-3979');
  await page.locator('#lb-f-bestand').fill('3970');
  await page.locator('#lb-f-aufwand').fill('3960');
  await page.locator('#lb-dialog-einstellung-senden').click();
  await meldung('Einstellungen gespeichert.');
  await page.locator('#lb-abstimmung').filter({ hasText: 'Lagerwert 89,78 €, Saldo der Vorratskonten 0,00 € – Differenz 89,78 €' }).waitFor();
  await page.locator('#lb-buchen').click();
  const bz = (await zeilen('lb-buchung'))[0];
  ist(bz[0] === 'Bestandserhöhung' && bz[1] === '3970' && bz[2] === '3960' && bz[3] === '89,78 €', `Entwurf: ${bz.join(' | ')}`);
  await bild('9-bestandsveraenderung');
  await page.locator('#lb-dialog-buchen-senden').click();
  await meldung('Entwurf angelegt');
  await page.waitForFunction(() => document.querySelector('#lb-buchen')?.disabled);
  ist(await gesperrt('lb-buchen', 'schon einen Entwurf') && (await page.locator('#lb-abstimmung').textContent()).includes('Entwurf, der noch nicht festgeschrieben ist'), 'ein zweiter Entwurf ist gesperrt');
  // festschreiben wie jede Buchung – danach stimmt die Abstimmung
  const vermerk = (await ok('GET', '/lager/bestandsliste')).vermerke[0];
  const entwurf = await ok('GET', `/buchungen/${vermerk.buchung}`);
  ist(entwurf.status === 'entwurf' && entwurf.text.startsWith('Bestandsveränderung Lager zum') && vermerk.betrag === '89.78' && vermerk.richtung === 'erhoehung', 'der Entwurf steht im Kern, der Vermerk im Lager');
  await ok('POST', `/buchungen/${vermerk.buchung}/vorbereiten`);
  await ok('POST', '/freigaben', { buchungen: [vermerk.buchung] });
  await page.reload();
  await page.locator('#lb-abstimmung').filter({ hasText: 'Der Lagerwert entspricht dem Saldo der Vorratskonten (89,78 €)' }).waitFor();
  ist(await gesperrt('lb-buchen', 'es ist nichts zu buchen'), 'abgestimmt: nichts mehr zu buchen');
  await bild('10-bestandsliste');
  const [bcsv] = await Promise.all([page.waitForEvent('download'), page.locator('#lb-csv').click()]);
  ist(readFileSync(await bcsv.path(), 'utf8').includes('"Gesamt";"";"";"";"";"89,78 €"'), 'CSV der Bestandsliste');

  // Druck des Bestands: ohne Navigation und Werkzeuge
  await page.getByRole('navigation').getByText('Bestand', { exact: true }).click();
  await page.locator('#lg-tabelle tbody tr').first().waitFor();
  // F137: Sortierung und Filter an beiden Tabellenköpfen, Teiler zwischen Liste und Chargen, Menüleiste verschiebbar und ausblendbar
  ist(await page.locator('#lg-tabelle thead th.sp-kopf').count() === 7 && await page.locator('#lg-chargen-tabelle thead th.sp-kopf').count() === 6, `Bestand: Filter an jedem Spaltenkopf, auch bei den Chargen – ${await page.locator('#lg-tabelle thead th.sp-kopf').count()}/${await page.locator('#lg-chargen-tabelle thead th.sp-kopf').count()}`);
  ist(await page.locator('#lg-teilung-teiler').isVisible() && await page.locator('#navigation-teiler').isVisible(), 'Bestand: Teiler der Zweiteilung und Griff der Menüleiste');
  await page.locator('#menue-knopf').click();
  ist(!(await page.locator('.navigation').isVisible()) && await page.locator('#navigation-rand').isVisible(), 'Menüleiste ausgeblendet, der Rand bleibt');
  await page.locator('#navigation-rand').click();
  ist(await page.locator('.navigation').isVisible(), 'Menüleiste wieder eingeblendet');
  // F146: Spalten verschiebbar – mit der Tastatur eine Stelle nach rechts und über das Filtermenü zurück zur Vorgabe
  const koepfe = () => page.locator('#lg-tabelle thead th .sp-titel').allTextContents();
  ist(await page.locator('#lg-tabelle thead th .sp-griff').count() === 8, `Bestand: jede Spalte hat eine Grenze zum Ziehen, auch die mit dem Dreieck – ${await page.locator('#lg-tabelle thead th .sp-griff').count()}`);
  await page.locator('#lg-tabelle thead .sp-titel', { hasText: 'Nummer' }).focus();
  await page.keyboard.press('Control+ArrowRight');
  ist((await koepfe())[2].trim().startsWith('Nummer') && (await zeilen('lg-tabelle'))[0][1] !== '', 'Bestand: Spalte „Nummer“ eine Stelle nach rechts, die Zellen folgen');
  await page.locator('#lg-tabelle details[data-spalte="nummer"] summary').click();
  await page.locator('#lg-tabelle details[data-spalte="nummer"] .sp-vorgabe').click();
  await page.locator('.seitenkopf').click();
  ist((await koepfe())[1].trim().startsWith('Nummer'), 'Bestand: Spalten nach Vorgabe');
  // Artikelgruppe (LAGER.md Abschnitt 17, F168): ersetzbare Artikel gleicher Funktion – Summe, Dreieck, Vorschlag über alle
  const ort1 = (await ok('GET', '/lager')).orte.find((o) => !o.stillgelegt).id;
  const handschuh = async (name, charge, tage, menge) => {
    const a = await ok('POST', '/lager/artikel', { bezeichnung: `${name} [TESTDATEN]`, art: 'verbrauch', einheit: 'Stück', fuehrung: 'charge', verfall_pflicht: true });
    await ok('POST', '/lager/zugaenge', { zeilen: [{ artikel: a.id, ort: ort1, menge, charge, verfall: tag(tage), grund: 'anfangsbestand' }] });
    return a.id;
  };
  const hA = await handschuh('Musterhandschuh A', 'T-HA', 400, '30');
  const hB = await handschuh('Musterhandschuh B', 'T-HB', 300, '40');
  await page.reload();
  await page.locator('#lg-tabelle tbody tr').first().waitFor();
  await page.locator(`#lg-tabelle tbody tr[data-id="${hA}"]`).click();   // der gewählte Artikel gibt die Lagereinheit vor
  await page.locator('#lg-gruppe-neu').click();
  await page.locator('#lg-dialog-gruppe').waitFor();
  ist((await page.locator('#lg-m-einheit').inputValue()) === 'Stück', 'Lagereinheit aus dem gewählten Artikel vorgeschlagen');
  ist(await gesperrt('lg-m-still', 'Erst nach dem Anlegen'), 'Artikelgruppe anlegen: „stillgelegt“ steht da und ist gesperrt');
  // Maske im Raster (STUECKLISTE.md AG6): Aufgabe als Pflichtfeld, Artikel der Gruppe an festem Platz – beim Anlegen gesperrt
  ist((await page.locator('#lg-m-bezeichnung').getAttribute('placeholder')) === 'z. B. Kopierpapier A4', 'Beispiel der Bezeichnung ohne Bezug auf Medizin');
  ist(await page.locator('#lg-m-aufgabe').evaluate((e) => e.required) && (await page.locator('#lg-m-aufgabe-zahl').textContent()) === '0 / 500', 'Aufgabe ist Pflicht, der Zähler steht da');
  ist(await gesperrt('lg-m-zuordnen', 'Erst nach dem Anlegen') && await gesperrt('lg-m-loesen', 'Erst nach dem Anlegen') && await gesperrt('lg-m-zu', 'Erst nach dem Anlegen'), 'Artikelgruppe anlegen: Zuordnen und Lösen stehen da und sind gesperrt');
  const hoehen = await page.evaluate(() => ['lg-m-einheit', 'lg-m-mindest', 'lg-m-soll'].map((id) => Math.round(document.getElementById(id).getBoundingClientRect().top)));
  ist(hoehen[0] === hoehen[1] && hoehen[1] === hoehen[2], `Lagereinheit, Mindest- und Sollbestand stehen auf einer Höhe: ${hoehen}`);
  await page.locator('#lg-m-bezeichnung').fill('Einmalhandschuh M [TESTDATEN]');
  await page.locator('#lg-m-aufgabe').fill('puderfrei, unsteril, Größe M; für Untersuchung und Reinigung [TESTDATEN]');
  ist((await page.locator('#lg-m-aufgabe-zahl').textContent()) === '72 / 500', 'der Zähler zählt mit');
  await bild('9a-artikelgruppe-anlegen');
  await page.locator('#lg-m-mindest').fill('80');
  await page.locator('#lg-m-soll').fill('200');
  await page.locator('#lg-dialog-gruppe-senden').click();
  await meldung('Artikelgruppe angelegt.');
  const gruppeZeile = page.locator('#lg-tabelle tbody tr.lg-gruppe');
  ist(await gruppeZeile.count() === 1 && await gruppeZeile.locator('.lg-dreieck').isDisabled() && (await gruppeZeile.locator('.lg-dreieck').getAttribute('title')).includes('noch kein Artikel'), 'Gruppe ohne Artikel: Dreieck gesperrt mit Grund');
  // Artikel A über den Dialog des Artikels zuordnen, B im Dialog der Gruppe (AG7)
  await page.locator(`#lg-tabelle tbody tr[data-id="${hA}"]`).click();
  await page.locator('#lg-artikel-aendern').click();
  await page.locator('#lg-dialog-artikel').waitFor();
  ist(await gesperrt('lg-f-still', 'Erst nach dem Anlegen') === false && (await page.locator('#lg-f-gruppe-aufgabe').textContent()).startsWith('Artikel gleicher Aufgabe'), 'Artikel bearbeiten: „stillgelegt“ frei, neben der Gruppe steht die Erklärung');
  await page.locator('#lg-f-gruppe').selectOption({ label: 'Einmalhandschuh M [TESTDATEN] (Stück)' });
  ist(await gesperrt('lg-f-mindest', 'stehen dort') && await gesperrt('lg-f-soll', 'stehen dort'), 'Artikel in einer Gruppe: Mindest- und Sollbestand gesperrt mit Grund');
  ist((await page.locator('#lg-f-gruppe-aufgabe').textContent()).includes('Aufgabe der Gruppe: puderfrei'), 'neben der Auswahl steht die Aufgabe der gewählten Gruppe (AG3)');
  // Eine gewählte Gruppe wird nie still entfernt (AG8): Passt der Artikel nicht, ist „Speichern“ gesperrt mit Grund
  await page.locator('#lg-f-art').selectOption('arzneimittel');
  ist(await gesperrt('lg-dialog-artikel-senden', 'Arzneimittel gehören zu keiner Artikelgruppe') && (await page.locator('#lg-f-gruppe').inputValue()) !== '' && !(await page.locator('#lg-f-gruppe').isDisabled()),
    'Arzneimittel in einer Gruppe: Speichern gesperrt mit Grund, die Gruppe bleibt gewählt und änderbar');
  await page.locator('#lg-f-art').selectOption('verbrauch');
  ist(!(await page.locator('#lg-dialog-artikel-senden').isDisabled()), 'wieder Verbrauch: Speichern frei');
  await page.locator('#lg-dialog-artikel-senden').click();
  await meldung('Artikel gespeichert.');
  await page.locator('#lg-tabelle tbody tr.lg-gruppe').click();
  await page.locator('#lg-artikel-aendern').click();
  await page.locator('#lg-dialog-gruppe').waitFor();
  ist(await page.locator('#lg-m-tabelle tbody tr').count() === 1 && await gesperrt('lg-m-einheit', 'steht fest') && await gesperrt('lg-m-still', 'noch Artikel'), 'Gruppe bearbeiten: ein Artikel in der Liste, Lagereinheit und „stillgelegt“ gesperrt mit Grund');
  const zuWahl = await page.locator('#lg-m-zu option').allTextContents();
  ist(zuWahl.includes('Musterhandschuh B [TESTDATEN]') && !zuWahl.some((t) => t.startsWith('Musterhandschuh A')), `zur Auswahl steht, was in die Gruppe darf und noch in keiner ist: ${JSON.stringify(zuWahl)}`);
  await page.locator('#lg-m-zu').selectOption({ label: 'Musterhandschuh B [TESTDATEN]' });
  await page.locator('#lg-m-zuordnen').click();
  await meldung('„Musterhandschuh B [TESTDATEN]“ zugeordnet.');
  await page.waitForFunction(() => document.querySelectorAll('#lg-m-tabelle tbody tr').length === 2);
  ist(await page.locator('#lg-dialog-gruppe').isVisible() && (await page.locator('#lg-m-tabelle tbody tr.gewaehlt td').first().textContent()).startsWith('Musterhandschuh B'), 'zugeordnet: Der Dialog bleibt offen, der neue Artikel ist markiert');
  await page.locator('#lg-m-loesen').click();
  await meldung('„Musterhandschuh B [TESTDATEN]“ gelöst.');
  await page.waitForFunction(() => document.querySelectorAll('#lg-m-tabelle tbody tr').length === 1);
  await page.locator('#lg-m-zu').selectOption({ label: 'Musterhandschuh B [TESTDATEN]' });
  await page.locator('#lg-m-zuordnen').click();
  await page.waitForFunction(() => document.querySelectorAll('#lg-m-tabelle tbody tr').length === 2);
  await bild('9b-artikelgruppe-bearbeiten');
  await page.locator('#lg-dialog-gruppe-abbrechen').click();
  await page.reload();
  await page.locator('#lg-tabelle tbody tr.lg-gruppe').waitFor();
  let g = (await zeilen('lg-tabelle')).find((z) => z[0].startsWith('Einmalhandschuh M'));
  ist(g[0].includes('Gruppe · 2 Artikel') && g[2] === 'Artikelgruppe' && g[3] === '70 Stück' && g[4] === '80' && g[6].includes('unter Mindestbestand'), `Gruppe als eine Zeile mit der Summe: ${JSON.stringify(g)}`);
  ist(await page.locator(`#lg-tabelle tbody tr[data-id="${hA}"]`).count() === 0, 'die Artikel der Gruppe stehen nicht einzeln in der Liste');
  await gruppeZeile.click();
  await page.waitForFunction(() => document.querySelectorAll('#lg-chargen-tabelle tbody tr').length === 2);
  ist((await page.locator('#lg-chargen-titel').textContent()).startsWith('Chargen aller Artikel') && await page.locator('#lg-chargen-tabelle tbody tr.gewaehlt td').first().textContent() === 'T-HB'
    && (await page.locator('#lg-chargen-tabelle tbody tr.gewaehlt').textContent()).includes('wird vorgeschlagen'), 'unten die Chargen aller Artikel; vorgewählt ist der früheste Verfall über alle – die Charge von B');
  // seit 0.7.86 (F197): Zugang auch an der markierten Gruppe – der Dialog fragt, welcher ihrer Artikel zugeht
  ist(!(await page.locator('#lg-zugang').isDisabled()), 'Zugang an der Gruppe frei');
  await page.locator('#lg-zugang').click();
  await page.locator('#lg-dialog-zugang').waitFor();
  ist((await page.locator('#lg-z-artikel option').count()) === 2 && (await page.locator('#lg-dialog-zugang h2').textContent()).includes('Musterhandschuh B'), 'Zugang an der Gruppe: Auswahl ihrer Artikel, vorgewählt der Artikel der gewählten Charge');
  await page.locator('#lg-z-artikel').selectOption({ label: 'Musterhandschuh A [TESTDATEN]' });
  await page.waitForFunction(() => document.querySelector('#lg-dialog-zugang h2')?.textContent.includes('Musterhandschuh A'));
  ist((await page.locator('#lg-z-artikel option:checked').textContent()) === 'Musterhandschuh A [TESTDATEN]', 'anderer Artikel gewählt: Der Dialog gilt jetzt für ihn');
  await page.locator('#lg-dialog-zugang-abbrechen').click();
  ist((await page.locator('#lg-chargen-hinweis').textContent()).includes('Aufgabe: puderfrei, unsteril'), 'unten steht die Aufgabe der Gruppe (AG3)');
  // Dreieck: mit der Tastatur auf, die Artikel stehen eingerückt darunter; wieder zu
  await gruppeZeile.focus();
  await page.keyboard.press('ArrowRight');
  await page.locator('#lg-tabelle tbody tr.lg-mitglied').first().waitFor();
  ist(await page.locator('#lg-tabelle tbody tr.lg-mitglied').count() === 2 && (await page.locator('#lg-tabelle tbody tr.lg-gruppe .lg-dreieck').getAttribute('aria-expanded')) === 'true', 'aufgeklappt: zwei Artikel unter der Gruppe');
  await bild('9-artikelgruppe');
  // Entnahme aus A weicht vom Vorschlag ab – der liegt beim anderen Artikel der Gruppe
  await page.locator(`#lg-tabelle tbody tr[data-id="${hA}"]`).click();
  await page.waitForFunction(() => document.querySelector('#lg-chargen-tabelle tbody tr.gewaehlt td')?.textContent === 'T-HA');
  await page.locator('#lg-entnahme').click();
  await page.locator('#lg-dialog-entnahme').waitFor();
  ist((await page.locator('#lg-e-abweichung').textContent()).includes('T-HB von „Musterhandschuh B [TESTDATEN]“'), 'Entnahme: Hinweis auf die frühere Charge des anderen Artikels');
  await page.locator('#lg-dialog-entnahme-senden').click();
  await meldung('abweichend vom Vorschlag');
  await page.locator('#lg-tabelle tbody tr.lg-gruppe .lg-dreieck').click();
  await page.waitForFunction(() => document.querySelectorAll('#lg-tabelle tbody tr.lg-mitglied').length === 0);
  ist(true, 'Dreieck schließt wieder');
  // Nachbestellen: eine Zeile für die Gruppe, darunter ihre Artikel
  await page.getByRole('navigation').getByText('Nachbestellen', { exact: true }).click();
  await page.locator('#nb-tabelle tbody tr.lg-gruppe').waitFor();
  const nbG = await zeilen('nb-tabelle');
  const ng = nbG.findIndex((z) => z[0].startsWith('Einmalhandschuh M'));
  ist(nbG[ng][0].includes('(Artikelgruppe) – puderfrei, unsteril') && nbG[ng][2] === '69 Stück' && nbG[ng][5] === '131 Stück' && nbG[ng + 1][0] === '↳ Musterhandschuh A [TESTDATEN]' && nbG[ng + 1][2] === '29 Stück' && nbG[ng + 2][0] === '↳ Musterhandschuh B [TESTDATEN]',
    `Nachbestellen: Gruppe mit Summe und Vorschlag, darunter die Artikel – ${JSON.stringify(nbG.slice(ng, ng + 3))}`);
  await page.getByRole('navigation').getByText('Bestand', { exact: true }).click();
  await page.locator('#lg-tabelle tbody tr').first().waitFor();
  // „Artikel neu …“ führt in die markierte Gruppe (AG8) – auch, wenn ein Artikel der Gruppe markiert ist
  await page.locator('#lg-tabelle tbody tr.lg-gruppe').click();
  ist((await page.locator('#lg-artikel-neu').getAttribute('title')).includes('in der markierten Artikelgruppe „Einmalhandschuh M [TESTDATEN]“') && (await page.locator('#lg-artikel-neu').textContent()) === 'Artikel neu …',
    'Gruppe markiert: Der Knopf heißt wie immer, seine Hilfe nennt die Gruppe');
  await page.locator('#lg-tabelle tbody tr.lg-gruppe .lg-dreieck').click();
  await page.locator(`#lg-tabelle tbody tr[data-id="${hB}"]`).click();
  ist((await page.locator('#lg-artikel-neu').getAttribute('title')).includes('in der markierten Artikelgruppe'), 'Artikel der Gruppe markiert: dasselbe');
  await page.locator('#lg-artikel-neu').click();
  await page.locator('#lg-dialog-artikel').waitFor();
  ist((await page.locator('#lg-f-gruppe option:checked').textContent()) === 'Einmalhandschuh M [TESTDATEN] (Stück)' && (await page.locator('#lg-f-einheit').inputValue()) === 'Stück'
    && await gesperrt('lg-f-mindest', 'stehen dort') && await gesperrt('lg-f-still', 'Erst nach dem Anlegen'), 'Artikel anlegen: Gruppe vorgewählt, ihre Lagereinheit vorbelegt, Mindestbestand gesperrt, „stillgelegt“ steht da und ist gesperrt');
  // seit 0.7.85 (F192): Seriennummern schließen eine Gruppe nicht mehr aus; Betäubungsmittel weiter
  await page.locator('#lg-f-fuehrung').selectOption('serie');
  ist(!(await page.locator('#lg-dialog-artikel-senden').isDisabled()) && (await page.locator('#lg-f-gruppe-aufgabe').textContent()).startsWith('Aufgabe der Gruppe'), 'Seriennummer: passt in die Gruppe, Anlegen frei');
  await page.locator('#lg-f-btm').check();
  ist(await gesperrt('lg-dialog-artikel-senden', 'Betäubungsmittel gehören zu keiner Artikelgruppe') && (await page.locator('#lg-f-gruppe option:checked').textContent()).startsWith('Einmalhandschuh M'), 'Betäubungsmittel: Anlegen gesperrt mit Grund, die Gruppe bleibt gewählt');
  await page.locator('#lg-f-btm').uncheck();
  await page.locator('#lg-f-fuehrung').selectOption('ohne');
  await page.locator('#lg-f-einheit').fill('Paar');
  ist(await gesperrt('lg-dialog-artikel-senden', 'ist nicht die der Artikelgruppe'), 'andere Lagereinheit: Anlegen gesperrt mit Grund');
  await page.locator('#lg-f-einheit').fill('Stück');
  await page.locator('#lg-f-art').selectOption('verbrauch');
  await page.locator('#lg-f-bezeichnung').fill('Musterhandschuh C [TESTDATEN]');
  await bild('9c-artikel-in-gruppe');
  await page.locator('#lg-dialog-artikel-senden').click();
  await meldung('Artikel angelegt – in der Artikelgruppe „Einmalhandschuh M [TESTDATEN]“.');
  await page.waitForFunction(() => document.querySelectorAll('#lg-tabelle tbody tr.lg-mitglied').length === 3);
  ist((await page.locator('#lg-tabelle tbody tr.gewaehlt').getAttribute('class')).includes('lg-gruppe'), 'nach dem Anlegen: Die Gruppe bleibt markiert und ist aufgeklappt, mit drei Artikeln');
  // ohne markierte Gruppe bleibt alles wie bisher
  await page.locator('#lg-tabelle tbody tr:not(.lg-gruppe):not(.lg-mitglied)').first().click();
  ist((await page.locator('#lg-artikel-neu').getAttribute('title')) === 'Artikel anlegen', 'kein Artikel einer Gruppe markiert: gewöhnliche Hilfe');
  await page.locator('#lg-artikel-neu').click();
  await page.locator('#lg-dialog-artikel').waitFor();
  ist((await page.locator('#lg-f-gruppe').inputValue()) === '', 'dann ist keine Gruppe vorgewählt');
  await page.locator('#lg-dialog-artikel-abbrechen').click();
  // --- Freier Textfilter am Spaltenkopf (F193, F198): im Menü der Spalte tippen, die Liste folgt sofort ---
  const obenZeilen = () => page.locator('#lg-tabelle tbody tr:not(.lg-mitglied):not(.leer)').count();
  const vorher = await obenZeilen();
  await page.locator('#lg-tabelle th details[data-spalte="artikel"] summary').click();
  await page.waitForFunction(() => document.activeElement?.classList.contains('sp-tippen'));
  ist(true, 'Menü der Spalte offen: Das Feld hat den Fokus – einfach tippen');
  await page.keyboard.type('handschuh c', { delay: 15 });
  await page.waitForFunction(() => document.querySelectorAll('#lg-tabelle tbody tr:not(.lg-mitglied):not(.leer)').length === 1);
  ist((await page.locator('#lg-tabelle tbody tr').first().getAttribute('class')).includes('lg-gruppe') && (await page.locator('#lg-tabelle').locator('..').locator('..').locator('.liste-fuss').textContent()).includes('gefiltert'),
    'beim Tippen gefiltert: Die Gruppe wird über ihren Artikel gefunden, der Fuß sagt „gefiltert“');
  ist(await page.evaluate(() => document.activeElement?.classList.contains('sp-tippen') && document.activeElement.value === 'handschuh c'), 'das Feld behält Fokus und Text, obwohl die Liste neu gezeichnet wird');
  ist((await page.locator('#lg-tabelle th details[data-spalte="artikel"] .sp-marke').textContent()).includes('gefiltert') && !(await page.locator('#lg-tabelle th details[data-spalte="artikel"] .sp-aufheben').isDisabled()), 'der Spaltenkopf zeigt „gefiltert“, „Filter aufheben“ ist frei');
  await bild('9d-textfilter');
  await page.keyboard.type(' gibtesnicht');
  await page.locator('#lg-kein-treffer').waitFor();
  await page.keyboard.press('Escape');
  await page.waitForFunction((n) => document.querySelectorAll('#lg-tabelle tbody tr:not(.lg-mitglied):not(.leer)').length === n, vorher);
  ist(await page.locator('#lg-tabelle th details[data-spalte="artikel"]').getAttribute('open') !== null, 'Esc leert das Feld, die Liste ist wieder vollständig, das Menü bleibt offen');
  await page.keyboard.press('Escape');
  ist(await page.locator('#lg-tabelle th details[data-spalte="artikel"]').getAttribute('open') === null, 'ein zweites Esc schließt das Menü');

  // --- Stückliste am Artikel (0.7.86, F197): im Dialog des Artikels gepflegt, unten als Baum ---
  await page.locator('#lg-tabelle tbody tr:not(.lg-gruppe):not(.lg-mitglied)').first().click();
  const ganzes = (await page.locator('#lg-tabelle tbody tr.gewaehlt td').nth(1).textContent());
  await page.locator('#lg-artikel-aendern').click();
  await page.locator('#lg-dialog-artikel').waitFor();
  ist((await page.locator('#lg-s-zahl').textContent()).startsWith('keine') && await gesperrt('lg-s-dazu', 'Wähle zuerst ein Teil') && await gesperrt('lg-s-text', 'Nur für ein Teil ohne eigenen Artikel'), 'Stückliste leer: Hinzufügen und Freitext gesperrt mit Grund');
  ist(await page.locator('#lg-s-teil option').evaluateAll((os, name) => !os.some((o) => name.startsWith(o.textContent.replace(/ \([^)]*\)$/, '')) && o.value.startsWith('a:')), ganzes), 'der Artikel selbst steht nicht zur Wahl');
  await page.locator('#lg-s-teil').selectOption({ label: 'Musterhandschuh A [TESTDATEN] (Stück)' });
  await page.locator('#lg-s-menge').fill('2');
  await page.locator('#lg-s-dazu').click();
  await page.locator('#lg-s-teil').selectOption({ label: 'Einmalhandschuh M [TESTDATEN] (Stück)' });
  await page.locator('#lg-s-erf').uncheck();
  await page.locator('#lg-s-dazu').click();
  await page.locator('#lg-s-teil').selectOption('frei');
  await page.locator('#lg-s-text').fill('Musterkabel [TESTDATEN]');
  await page.locator('#lg-s-dazu').click();
  ist((await page.locator('#lg-s-tabelle tbody tr').count()) === 3 && (await page.locator('#lg-s-zahl').textContent()) === '3 Teile', 'drei Zeilen: Artikel, Artikelgruppe, Teil ohne eigenen Artikel');
  await page.locator('#lg-s-teil').selectOption({ label: 'Musterhandschuh A [TESTDATEN] (Stück)' });
  ist(await gesperrt('lg-s-dazu', 'steht schon in der Stückliste'), 'dasselbe Teil zweimal: gesperrt mit Grund');
  await page.locator('#lg-s-tabelle tbody tr').first().locator('.lg-s-zmenge').fill('abc');
  ist(await gesperrt('lg-dialog-artikel-senden', 'Stückliste: Jede Menge'), 'unlesbare Menge: Speichern gesperrt mit Grund, nichts wird still geändert');
  await page.locator('#lg-s-tabelle tbody tr').first().locator('.lg-s-zmenge').fill('2');
  await bild('9e-stueckliste-dialog');
  await page.locator('#lg-dialog-artikel-senden').click();
  await meldung('Artikel gespeichert.');
  await page.locator('#lg-stueckliste').waitFor();
  ist(!(await page.locator('#lg-chargen').textContent()).includes('null'), 'unten steht kein Platzhalter');
  ist((await page.locator('#lg-tabelle tbody tr.gewaehlt .lg-marke-sl').textContent()) === 'Stückliste · 3 Teile', 'die Liste zeigt die Marke „Stückliste · 3 Teile“');
  const baumText = await page.locator('#lg-stueckliste .lg-baum li').allTextContents();
  ist(baumText.length === 3 && baumText[0].startsWith('2 × Musterhandschuh A') && baumText[0].includes('im Lager:') && baumText[1].includes('optional') && baumText[1].includes('Artikelgruppe – einer davon') && baumText[2].includes('ohne eigenen Artikel'),
    'unten der Baum: Menge, Teil, Bestand des Teils, optional, Gruppe und Teil ohne Artikel in Worten');
  // ein Teil mit eigener Stückliste: Der Baum bekommt eine zweite Ebene; ein Kreis steht nicht zur Wahl
  await page.locator('#lg-tabelle tbody tr.lg-gruppe .lg-dreieck').click().catch(() => {});
  if (await page.locator('#lg-tabelle tbody tr.lg-mitglied').count() === 0) await page.locator('#lg-tabelle tbody tr.lg-gruppe .lg-dreieck').click();
  await page.locator('#lg-tabelle tbody tr.lg-mitglied').filter({ hasText: 'Musterhandschuh A' }).click();
  await page.locator('#lg-artikel-aendern').click();
  await page.locator('#lg-dialog-artikel').waitFor();
  ist(await page.locator('#lg-s-teil option').evaluateAll((os, name) => !os.some((o) => o.textContent.startsWith(name.trim().slice(0, 12))), ganzes), 'im Teil steht das Ganze nicht zur Wahl – das wäre ein Kreis');
  await page.locator('#lg-s-teil').selectOption('frei');
  await page.locator('#lg-s-text').fill('Musterschraube [TESTDATEN]');
  await page.locator('#lg-s-menge').fill('4');
  await page.locator('#lg-s-dazu').click();
  await page.locator('#lg-dialog-artikel-senden').click();
  await meldung('Artikel gespeichert.');
  await page.locator('#lg-tabelle tbody tr:not(.lg-gruppe):not(.lg-mitglied)').first().click();
  await page.locator('#lg-stueckliste details').waitFor();
  ist((await page.locator('#lg-stueckliste .lg-baum .lg-baum li').first().textContent()).startsWith('4 × Musterschraube'), 'zweite Ebene im Baum: das Teil des Teils');
  await bild('9f-stueckliste-baum');

  // --- „Zugang ohne Beleg …“ auch von „Wareneingang“ aus: führt zu „Bestand“ und öffnet dort den Dialog ---
  await page.getByRole('navigation').getByText('Wareneingang', { exact: true }).click();
  await page.locator('#we-ohne-beleg').waitFor();
  await bild('9g-wareneingang');
  await page.locator('#we-ohne-beleg').click();
  await page.locator('#lg-dialog-zugang').waitFor();
  ist(await page.locator('#lg-tabelle').isVisible(), 'von „Wareneingang“: „Bestand“ ist offen und der Dialog „Zugang ohne Beleg“ steht da');
  await page.locator('#lg-dialog-zugang-abbrechen').click();
  await page.emulateMedia({ media: 'print' });
  ist(!(await page.locator('.navigation').isVisible()) && !(await page.locator('#lg-entnahme').isVisible()) && await page.locator('#lg-tabelle').isVisible(), 'Druck: nur die Bestandsliste');
  await page.emulateMedia({ media: 'screen' });
} finally {
  await browser.close();
  server.kill();
  execFileSync('php', [path.join(wurzel, 'tests/browser/aufbau.php'), '--abbau']);
}
// die erste Anfrage ohne Anmeldung (401) gehört zum Ablauf
const echteFehler = fehler.filter((f) => !/status of 401/.test(f));
if (echteFehler.length) {
  console.error(echteFehler.join('\n'));
  console.error(serverLog.split('\n').filter((z) => /error|warn|kumero\[/i.test(z)).slice(-20).join('\n'));
  process.exit(1);
}
console.log(`${pruefungen} Prüfungen im Browser, alle grün`);
