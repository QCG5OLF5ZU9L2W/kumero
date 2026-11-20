// SPDX-License-Identifier: AGPL-3.0-or-later
// Browsertest der Seite „Mandanten“ (VERWALTUNG.md Abschnitt 14, F118): Die System-Verwalterin legt einen Mandanten
// mit seinem ersten Administrator an, legt ihn still, exportiert und löscht ihn. Die Seite schreibt Aufträge; den
// Dienst ersetzt hier der Aufruf von `bin/kumero system:auftraege`. Nur synthetische Daten.
//
//   KUMERO_TEST_PGHOST=/tmp/pgtest KUMERO_TEST_PGUSER=postgres \
//   PLAYWRIGHT=/opt/npm-tools/node_modules/playwright CHROME=/usr/bin/google-chrome node tests/browser/mandanten.mjs [Bildverzeichnis]
import { spawn, execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { mkdirSync, mkdtempSync, rmSync, existsSync } from 'node:fs';
import path from 'node:path';
import os from 'node:os';
import { fileURLToPath } from 'node:url';

const require = createRequire(import.meta.url);
const { chromium } = require(process.env.PLAYWRIGHT || 'playwright');
const wurzel = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const PORT = 8092;
const ADRESSE = `http://localhost:${PORT}`;
const bilder = process.argv[2] || null;
if (bilder) mkdirSync(bilder, { recursive: true });

let pruefungen = 0;
function ist(b, was) { if (!b) throw new Error(`fehlgeschlagen: ${was}`); pruefungen++; }

const psql = (sql) => execFileSync('psql', ['-X', '-q', '-h', process.env.KUMERO_TEST_PGHOST, '-U', process.env.KUMERO_TEST_PGUSER || 'postgres', '-d', 'postgres', '-c', sql]);
psql('DROP DATABASE IF EXISTS kumero_browserneu');   // Rest eines abgebrochenen Laufs
const umgebung = JSON.parse(execFileSync('php', [path.join(wurzel, 'tests/browser/aufbau.php'), ADRESSE], { encoding: 'utf8' }).trim().split('\n').pop());
const exporte = mkdtempSync(path.join(os.tmpdir(), 'kumero-exporte-'));
// der Dienst: führt die offenen Aufträge als kumero_owner aus
const dienst = () => execFileSync('php', [path.join(wurzel, 'bin/kumero'), 'system:auftraege'], { encoding: 'utf8', env: { ...process.env,
  KUMERO_KONFIG: umgebung.konfig, KUMERO_VORLAGE_DB: 'kumero_test_php_vorlage', KUMERO_DB_NUTZER: 'kumero_owner', KUMERO_EXPORT_VERZEICHNIS: exporte } });
const server = spawn('php', ['-S', `localhost:${PORT}`, path.join(wurzel, 'werkzeuge/entwicklungsserver.php')], {
  cwd: wurzel, env: { ...process.env, KUMERO_KONFIG: umgebung.konfig, KUMERO_ENTWICKLUNG: '1' }, stdio: ['ignore', 'ignore', 'pipe'],
});
let serverLog = '';
server.stderr.on('data', (d) => { serverLog += d; });
await new Promise((r) => setTimeout(r, 700));

const browser = await chromium.launch({ executablePath: process.env.CHROME || undefined, headless: true });
const fehler = [];
try {
  const context = await browser.newContext({ viewport: { width: 1400, height: 1000 }, locale: 'de-DE' });
  await context.grantPermissions(['clipboard-read', 'clipboard-write'], { origin: ADRESSE });
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
  const zeile = (kennung) => page.locator(`#mandanten-liste tr[data-kennung="${kennung}"]`);
  const zustand = (kennung, z) => page.locator(`#mandanten-liste tr[data-kennung="${kennung}"] [data-zustand="${z}"]`).waitFor();

  // System-Verwalterin: Passkey einrichten, anmelden
  await page.goto(umgebung.verwalter);
  await page.getByText('Passkey registrieren', { exact: true }).first().waitFor();
  await page.locator('#registrierung-bezeichnung').fill('Virtueller Passkey');
  await page.getByRole('button', { name: 'Passkey registrieren' }).click();
  await page.getByText('Passkey registriert. Jetzt anmelden.').waitFor();
  await page.locator('#anmelden').click();
  await page.getByRole('navigation').getByText('Mandanten', { exact: true }).waitFor();
  ist(true, 'Menüpunkt „Mandanten“ im Bereich Verwaltung');
  await page.getByRole('navigation').getByText('Mandanten', { exact: true }).click();
  await zeile('browserpraxis').waitFor();
  ist(await page.locator('#mandanten-liste tbody tr').count() === 1, 'die Liste zeigt den vorhandenen Mandanten');

  // ohne Auswahl sind die Aktionen gesperrt und nennen den Grund (F106)
  for (const id of ['mandant-stilllegen', 'mandant-reaktivieren', 'mandant-exportieren', 'mandant-loeschen']) {
    ist(await page.locator(`#${id}`).isDisabled() && (await page.locator(`#${id}`).getAttribute('title')).includes('Mandanten in der Liste wählen'), `${id} gesperrt mit Grund`);
  }
  ist(await page.locator('#zugang-kopieren').isDisabled(), 'kein Link, nichts zu kopieren');
  await bild('1-mandanten');

  // anlegen: Mandant und erster Administrator in einem Vorgang (U5)
  await page.locator('#neu-kennung').fill('browserneu');
  await page.locator('#neu-name').fill('Neue Musterpraxis');
  await page.locator('#neu-vornamen').fill('Anna');
  await page.locator('#neu-familienname').fill('Admin');
  await page.locator('#neu-email').fill('anna@browserneu.example.org');
  await page.locator('#mandant-anlegen-knopf').click();
  await meldung('Mandant wird angelegt.');
  const link = await page.locator('#zugang-link').inputValue();
  ist(new RegExp(`^${ADRESSE}/app\\.html#registrierung=browserneu\\.[A-Za-z0-9_-]{43}$`).test(link), `der Zugang des Administrators wird einmal gezeigt: ${link.slice(0, 60)}…`);
  ist((await page.locator('#zugang-hinweis').innerText()).includes('zeigt ihn nicht wieder'), 'mit dem Hinweis, dass kumero ihn nicht wieder zeigt');
  await page.locator('#zugang-kopieren').click();
  ist(await page.evaluate(() => navigator.clipboard.readText()) === link, 'Kopieren legt den Link in die Zwischenablage');
  await zustand('browserneu', 'mandant_anlegen');
  ist((await page.locator('#mandanten-stand').innerText()).includes('1 Auftrag in Arbeit'), 'der Kopf nennt den laufenden Auftrag');
  ist(await page.locator('#neu-kennung').inputValue() === '', 'das Formular ist wieder leer');
  await bild('2-wird-angelegt');
  ist(dienst().includes('mandant_anlegen browserneu: erledigt'), 'der Dienst legt den Mandanten an');
  await zustand('browserneu', 'betrieb');      // die Seite sieht selbst nach
  ist(true, 'die Liste zeigt den neuen Mandanten in Betrieb, ohne neu zu laden');

  // wählen; löschen geht erst nach Stilllegen und Export
  await zeile('browserneu').click();
  ist(!(await page.locator('#mandant-stilllegen').isDisabled()) && await page.locator('#mandant-reaktivieren').isDisabled(), 'in Betrieb: stilllegen frei, reaktivieren gesperrt');
  ist((await page.locator('#mandant-loeschen').getAttribute('title')).includes('Erst stilllegen'), 'Löschen nennt den Grund: erst stilllegen');
  await page.locator('#mandant-stilllegen').click();
  await meldung('Mandant stillgelegt.');
  await zustand('browserneu', 'stillgelegt');
  ist((await page.locator('#mandant-loeschen').getAttribute('title')).includes('Erst exportieren'), 'Löschen nennt den Grund: erst exportieren');
  await page.locator('#mandant-exportieren').click();
  await meldung('Export beauftragt.');
  ist(dienst().includes('mandant_exportieren browserneu: erledigt'), 'der Dienst sichert den Mandanten');
  await zeile('browserneu').filter({ hasText: `${exporte}/browserneu-` }).waitFor();
  ist(true, 'die Liste nennt die Sicherung auf dem Server');
  ist(await page.locator('a[download], a[href*="export"]').count() === 0, 'die Sicherung wird nicht zum Herunterladen angeboten (F43)');
  await page.waitForFunction(() => !document.getElementById('mandant-loeschen').disabled);
  await bild('3-exportiert');

  // löschen: Kennung eintippen
  await page.locator('#mandant-loeschen').click();
  await page.locator('#loeschen-dialog').waitFor();
  ist(await page.locator('#loeschen-bestaetigen').isDisabled(), 'erst mit eingetippter Kennung');
  await page.locator('#loeschen-kennung').fill('browserpraxis');
  ist(await page.locator('#loeschen-bestaetigen').isDisabled(), 'eine andere Kennung genügt nicht');
  await page.locator('#loeschen-kennung').fill('browserneu');
  await bild('4-loeschen');
  await page.locator('#loeschen-bestaetigen').click();
  await meldung('Löschen beauftragt.');
  ist(dienst().includes('mandant_loeschen browserneu: erledigt'), 'der Dienst löscht den Mandanten');
  await zeile('browserneu').waitFor({ state: 'detached' });
  ist(await page.locator('#mandanten-liste tbody tr').count() === 1, 'der Mandant steht nicht mehr in der Liste');
  ist(await page.locator('#auftrags-liste tbody tr').count() === 3 && await page.locator('#auftrags-liste [data-zustand="erledigt"]').count() === 3, 'drei erledigte Aufträge');
  ist(await page.locator('#mandant-loeschen').isDisabled(), 'ohne Auswahl wieder gesperrt');
  await bild('5-geloescht');
} finally {
  await browser.close();
  server.kill();
  try { psql('DROP DATABASE IF EXISTS kumero_browserneu'); } catch { /* läuft der Abbau trotzdem */ }
  execFileSync('php', [path.join(wurzel, 'tests/browser/aufbau.php'), '--abbau']);
  if (existsSync(exporte)) rmSync(exporte, { recursive: true, force: true });
}
const echteFehler = fehler.filter((f) => !/status of (401|404)/.test(f));
if (echteFehler.length) {
  console.error(echteFehler.join('\n'));
  console.error(serverLog.split('\n').filter((z) => /error|warn|kumero\[/i.test(z)).slice(-20).join('\n'));
  process.exit(1);
}
console.log(`${pruefungen} Prüfungen im Browser, alle grün`);
