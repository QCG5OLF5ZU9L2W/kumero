// SPDX-License-Identifier: AGPL-3.0-or-later
// Browsertest der Seite „Siegel“ (SIEGEL.md Abschnitt 7; F119, F120): Siegel anfordern, Siegelinhalt herunterladen,
// mit gpg außerhalb signieren, öffentlichen Schlüssel anmelden, Signatur hochladen. Der Schlüssel entsteht hier in
// einem Wegwerfverzeichnis; nichts davon ist echt.
//
//   KUMERO_TEST_PGHOST=/tmp/pgtest KUMERO_TEST_PGUSER=postgres \
//   PLAYWRIGHT=/opt/npm-tools/node_modules/playwright CHROME=/usr/bin/google-chrome node tests/browser/siegel.mjs [Bildverzeichnis]
import { spawn, execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { mkdirSync, mkdtempSync, rmSync, readFileSync, writeFileSync, chmodSync } from 'node:fs';
import path from 'node:path';
import os from 'node:os';
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

const umgebung = JSON.parse(execFileSync('php', [path.join(wurzel, 'tests/browser/aufbau.php'), ADRESSE, '--siegel'], { encoding: 'utf8' }).trim().split('\n').pop());
const arbeit = mkdtempSync(path.join(os.tmpdir(), 'kumero-siegel-browser-'));
const heim = path.join(arbeit, 'gnupg');
mkdirSync(heim);
chmodSync(heim, 0o700);
const gpg = (...a) => execFileSync('gpg', ['--homedir', heim, '--batch', '--no-tty', ...a], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] });
gpg('--passphrase', '', '--quick-generate-key', 'Erika Muster <erika@example.org>', 'ed25519', 'sign', 'never');
writeFileSync(path.join(arbeit, 'oeffentlich.asc'), gpg('--armor', '--export'));
const geheim = gpg('--armor', '--export-secret-keys');

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

  // Erika (Verantwortliche): Passkey einrichten, anmelden, Seite öffnen
  await page.goto(umgebung.link);
  await page.getByText('Passkey registrieren', { exact: true }).first().waitFor();
  await page.locator('#registrierung-bezeichnung').fill('Virtueller Passkey');
  await page.getByRole('button', { name: 'Passkey registrieren' }).click();
  await page.getByText('Passkey registriert. Jetzt anmelden.').waitFor();
  await page.locator('#anmelden').click();
  await page.getByRole('navigation').getByText('Siegel', { exact: true }).click();
  await page.locator('#sg-liste tr.leer').waitFor();
  ist(true, 'Menüpunkt „Siegel“ im Bereich Buchführung, Liste leer');
  ist((await page.locator('#sg-stand-offen').textContent()) === 'noch kein signiertes Siegel', 'Stand: noch kein signiertes Siegel');
  ist((await page.locator('#sg-anf-zustand').textContent()) === 'keine offene Anforderung', 'Karte der Anforderung steht da, leer');
  ist(await gesperrt('sg-inhalt', 'Erst ein Siegel in der Liste wählen'), 'Herunterladen gesperrt mit Grund');
  ist(await gesperrt('sg-signatur', 'Erst ein Siegel in der Liste wählen'), 'Weitere Signatur gesperrt mit Grund');
  ist(await gesperrt('sg-anf-inhalt', 'Erst ein Siegel anfordern') && await gesperrt('sg-anf-festschreiben', 'Erst ein Siegel anfordern')
    && await gesperrt('sg-anf-verwerfen', 'Erst ein Siegel anfordern'), 'Knöpfe der Anforderung gesperrt mit Grund');
  ist(await gesperrt('sg-zurueckziehen', 'Erst einen Schlüssel in der Liste wählen'), 'Zurückziehen gesperrt mit Grund');
  ist(!(await page.locator('#sg-anfordern').isDisabled()) && !(await page.locator('#sg-schluessel-neu').isDisabled()), 'Anfordern und Schlüssel anmelden sind frei');
  await bild('1-leer');

  // anfordern: vorläufig – die Liste bleibt leer; verwerfen und neu anfordern gibt wieder Nr. 1
  await page.locator('#sg-anfordern').click();
  await meldung('Siegel Nr. 1 angefordert – vorläufig.');
  await page.locator('#sg-anforderung [data-zustand="wartet"]').waitFor();
  ist(await page.locator('#sg-liste tr.leer').count() === 1, 'angefordert ist noch kein Siegel');
  ist(await gesperrt('sg-anfordern', 'Es gibt schon eine offene Anforderung – erst festschreiben oder verwerfen'), 'keine zweite Anforderung, mit Grund');
  await page.locator('#sg-anf-verwerfen').click();
  await meldung('Anforderung verworfen.');
  await page.locator('#sg-anforderung [data-zustand="keine"]').waitFor();
  await page.locator('#sg-anfordern').click();
  await meldung('Siegel Nr. 1 angefordert – vorläufig.');
  await page.locator('#sg-anforderung [data-zustand="wartet"]').waitFor();
  ist(await gesperrt('sg-anf-signatur', 'Erst einen öffentlichen Schlüssel anmelden'), 'ohne Schlüssel kein Hochladen, mit Grund');
  ist(await gesperrt('sg-anf-festschreiben', 'Erst die Signatur hochladen – ohne geprüfte Signatur wird nichts festgeschrieben'), 'ohne Signatur kein Festschreiben, mit Grund');
  ist((await page.locator('#sg-befehl').textContent()) === 'gpg --armor --detach-sign kumero-siegel-browserpraxis-haupt-0001.json', 'die Seite nennt den Befehl mit dem Dateinamen');

  // Siegelinhalt herunterladen und außerhalb signieren
  const [dl] = await Promise.all([page.waitForEvent('download'), page.locator('#sg-anf-inhalt').click()]);
  ist(dl.suggestedFilename() === 'kumero-siegel-browserpraxis-haupt-0001.json', 'Download heißt nach Mandant und Nummer');
  const datei = path.join(arbeit, dl.suggestedFilename());
  await dl.saveAs(datei);
  const inhalt = JSON.parse(readFileSync(datei, 'utf8'));
  ist(inhalt.art === 'kumero-siegel' && inhalt.nr === 1 && inhalt.mandant === 'browserpraxis' && /^[0-9a-f]{64}$/.test(inhalt.protokoll.hash), 'die Datei ist der Siegelinhalt');
  gpg('--armor', '--detach-sign', datei);

  // privater Schlüssel wird abgewiesen; öffentlicher angemeldet
  await page.locator('#sg-schluessel-neu').click();
  ist(await gesperrt('sg-dialog-schluessel-senden', 'Erst eine Datei wählen oder den Text einfügen'), 'Anmelden gesperrt, solange nichts gewählt ist');
  await page.locator('#sg-dialog-schluessel-text').fill(geheim);
  await page.locator('#sg-dialog-schluessel-senden').click();
  await meldung('Das ist ein privater Schlüssel.');
  ist(await page.locator('#sg-schluessel tr.leer').count() === 1, 'nichts gespeichert');
  await page.locator('#sg-schluessel-neu').click();
  await page.locator('#sg-dialog-schluessel-datei').setInputFiles(path.join(arbeit, 'oeffentlich.asc'));
  await page.locator('#sg-dialog-schluessel-senden:not([disabled])').click();
  await meldung('Schlüssel angemeldet: Erika Muster <erika@example.org>');
  await page.locator('#sg-schluessel [data-zustand="aktiv"]').waitFor();
  ist(await page.locator('#sg-schluessel tbody tr').count() === 1, 'der Schlüssel steht in der Liste');
  await bild('2-schluessel');

  // Signatur hochladen: kumero prüft – festgeschrieben ist damit noch nichts
  ist(!(await page.locator('#sg-anf-signatur').isDisabled()), 'Signatur hochladen ist jetzt frei');
  await page.locator('#sg-anf-signatur').click();
  await page.locator('#sg-dialog-signatur-datei').setInputFiles(`${datei}.asc`);
  await page.locator('#sg-dialog-signatur-senden:not([disabled])').click();
  await meldung('Signatur geprüft. Siegel Nr. 1 ist noch nicht festgeschrieben');
  await page.locator('#sg-anforderung [data-zustand="geprueft"]').waitFor();
  ist(await page.locator('#sg-liste tr.leer').count() === 1, 'geprüft, aber noch kein Siegel in der Liste');
  await bild('3-geprueft');

  // Bestätigung: der Dialog zeigt, was festgeschrieben wird; Abbrechen ändert nichts
  await page.locator('#sg-anf-festschreiben').click();
  ist((await page.locator('#sg-dialog-fest').textContent()).includes('Erika Muster <erika@example.org>'), 'die Bestätigung nennt den Schlüssel');
  await page.locator('#sg-fest-abbrechen').click();
  ist(await page.locator('#sg-liste tr.leer').count() === 1, 'abgebrochen: weiter kein Siegel');
  await page.locator('#sg-anf-festschreiben').click();
  await page.locator('#sg-fest-bestaetigen').click();
  await meldung('Siegel Nr. 1 festgeschrieben.');
  await page.locator('#sg-liste tr.gewaehlt [data-zustand="signiert"]').waitFor();
  await page.locator('#sg-anforderung [data-zustand="keine"]').waitFor();
  ist((await page.locator('#sg-liste .sg-signaturen li').first().textContent()).startsWith('Erika Muster <erika@example.org> · '), 'die Liste nennt, wer signiert hat');
  ist((await page.locator('#sg-stand-offen').textContent()) === 'alle Buchungen gesiegelt', 'Stand: alle Buchungen gesiegelt');
  await bild('4-festgeschrieben');

  // zweite Anforderung: Die Signatur des ersten Siegels passt nicht
  await page.locator('#sg-anfordern').click();
  await meldung('Siegel Nr. 2 angefordert – vorläufig.');
  await page.locator('#sg-anf-signatur').click();
  await page.locator('#sg-dialog-signatur-datei').setInputFiles(`${datei}.asc`);
  await page.locator('#sg-dialog-signatur-senden:not([disabled])').click();
  await meldung('Die Signatur passt nicht zu diesem Siegelinhalt.');
  ist(await page.locator('#sg-anforderung [data-zustand="wartet"]').count() === 1 && await page.locator('#sg-liste tbody tr').count() === 1, 'Anforderung 2 wartet weiter; in der Liste steht nur Siegel 1');

  // Schlüssel zurückziehen
  await page.locator('#sg-schluessel tbody tr').first().click();
  await page.locator('#sg-zurueckziehen').click();
  ist(await gesperrt('sg-zurueck-bestaetigen', 'Erst den Grund nennen'), 'Zurückziehen verlangt einen Grund');
  await page.locator('#sg-grund').fill('Schlüssel ersetzt');
  await page.locator('#sg-zurueck-bestaetigen').click();
  await meldung('Schlüssel zurückgezogen.');
  await page.locator('#sg-schluessel [data-zustand="zurueck"]').waitFor();
  ist(await gesperrt('sg-anf-signatur', 'Erst einen öffentlichen Schlüssel anmelden'), 'ohne aktiven Schlüssel ist das Hochladen wieder gesperrt');
  ist(await page.locator('#sg-liste [data-zustand="signiert"]').count() === 1, 'das festgeschriebene Siegel bleibt');
  await bild('5-zurueckgezogen');
} finally {
  await browser.close();
  server.kill();
  try { execFileSync('gpgconf', ['--homedir', heim, '--kill', 'all'], { stdio: 'ignore' }); } catch { /* kein Agent mehr */ }
  execFileSync('php', [path.join(wurzel, 'tests/browser/aufbau.php'), '--abbau']);
  rmSync(arbeit, { recursive: true, force: true });
}
// die beiden abgewiesenen Eingaben (privater Schlüssel, falsche Signatur) sind gewollt
const echteFehler = fehler.filter((f) => !/status of (401|404|422)/.test(f) && !/^HTTP 422 .*\/siegel\//.test(f));
if (echteFehler.length) {
  console.error(echteFehler.join('\n'));
  console.error(serverLog.split('\n').filter((z) => /error|warn|kumero\[/i.test(z)).slice(-20).join('\n'));
  process.exit(1);
}
console.log(`${pruefungen} Prüfungen im Browser, alle grün`);
