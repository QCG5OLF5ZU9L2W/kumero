// SPDX-License-Identifier: AGPL-3.0-or-later
// Browsertest der Seite „Bedarf und Bestellung“ (BESTELLANFORDERUNG.md Stufe 1, F222): Vorschlag aus dem Bestand,
// Anforderung mit Positionen fürs Lager und nicht fürs Lager, Weiterleiten, Freigeben, Abhaken als bestellt mit
// Artikelanlage, Teilen, Streichen, Haken zurücknehmen, Eingang melden, Ablehnen, Entwurf löschen.
// Alle Artikel, Lieferanten, Nummern und Preise sind erfunden.
//
//   KUMERO_TEST_PGHOST=/tmp/pgtest KUMERO_TEST_PGUSER=postgres \
//   PLAYWRIGHT=/opt/npm-tools/node_modules/playwright CHROME=/usr/bin/google-chrome node tests/browser/beschaffung.mjs [Bildverzeichnis]
import { spawn, execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { mkdirSync, readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const require = createRequire(import.meta.url);
const { chromium } = require(process.env.PLAYWRIGHT || 'playwright');
const wurzel = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const PORT = 8103;
const ADRESSE = `http://localhost:${PORT}`;
const bilder = process.argv[2] || null;
if (bilder) mkdirSync(bilder, { recursive: true });

let pruefungen = 0;
function ist(b, was) { if (!b) throw new Error(`fehlgeschlagen: ${was}`); pruefungen++; }

const umgebung = JSON.parse(execFileSync('php', [path.join(wurzel, 'tests/browser/aufbau.php'), ADRESSE, '--lager', '--beschaffung'], { encoding: 'utf8' }).trim().split('\n').pop());
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
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'Idempotency-Key': crypto.randomUUID(), 'If-Match': '*' }, body: j2 === undefined ? undefined : JSON.stringify(j2) });
    return [r.status, r.status === 204 ? null : await r.json()];
  }, [m, p, j]);
  const ok = async (m, p, j) => { const [s, r] = await api(m, p, j); if (s >= 300) throw new Error(`${m} ${p}: ${s} ${JSON.stringify(r)}`); return r; };
  const tag = (n) => { const d = new Date(); d.setDate(d.getDate() + n); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`; };
  const de = (iso) => `${iso.slice(8, 10)}.${iso.slice(5, 7)}.${iso.slice(0, 4)}`;
  const kurz = (iso) => `${iso.slice(2, 4)}${iso.slice(5, 7)}${iso.slice(8, 10)}`;

  // Lager: ein Artikel unter Mindestbestand – über die Schnittstelle, die Seite „Bestand“ prüft lager.mjs
  await ok('POST', '/lager/artikel', { bezeichnung: 'Musterhandschuh [TESTDATEN]', art: 'verbrauch', einheit: 'Stück', hat_charge: false, hat_serie: false, in_stuecken: true, mindest: '10', soll: '20' });

  await page.getByRole('navigation').getByText('Bedarf', { exact: true }).click();
  await page.locator('#bs-leer').waitFor();
  ist(await gesperrt('bs-aendern', 'Wähle zuerst eine Anforderung') && await gesperrt('bs-freigeben', 'Wähle zuerst eine Anforderung') && await gesperrt('bs-uebernahme', 'Wechsle zum Vorschlag'), 'leer: Knöpfe gesperrt mit Grund');
  await bild('1-leer');

  // --- Vorschlag aus dem Bestand → Anforderung ---
  await page.locator('#bs-reiter-vorschlag').click();
  await page.locator('#bs-vorschlag').waitFor();
  ist(JSON.stringify((await zeilen('bs-vorschlag'))[0].slice(1)) === JSON.stringify(['Musterhandschuh [TESTDATEN]', '', '0 Stück', '10', '20', '20', '', '20 Stück']), `Vorschlag: ${JSON.stringify(await zeilen('bs-vorschlag'))}`);
  ist(await gesperrt('bs-uebernahme', 'Markiere zuerst Zeilen'), 'ohne Markierung gesperrt');
  await page.locator('#bs-vorschlag tbody input[type=checkbox]').check();
  await bild('2-vorschlag');
  await page.locator('#bs-uebernahme').click();
  await page.locator('#bs-dialog-anforderung').waitFor();
  ist(JSON.stringify((await zeilen('bs-f-tabelle')).map((z) => z.slice(1, 2))) === JSON.stringify([['Musterhandschuh [TESTDATEN]']]), 'Position aus dem Vorschlag');
  ist(await page.locator('.bs-f-menge').inputValue() === '20', 'Menge aus dem Vorschlag');
  ist(await gesperrt('bs-dialog-anforderung-senden', 'Wofür fehlt') && await gesperrt('bs-f-anfordern', 'Wofür fehlt'), 'ohne „Wofür“ gesperrt');
  await page.locator('#bs-f-wofuer').fill('Auffüllen Behandlungsraum [TESTDATEN]');
  await page.locator('.bs-f-menge').fill('12');
  await page.locator('.bs-f-menge').dispatchEvent('change');
  // eine Position, die nie ins Lager geht, und eine, zu der es noch keinen Artikel gibt
  ist(await gesperrt('bs-p-dazu', 'Wähle, was gebraucht wird'), '„Dazu“ gesperrt ohne Auswahl');
  await page.locator('#bs-p-was').selectOption('frei');
  ist(await gesperrt('bs-p-dazu', 'Bezeichnung fehlt'), '„Dazu“ nennt, was fehlt');
  await page.locator('#bs-p-bezeichnung').fill('Wartung Mustergerät [TESTDATEN]');
  await page.locator('#bs-p-dazu').click();
  await page.locator('#bs-p-was').selectOption('neu');
  ist((await page.locator('#bs-p-hinweis').textContent()).includes('spätestens beim Bestellen'), 'Hinweis zum neuen Artikel');
  await page.locator('#bs-p-bezeichnung').fill('Neues Musterteil [TESTDATEN]');
  await page.locator('#bs-p-menge').fill('5');
  await page.locator('#bs-p-dazu').click();
  ist((await zeilen('bs-f-tabelle')).length === 3, 'drei Positionen');
  await bild('3-anforderung');
  await page.locator('#bs-f-anfordern').click();
  const jahr = new Date().getFullYear();
  await meldung(`Angefordert als BA-${jahr}-0001`);
  await page.locator('#bs-tabelle').waitFor();
  ist(JSON.stringify((await zeilen('bs-tabelle'))[0].slice(0, 3)) === JSON.stringify([`BA-${jahr}-0001`, 'Auffüllen Behandlungsraum [TESTDATEN]', 'angefordert']), `Liste: ${JSON.stringify(await zeilen('bs-tabelle'))}`);
  ist((await zeilen('bs-positionen')).length === 3 && (await page.locator('#bs-positionen').textContent()).includes('noch kein Artikel') && (await page.locator('#bs-positionen').textContent()).includes('nicht fürs Lager'), 'unten die Positionen mit ihren Marken');
  ist(await gesperrt('bs-haken', 'erst, was freigegeben ist') && await gesperrt('bs-anfordern', 'schon angefordert'), 'vor der Freigabe nicht bestellen');
  await page.locator('#bs-reiter-vorschlag').click();
  ist(JSON.stringify((await zeilen('bs-vorschlag'))[0].slice(6)) === JSON.stringify(['20', '12', '8 Stück']), 'Vorschlag zieht das Angeforderte ab');
  await page.locator('#bs-reiter-beimir').click();
  ist((await page.locator('#bs-stand').textContent()).includes('1 Anforderung wartet auf dich'), 'bei mir: wartet auf die Freigabe');

  // --- Weiterleiten: die Auswahl bleibt, der Knopf nennt den Grund (SA6) ---
  await page.locator('#bs-weiter').click();
  await page.locator('#bs-dialog-weiter').waitFor();
  ist(await gesperrt('bs-dialog-weiter-senden', 'Wähle, an wen'), 'ohne Empfänger gesperrt');
  ist(await page.locator('#bs-w-wozu option[value=bestellen]').evaluate((o) => o.disabled), '„zum Bestellen“ erst nach der Freigabe');
  await page.locator('#bs-w-an').selectOption('r:Lager');
  ist(await gesperrt('bs-dialog-weiter-senden', 'Lager darf nicht freigeben') && await page.locator('#bs-w-an').inputValue() === 'r:Lager', 'Rolle Lager gibt nicht frei – die Auswahl bleibt stehen');
  await page.locator('#bs-w-an').selectOption('r:Einkauf');
  await page.locator('#bs-w-text').fill('Bitte bis Freitag');
  await bild('4-weiterleiten');
  await page.locator('#bs-dialog-weiter-senden').click();
  await meldung('Weitergeleitet an Einkauf zur Freigabe');
  ist((await page.locator('#bs-kopf-bei').textContent()) === 'liegt bei Einkauf (Rolle) zur Freigabe', 'Kopf nennt, bei wem');
  ist(await gesperrt('bs-nehmen', 'wer die Rolle „Einkauf“ hat'), 'Erika hat die Rolle Einkauf nicht – Übernehmen gesperrt');
  ist((await page.locator('#bs-verlauf').textContent()).includes('hat weitergeleitet an Einkauf (Rolle) zur Freigabe – „Bitte bis Freitag“'), 'Verlauf');

  // --- Freigeben, dann abhaken ---
  await page.locator('#bs-freigeben').click();
  await meldung('Freigegeben');
  ist(await gesperrt('bs-aendern', 'ändern lässt sie sich nicht mehr') && (await page.locator('#bs-kopf-stand').textContent()) === 'Stand: freigegeben', 'freigegeben');
  await page.locator('#bs-positionen tbody tr').first().dblclick();
  await page.locator('#bs-dialog-bestellen').waitFor();
  ist(await page.locator('#bs-b-menge').inputValue() === '12' && await page.locator('#bs-n-bezeichnung').isDisabled() && !(await page.locator('#bs-b-gtin').isDisabled()), 'Doppelklick öffnet das Abhaken; Artikel gewählt, GTIN lässt sich ergänzen');
  ist(await gesperrt('bs-dialog-bestellen-senden', 'Lieferant fehlt'), 'ohne Lieferant gesperrt');
  await page.locator('#bs-b-menge').fill('15');
  ist((await page.locator('#bs-b-menge-hinweis').textContent()) === '15 statt 12 Stück angefordert', 'Abweichung der Menge steht da');
  await page.locator('#bs-b-lieferant').fill('Mustershop [TESTDATEN]');
  await page.locator('#bs-b-gtin').fill('0401234');
  ist(await gesperrt('bs-dialog-bestellen-senden', 'GTIN: 8 bis 14 Ziffern erwartet – es sind 7') && (await page.locator('#bs-dialog-bestellen-fehler').textContent()).includes('es sind 7'), 'falsche GTIN: Grund im Dialog');
  await page.locator('#bs-b-gtin').fill('04012345678901');
  await page.locator('#bs-b-bestellnummer').fill('B-1');
  await page.locator('#bs-b-nummer-dort').fill('MS-1');
  await page.locator('#bs-b-preis').fill('1,2345');
  await page.locator('#bs-b-vermerk').fill('3 Packungen zu 5');
  await bild('5-abhaken');
  await page.locator('#bs-dialog-bestellen-senden').click();
  await meldung('„Musterhandschuh [TESTDATEN]“ ist bestellt');
  let pos = await zeilen('bs-positionen');
  ist(JSON.stringify(pos[0].slice(2, 8)) === JSON.stringify(['12 Stück', '15', '', 'bestellt', 'Mustershop [TESTDATEN]', 'B-1']) && pos[0][10] === '1,2345 €', `Position 1 bestellt: ${JSON.stringify(pos[0])}`);
  ist((await page.locator('#bs-kopf-stand').textContent()) === 'Stand: teilweise bestellt', 'teilweise bestellt');

  // nächste Position: Lieferant und Bestellnummer stehen noch da (BA23); der Artikel wird hier angelegt (BA12)
  await page.locator('#bs-positionen tbody tr').nth(2).click();
  await page.locator('#bs-haken').click();
  await page.locator('#bs-dialog-bestellen').waitFor();
  ist(await page.locator('#bs-b-lieferant').inputValue() === 'Mustershop [TESTDATEN]' && await page.locator('#bs-b-bestellnummer').inputValue() === 'B-1', 'Lieferant und Bestellnummer bleiben stehen');
  ist(await page.locator('#bs-b-artikel').inputValue() === 'neu' && await page.locator('#bs-n-bezeichnung').inputValue() === 'Neues Musterteil [TESTDATEN]', 'neuer Artikel, vorbelegt aus der Position');
  await page.locator('#bs-n-bezeichnung').fill('Musterteil A [TESTDATEN]');
  await page.locator('#bs-n-serie').check();
  ist(await page.locator('#bs-n-stuecke').isDisabled() && await page.locator('#bs-n-stuecke').isChecked(), 'mit Seriennummer: in Stücken');
  await page.locator('#bs-b-menge').fill('4');
  await page.locator('#bs-b-rest').fill('1');
  await bild('6-artikel-anlegen');
  await page.locator('#bs-dialog-bestellen-senden').click();
  await meldung('der Artikel ist im Lager angelegt');
  pos = await zeilen('bs-positionen');
  ist(pos.length === 4 && pos[2][1].startsWith('Musterteil A [TESTDATEN]') && pos[3][5] === 'offen' && pos[3][2] === '1 Stück', `Artikel angelegt, Rest als neue Position: ${JSON.stringify(pos.slice(2))}`);
  const lager = await ok('GET', '/lager');
  ist(lager.artikel.some((a) => a.bezeichnung === 'Musterteil A [TESTDATEN]' && a.hat_serie) && lager.artikel.find((a) => a.bezeichnung === 'Musterhandschuh [TESTDATEN]').gtin === '04012345678901', 'im Lager: neuer Artikel, GTIN ergänzt');

  // Rest streichen; nicht fürs Lager bestellen, Haken zurücknehmen, neu setzen
  await page.locator('#bs-positionen tbody tr').nth(3).click();
  await page.locator('#bs-streichen').click();
  ist(await gesperrt('bs-dialog-streichen-senden', 'Grund fehlt'), 'Streichen braucht einen Grund');
  await page.locator('#bs-dialog-streichen-grund').fill('reicht so');
  await page.locator('#bs-dialog-streichen-senden').click();
  await meldung('gestrichen');
  await page.locator('#bs-positionen tbody tr').nth(1).click();
  await page.locator('#bs-haken').click();
  ist(await page.locator('#bs-b-artikel').count() === 0, 'nicht fürs Lager: kein Artikel im Dialog');
  await page.locator('#bs-b-lieferant').fill('Falscher Shop [TESTDATEN]');
  ist((await page.locator('#bs-b-nummer-hinweis').textContent()) === 'steht noch von der Bestellung bei Mustershop [TESTDATEN]' && await page.locator('#bs-b-bestellnummer').inputValue() === 'B-1', 'anderer Lieferant: die Bestellnummer bleibt, der Hinweis nennt ihre Herkunft');
  await page.locator('#bs-b-bestellnummer').fill('');
  await page.locator('#bs-dialog-bestellen-senden').click();
  await meldung('ist bestellt');
  await page.locator('#bs-positionen tbody tr').nth(1).click();
  await page.locator('#bs-haken-weg').click();
  await meldung('Haken zurückgenommen');
  ist((await zeilen('bs-positionen'))[1][5] === 'offen', 'wieder offen');
  await page.locator('#bs-positionen tbody tr').nth(1).click();
  await page.locator('#bs-haken').click();
  await page.locator('#bs-b-lieferant').fill('Musterdienst [TESTDATEN]');
  await page.locator('#bs-dialog-bestellen-senden').click();
  await meldung('ist bestellt');
  ist((await page.locator('#bs-kopf-stand').textContent()) === 'Stand: bestellt', 'alles bestellt');

  // --- Eingang melden: Teilmenge, Rest, Abschließen mit weniger ---
  await page.locator('#bs-positionen tbody tr').first().dblclick();
  await page.locator('#bs-dialog-erhalten').waitFor();
  ist(await page.locator('#bs-e-menge').inputValue() === '15' && (await page.locator('#bs-dialog-erhalten').textContent()).includes('bucht nichts ins Lager'), 'Doppelklick auf Bestelltes: Eingang melden, mit dem Hinweis zum Lager');
  await page.locator('#bs-e-menge').fill('10');
  ist((await page.locator('#bs-e-hinweis').textContent()) === 'danach noch offen: 5', 'Hinweis zur Teilmenge');
  await page.locator('#bs-dialog-erhalten-senden').click();
  await meldung('Teilmenge');
  ist((await zeilen('bs-positionen'))[0][4] === '10' && (await page.locator('#bs-kopf-stand').textContent()) === 'Stand: teilweise erhalten' && await gesperrt('bs-haken-weg', 'schon etwas als erhalten gemeldet'), 'teilweise erhalten; Haken nicht mehr zurückzunehmen');
  for (const [nr, menge, fertig] of [[0, '5', false], [1, '1', false], [2, '3', true]]) {
    await page.locator('#bs-positionen tbody tr').nth(nr).click();
    await page.locator('#bs-erhalten').click();
    await page.locator('#bs-e-menge').fill(menge);
    if (fertig) { await page.locator('#bs-e-fertig').check(); ist((await page.locator('#bs-e-hinweis').textContent()) === 'abgeschlossen mit 3 von 4', 'Abschließen mit weniger'); }
    await page.locator('#bs-dialog-erhalten-senden').click();
    await page.locator('#bs-dialog-erhalten').waitFor({ state: 'detached' });
    await page.waitForFunction((n2) => document.querySelectorAll('#bs-positionen tbody tr')[n2]?.children[5].textContent.startsWith('erhalten'), nr);
  }
  ist((await page.locator('#bs-kopf-stand').textContent()) === 'Stand: erledigt', 'erledigt');
  await bild('7-erledigt');
  await page.locator('#bs-reiter-beimir').click();
  ist((await page.locator('#bs-stand').textContent()) === 'Nichts wartet auf dich.', 'bei mir ist nichts mehr');

  // --- Entwurf: speichern, mit Doppelklick öffnen, löschen; zweite Anforderung ablehnen ---
  await page.locator('#bs-neu').click();
  await page.locator('#bs-f-wofuer').fill('Musterabo [TESTDATEN]');
  ist(await gesperrt('bs-f-anfordern', 'noch keine Position') && !(await page.locator('#bs-dialog-anforderung-senden').isDisabled()), 'Entwurf ohne Position speicherbar, aber nicht anzufordern');
  await page.locator('#bs-dialog-anforderung-senden').click();
  await meldung('Entwurf gespeichert');
  ist((await zeilen('bs-tabelle'))[0][0] === 'Entwurf' && await gesperrt('bs-anfordern', 'noch keine Position') && await gesperrt('bs-weiter', 'erst anfordern'), 'Entwurf in der Liste');
  await page.locator('#bs-tabelle tbody tr').first().dblclick();
  await page.locator('#bs-dialog-anforderung').waitFor();
  await page.locator('#bs-p-was').selectOption('frei');
  await page.locator('#bs-p-bezeichnung').fill('Musterzeitschrift [TESTDATEN]');
  await page.locator('#bs-p-einheit').fill('Jahr');
  await page.locator('#bs-p-dazu').click();
  await page.locator('#bs-f-anfordern').click();
  await meldung(`Angefordert als BA-${jahr}-0002`);
  await page.locator('#bs-ablehnen').click();
  await page.locator('#bs-dialog-ablehnen-grund').fill('Gibt es schon digital');
  await page.locator('#bs-dialog-ablehnen-senden').click();
  await meldung('abgelehnt');
  ist((await page.locator('#bs-kopf-grund').textContent()) === 'Grund: Gibt es schon digital' && await gesperrt('bs-freigeben', 'schon entschieden'), 'abgelehnt mit Grund');
  await page.locator('#bs-neu').click();
  await page.locator('#bs-f-wofuer').fill('Versehen [TESTDATEN]');
  await page.locator('#bs-dialog-anforderung-senden').click();
  await meldung('Entwurf gespeichert');
  await page.locator('#bs-zurueck').click();
  await meldung('Entwurf gelöscht');
  ist((await zeilen('bs-tabelle')).length === 2, 'der Entwurf ist weg, zwei Anforderungen bleiben');

  // Freitext am Spaltenkopf durchsucht auch die Positionen (F198)
  await page.locator('#bs-tabelle details[data-spalte="positionen"] summary').click();
  await page.waitForFunction(() => document.activeElement?.classList.contains('sp-tippen'));
  await page.keyboard.type('Musterteil A');
  ist((await zeilen('bs-tabelle')).length === 1 && (await zeilen('bs-tabelle'))[0][0] === `BA-${jahr}-0001`, 'Filter findet die Anforderung über ihre Position');
  await bild('8-filter');
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
