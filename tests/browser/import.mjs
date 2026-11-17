// SPDX-License-Identifier: AGPL-3.0-or-later
// Browsertest der Oberfläche (F80): Passkey registrieren und anmelden (virtueller Authenticator),
// Kontenrahmen-PDF hochladen, korrigieren, als Vorlage speichern, im Mandanten anwenden.
// Prüft nebenbei, dass die strenge CSP nichts blockiert und keine Skriptfehler auftreten.
//
//   KUMERO_AUTOLOAD=… KUMERO_TEST_PGHOST=/tmp/pgtest KUMERO_TEST_PGUSER=postgres \
//   PLAYWRIGHT=/opt/npm-tools/node_modules/playwright CHROME=/usr/bin/google-chrome node tests/browser/import.mjs [Bildverzeichnis]
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

const umgebung = JSON.parse(execFileSync('php', [path.join(wurzel, 'tests/browser/aufbau.php'), ADRESSE], { encoding: 'utf8' }).trim().split('\n').pop());
const server = spawn('php', ['-S', `localhost:${PORT}`, path.join(wurzel, 'werkzeuge/entwicklungsserver.php')], {
  cwd: wurzel, env: { ...process.env, KUMERO_KONFIG: umgebung.konfig, KUMERO_ENTWICKLUNG: '1' }, stdio: ['ignore', 'ignore', 'pipe'],
});
let serverLog = '';
server.stderr.on('data', (d) => { serverLog += d; });
await new Promise((r) => setTimeout(r, 700));

const browser = await chromium.launch({ executablePath: process.env.CHROME || undefined, headless: true });
const fehler = [];
try {
  const context = await browser.newContext({ viewport: { width: 1400, height: 900 }, locale: 'de-DE' });
  const page = await context.newPage();
  page.on('console', (m) => { if (m.type() === 'error') fehler.push(`console: ${m.text()}`); });
  page.on('pageerror', (e) => fehler.push(`pageerror: ${e.message}`));
  page.on('response', (r) => { if (r.status() >= 400 && r.status() !== 401) fehler.push(`HTTP ${r.status()} ${r.url()}`); });
  const cdp = await context.newCDPSession(page);
  await cdp.send('WebAuthn.enable');
  await cdp.send('WebAuthn.addVirtualAuthenticator', { options: {
    protocol: 'ctap2', transport: 'internal', hasResidentKey: true, hasUserVerification: true, isUserVerified: true, automaticPresenceSimulation: true,
  } });
  const bild = async (name) => { if (bilder) await page.screenshot({ path: path.join(bilder, `${name}.png`), fullPage: false }); };

  // Registrieren und anmelden
  await page.goto(umgebung.link);
  await page.getByText('Passkey registrieren', { exact: true }).first().waitFor();
  await page.locator('input').first().fill('Virtueller Passkey');
  await page.getByRole('button', { name: 'Passkey registrieren' }).click();
  await page.getByText('Passkey registriert. Jetzt anmelden.').waitFor();
  ist(true, 'Passkey registriert');
  await page.locator('#anmelden').click();
  await page.getByRole('navigation').getByText('Kontenrahmen importieren').waitFor();
  ist(await page.locator('#mandant-wahl').inputValue() === 'browserpraxis', 'einziger Mandant vorgewählt');
  const bereiche = await page.locator('.navigation .bereich h2').allTextContents();
  ist(JSON.stringify(bereiche) === JSON.stringify(['Start', 'Stammdaten', 'Verwaltung']), `Bereiche im Menü: ${bereiche}`);
  await bild('1-uebersicht');

  // Import
  await page.getByRole('navigation').getByText('Kontenrahmen importieren').click();
  await page.locator('.ablage').waitFor();
  await bild('2-import-datei');
  await page.setInputFiles('#datei', path.join(wurzel, 'tests/fixtures/kontenrahmen/skr81-muster.pdf'));
  await page.locator('.kacheln').waitFor();
  ist(/entwurf=/.test(page.url()), 'Entwurf in der Adresse');
  ist(await page.locator('#vorlage-speichern').isDisabled(), 'Speichern gesperrt bei offenen Punkten');
  ist(await page.locator('.reiter button.aktiv').innerText().then((t) => t.startsWith('Offen')), 'Reiter „Offen“ zuerst');
  await bild('3-pruefbericht-offen');

  // 5100 offen → Kontoart wählen
  await page.getByLabel('Kontoart 5100').selectOption('aufwand');
  await page.getByText('Keine offenen Punkte – die Vorlage kann gespeichert werden.').waitFor();
  ist(!(await page.locator('#vorlage-speichern').isDisabled()), 'Speichern frei');
  // Zu bestätigen: alle bestätigen
  await page.getByRole('tab', { name: /Zu bestätigen/ }).click();
  const alle = page.getByRole('button', { name: /angezeigten bestätigen/ });
  if (await alle.count()) {
    await alle.click();
    await page.getByText('Nichts zu bestätigen.').waitFor();
  }
  await page.getByRole('tab', { name: /Alle Konten/ }).click();
  await bild('4-alle-konten');

  // Speichern
  await page.locator('#vorlage-speichern').click();
  await page.getByText('Vorlage gespeichert', { exact: true }).waitFor();
  await page.getByRole('navigation').getByText('SKR81, 2015, aus PDF').waitFor();
  ist(true, 'Vorlage als Unterpunkt im Menü');
  await bild('5-gespeichert');

  // Anwenden
  await page.getByRole('button', { name: /In „Musterpraxis“ anwenden/ }).click();
  await page.locator('#anwenden').waitFor();
  await page.locator('#anwenden').click();
  await page.locator('dialog').getByRole('button', { name: 'Anwenden' }).click();
  await page.getByText('Angewendet', { exact: true }).waitFor();
  await bild('6-angewendet');
  await page.getByRole('button', { name: 'Zu den Konten' }).click();
  await page.locator('table.liste tbody tr').nth(5).waitFor();
  const n = await page.locator('table.liste tbody tr').count();
  ist(n >= 15, `Konten angelegt (${n})`);
  await bild('7-konten');

  // Abmelden
  await page.getByRole('button', { name: 'Abmelden' }).click();
  await page.locator('#anmelden').waitFor();
  ist(true, 'abgemeldet');
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
