// SPDX-License-Identifier: AGPL-3.0-or-later
// Browsertest der Seite „Auswertungen“ (AUSWERTUNGEN.md, F128): Summen- und Saldenliste, Journal, Kontoblätter,
// eine eigene Auswertung zusammenstellen, speichern, CSV und Druckansicht. Alle Buchungen sind erfunden.
//
//   KUMERO_TEST_PGHOST=/tmp/pgtest KUMERO_TEST_PGUSER=postgres \
//   PLAYWRIGHT=/opt/npm-tools/node_modules/playwright CHROME=/usr/bin/google-chrome node tests/browser/auswertungen.mjs [Bildverzeichnis]
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

const umgebung = JSON.parse(execFileSync('php', [path.join(wurzel, 'tests/browser/aufbau.php'), ADRESSE, '--buchen', '--auswertungen'], { encoding: 'utf8' }).trim().split('\n').pop());
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
  const zeilen = () => page.locator('#aw-tabelle tbody tr').evaluateAll((trs) => trs.map((tr) => [...tr.children].map((td) => td.textContent)));
  const fertig = () => page.waitForFunction(() => !document.querySelector('#aw-ergebnis.laedt') && (document.querySelector('#aw-tabelle') || document.querySelector('#aw-leer')));

  await page.goto(umgebung.link);
  await page.getByText('Passkey registrieren', { exact: true }).first().waitFor();
  await page.locator('#registrierung-bezeichnung').fill('Virtueller Passkey');
  await page.getByRole('button', { name: 'Passkey registrieren' }).click();
  await page.getByText('Passkey registriert. Jetzt anmelden.').waitFor();
  await page.locator('#anmelden').click();
  await page.getByRole('navigation').getByText('Konten', { exact: true }).waitFor();

  // Bestand über die REST-Schnittstelle: Geschäftsjahr, Buchungsart und vier festgeschriebene Buchungen, ein Entwurf
  const jahr = new Date().getFullYear();
  const api = (m, p, j) => page.evaluate(async ([m2, p2, j2]) => {
    const r = await fetch(`/m/browserpraxis/b/haupt/api/v1${p2}`, { method: m2, credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'Idempotency-Key': crypto.randomUUID() }, body: j2 === undefined ? undefined : JSON.stringify(j2) });
    return [r.status, r.status === 204 ? null : await r.json()];
  }, [m, p, j]);
  const ok = async (m, p, j) => { const [s, r] = await api(m, p, j); if (s >= 300) throw new Error(`${m} ${p}: ${s} ${JSON.stringify(r)}`); return r; };
  await ok('POST', '/konten', { nr: '4210', bezeichnung: 'Miete', kontoart: 'aufwand' });
  await ok('POST', '/geschaeftsjahre', { kennung: String(jahr), beginn: `${jahr}-01-01`, ende: `${jahr}-12-31` });
  await ok('POST', '/nummernkreise', { kuerzel: 'ALLG', bezeichnung: 'Allgemein' });
  await ok('PUT', `/nummernkreise/ALLG/jahre/${jahr}`, { vorlage: 'ALLG-{JJJJ}-{NR:5}', zuruecksetzen: true });
  await ok('POST', '/buchungsarten', { kuerzel: 'BANK', bezeichnung: 'Bank', nummernkreis: 'ALLG' });
  const buchen = async (tag, text, soll, haben, betrag, fest = true) => {
    const b = await ok('POST', '/buchungen', { buchungsart: 'BANK', belegdatum: tag, buchungsdatum: tag, text,
      zeilen: [{ konto: soll, seite: 'S', betrag }, { konto: haben, seite: 'H', betrag }], vorbereiten: fest });
    if (fest) await ok('POST', '/freigaben', { buchungen: [b.id] });
  };
  await buchen(`${jahr}-01-10`, 'Honorar Muster', '1200', '8100', '500.00');
  await buchen(`${jahr}-01-15`, 'Miete Januar (Muster)', '4210', '1200', '300.00');
  await buchen(`${jahr}-02-03`, 'Bürobedarf (Muster)', '4930', '1000', '40.00');
  await buchen(`${jahr}-02-20`, 'Honorar Muster 2', '1200', '8100', '250.00');
  await buchen(`${jahr}-02-25`, 'Entwurf Papier (Muster)', '4930', '1200', '10.00', false);

  // Seite öffnen: Summen- und Saldenliste des laufenden Geschäftsjahres
  await page.reload();
  await page.getByRole('navigation').getByText('Auswertungen', { exact: true }).last().click();
  await fertig();
  ist((await page.locator('#aw-titel').textContent()) === 'Summen- und Saldenliste', 'Start mit der Summen- und Saldenliste');
  let z = await zeilen();
  const zeile = (teil) => z.find((r) => r.some((c) => c === teil));
  ist(JSON.stringify(zeile('1200')) === JSON.stringify(['1200', 'Bank', '0,00', '750,00', '300,00', '750,00', '300,00', '450,00 S']), `Bank: ${JSON.stringify(zeile('1200'))}`);
  ist(zeile('8100')[7] === '750,00 H' && zeile('Summe Aufwand')[7] === '340,00 S', 'Ertrag im Haben, Summe Aufwand');
  ist(JSON.stringify(zeile('Gesamt').slice(2)) === JSON.stringify(['0,00', '1.090,00', '1.090,00', '1.090,00', '1.090,00', '0,00']), `Gesamt: ${JSON.stringify(zeile('Gesamt'))}`);
  ist((await page.locator('#aw-pruefung').textContent()) === 'Soll und Haben stimmen überein.', 'Kontrolle der doppelten Buchführung');
  ist((await page.locator('#aw-stand').textContent()).includes(`Stand: bis Buchung ALLG-${jahr}-00004`) && (await page.locator('#aw-stand').textContent()).includes('1 Entwurf zählt nicht mit'), 'Stand der Auswertung und Hinweis auf den Entwurf');
  ist(!z.some((r) => r[0] === '1400'), 'Konto ohne Bewegung fehlt');
  ist(await gesperrt('aw-speichern', 'Nur eine eigene Auswertung') && await gesperrt('aw-loeschen', 'Nur eine eigene Auswertung'), 'Speichern und Löschen gesperrt mit Grund');
  await bild('1-susa');

  // Entwürfe mitrechnen: vorläufig
  await page.locator('#aw-entwuerfe').check();
  await page.locator('#aw-vorlaeufig').waitFor();
  z = await zeilen();
  ist(zeile('4930')[7] === '50,00 S', 'mit Entwürfen: Bürobedarf 50,00');
  await page.locator('#aw-entwuerfe').uncheck();
  await page.locator('#aw-vorlaeufig').waitFor({ state: 'detached' });

  // Zeitraum von Hand: nur Februar – Vortrag aus dem Januar
  await page.locator('#aw-von').fill(`01.02.${jahr}`);
  await page.locator('#aw-von').dispatchEvent('change');
  await page.locator('#aw-bis').fill(`28.02.${jahr}`);
  await page.locator('#aw-bis').dispatchEvent('change');
  await page.waitForFunction((t) => document.querySelector('#aw-zeit')?.textContent.startsWith(t), `01.02.${jahr}`);
  await fertig();
  z = await zeilen();
  ist(JSON.stringify(zeile('1200').slice(2)) === JSON.stringify(['200,00 S', '250,00', '0,00', '750,00', '300,00', '450,00 S']), `Februar, Bank: ${JSON.stringify(zeile('1200'))}`);
  ist(zeile('4210')[2] === '300,00 S' && zeile('4210')[3] === '0,00', 'Erfolgskonto: Vortrag seit Jahresbeginn');
  ist((await page.locator('#aw-zeitraum').inputValue()) === 'frei', 'der Zeitraum steht auf „Von – bis“');

  // Journal und Kontoblätter
  await page.locator('#aw-wahl').selectOption({ label: 'Journal' });
  await page.locator('#aw-dialog-abbrechen, .dialog button').first().waitFor({ timeout: 1500 }).then(() => page.getByRole('button', { name: 'Wechseln' }).click()).catch(() => {});
  await page.waitForFunction(() => document.querySelector('#aw-titel')?.textContent === 'Journal');
  await fertig();
  z = await zeilen();
  ist((await page.locator('#aw-anzahl').textContent()) === '4 Buchungen' && z[0][0] === `ALLG-${jahr}-00001` && z[0][5] === '1200' && z[0][6] === '500,00' && z[1][5] === '8100' && z[1][7] === '500,00', `Journal: ${JSON.stringify(z.slice(0, 2))}`);
  ist(JSON.stringify(z[z.length - 1].slice(4)) === JSON.stringify(['Gesamt', '', '1.090,00', '1.090,00']), 'Journal: Summe Soll = Summe Haben');
  await bild('2-journal');
  await page.locator('#aw-wahl').selectOption({ label: 'Kontoblätter' });
  await page.waitForFunction(() => document.querySelector('#aw-titel')?.textContent === 'Kontoblätter');
  await fertig();
  z = await zeilen();
  const bank = z.findIndex((r) => r[0] === '1200 Bank');
  ist(bank >= 0 && z[bank + 1][7] === '500,00 S' && z[bank + 2][4] === '4210' && z[bank + 2][7] === '200,00 S' && z[bank + 3][7] === '450,00 S' && z[bank + 4][7] === '450,00 S', `Kontoblatt Bank mit laufendem Saldo: ${JSON.stringify(z.slice(bank, bank + 5))}`);
  ist((await page.locator('#aw-anzahl').textContent()) === '5 Kontoblätter', 'fünf Kontoblätter');
  await bild('3-kontoblaetter');

  // Eigene Auswertung zusammenstellen: zwei Zeilen und eine Summe, drei Spalten
  await page.locator('#aw-wahl').selectOption({ label: 'Summen- und Saldenliste' });
  await page.waitForFunction(() => document.querySelector('#aw-titel')?.textContent === 'Summen- und Saldenliste');
  await fertig();
  await page.locator('#aw-anpassen').click();
  await page.locator('#aw-dialog').waitFor();
  ist(await page.locator('#aw-f-zeile-neu').isDisabled() && (await page.locator('#aw-f-zeile-neu').getAttribute('title')).includes('eigene Zeilen'), 'eigene Zeilen gesperrt, bis die Gliederung gewählt ist – mit Grund');
  await page.locator('#aw-f-gliederung').selectOption('eigene');
  await page.locator('#aw-f-zeile-neu').click();
  await page.locator('#aw-f-zeilen tr').nth(0).locator('input').nth(0).fill('Raumkosten');
  await page.locator('#aw-f-zeilen tr').nth(0).locator('input').nth(1).fill('4200-4299');
  await page.locator('#aw-f-zeile-neu').click();
  await page.locator('#aw-f-zeilen tr').nth(1).locator('input').nth(0).fill('Büro');
  await page.locator('#aw-f-zeilen tr').nth(1).locator('input').nth(1).fill('49*');
  await page.locator('#aw-f-summe-neu').click();
  await page.locator('#aw-f-zeilen tr').nth(2).locator('input').nth(0).fill('Kosten gesamt');
  for (const s of ['vortrag', 'soll_jahr', 'haben_jahr']) await page.locator(`#aw-f-teil-spalten input[value="${s}"]`).uncheck();
  await bild('4-baukasten');
  await page.locator('#aw-dialog-senden').click();
  await page.locator('#aw-geaendert').waitFor();
  await fertig();
  z = await zeilen();
  ist(JSON.stringify(z.map((r) => [r[0], r[1], r[4]])) === JSON.stringify([['', 'Raumkosten', '300,00 S'], ['4210', 'Miete', '300,00 S'], ['', 'Büro', '40,00 S'], ['4930', 'Bürobedarf', '40,00 S'], ['', 'Kosten gesamt', '340,00 S'], ['', 'Gesamt', '340,00 S']]),
    `eigene Gliederung: ${JSON.stringify(z)}`);
  ist((await page.locator('#aw-ergebnis .liste-fuss').textContent()).includes('3 bewegte Konten stehen in keiner Zeile: 1000, 1200, 8100'), 'was in keiner Zeile steht, wird genannt');
  await bild('5-eigene');

  // Speichern unter, neu laden: die eigene Auswertung ist wieder da
  await page.locator('#aw-speichern-unter').click();
  await page.locator('#aw-f-name').fill('Kostenübersicht');
  await page.locator('#aw-f-teilen').check();
  await page.locator('#aw-dialog-name-senden').click();
  await meldung('„Kostenübersicht“ gespeichert.');
  ist((await page.locator('#aw-titel').textContent()) === 'Kostenübersicht' && await page.locator('#aw-geaendert').count() === 0, 'gespeichert und gewählt');
  ist(await gesperrt('aw-speichern', 'Nichts geändert') && !(await page.locator('#aw-loeschen').isDisabled()), 'Speichern gesperrt, solange nichts geändert ist; Löschen frei');
  await page.reload();
  await fertig();
  ist((await page.locator('#aw-titel').textContent()) === 'Kostenübersicht' && (await page.locator('#aw-wahl optgroup[label="Meine Auswertungen"] option').allTextContents()).join() === 'Kostenübersicht', 'nach dem Neuladen steht die zuletzt gewählte eigene Auswertung da');
  z = await zeilen();
  ist(z.length === 6 && z[4][1] === 'Kosten gesamt', 'und rechnet wie vorher');

  // CSV: dieselben Zeilen, Semikolon, Dezimalkomma, BOM
  const [laden] = await Promise.all([page.waitForEvent('download'), page.locator('#aw-csv').click()]);
  const csv = readFileSync(await laden.path(), 'utf8');
  ist(laden.suggestedFilename().startsWith('Kostenuebersicht ') && laden.suggestedFilename().endsWith('.csv'), `Dateiname: ${laden.suggestedFilename()}`);
  ist(csv.charCodeAt(0) === 0xfeff && csv.includes('"Raumkosten";"0,00";"300,00";"300,00"') === false && csv.includes('"";"Raumkosten";"300,00";"0,00";"300,00"') && csv.includes('"4210";"Miete";"300,00";"0,00";"300,00"') && csv.includes('\r\n'), `CSV: ${csv.slice(0, 600)}`);
  ist(csv.includes('"Kostenübersicht"') && csv.includes('Stand: bis Buchung'), 'CSV nennt Auswertung und Stand');

  // Druckansicht: Kopf, Menü und Knöpfe sind weg, die Tabelle bleibt
  await page.emulateMedia({ media: 'print' });
  ist(!(await page.locator('.navigation').isVisible()) && !(await page.locator('#aw-anpassen').isVisible()) && await page.locator('#aw-tabelle').isVisible() && await page.locator('#aw-stand').isVisible(), 'Druck: nur Stand und Tabelle');
  await bild('6-druck');
  await page.emulateMedia({ media: 'screen' });

  // Löschen
  await page.locator('#aw-loeschen').click();
  await page.getByRole('button', { name: 'Löschen', exact: true }).click();
  await meldung('Gelöscht.');
  await page.waitForFunction(() => document.querySelector('#aw-titel')?.textContent === 'Summen- und Saldenliste');
  ist(await page.locator('#aw-wahl optgroup[label="Meine Auswertungen"]').count() === 0, 'eigene Auswertung gelöscht');
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
