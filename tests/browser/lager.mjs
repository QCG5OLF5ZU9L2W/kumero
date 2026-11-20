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

const umgebung = JSON.parse(execFileSync('php', [path.join(wurzel, 'tests/browser/aufbau.php'), ADRESSE, '--vorrat', '--lager'], { encoding: 'utf8' }).trim().split('\n').pop());
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
  const zeilen = (id) => page.locator(`#${id} tbody tr`).evaluateAll((trs) => trs.map((tr) => [...tr.children].map((td) => td.textContent)));

  await page.goto(umgebung.link);
  await page.getByText('Passkey registrieren', { exact: true }).first().waitFor();
  await page.locator('#registrierung-bezeichnung').fill('Virtueller Passkey');
  await page.getByRole('button', { name: 'Passkey registrieren' }).click();
  await page.getByText('Passkey registriert. Jetzt anmelden.').waitFor();
  await page.locator('#anmelden').click();
  await page.getByRole('navigation').getByText('Konten', { exact: true }).waitFor();

  const api = (m, p, j) => page.evaluate(async ([m2, p2, j2]) => {
    const r = await fetch(`/m/browserpraxis/b/haupt/api/v1${p2}`, { method: m2, credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'Idempotency-Key': crypto.randomUUID() }, body: j2 === undefined ? undefined : JSON.stringify(j2) });
    return [r.status, r.status === 204 ? null : await r.json()];
  }, [m, p, j]);
  const ok = async (m, p, j) => { const [s, r] = await api(m, p, j); if (s >= 300) throw new Error(`${m} ${p}: ${s} ${JSON.stringify(r)}`); return r; };
  const tag = (n) => { const d = new Date(); d.setDate(d.getDate() + n); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`; };
  const de = (iso) => `${iso.slice(8, 10)}.${iso.slice(5, 7)}.${iso.slice(0, 4)}`;
  const kurz = (iso) => `${iso.slice(2, 4)}${iso.slice(5, 7)}${iso.slice(8, 10)}`;

  // Zerlegen des Codes einer Packung (WW16): Klammerschreibweise, Rohfolge des Scanners, Tag 00
  const codes = await page.evaluate(async () => {
    const m = await import('/module/lager/0.1.0/gemeinsam.js');
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

  // Kachel als Filter; Suche über den gescannten Code
  await page.locator('#lg-k-unter_mindest').click();
  ist((await zeilen('lg-tabelle')).length === 0 || (await page.locator('#lg-anzahl').textContent()) === '0 Artikel', 'Filter „unter Mindestbestand“: der Artikel hat genug');
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

  // Druck des Bestands: ohne Navigation und Werkzeuge
  await page.getByRole('navigation').getByText('Bestand', { exact: true }).click();
  await page.locator('#lg-tabelle tbody tr').first().waitFor();
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
