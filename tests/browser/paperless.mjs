// SPDX-License-Identifier: AGPL-3.0-or-later
// Browsertest des Moduls paperless (PAPERLESS.md, F92) gegen den nachgebauten Paperless-Server: Zugang des Nutzers,
// Entsperren, Tags, Arbeitsvorrat aus Paperless, geprüfter Beleg und Vorschau in Buchungsmaske und Buchungsliste.
//
//   … node tests/browser/paperless.mjs [Bildverzeichnis]   (Umgebung wie tests/browser/buchen.mjs)
import { spawn, execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { mkdirSync, rmSync, readFileSync } from 'node:fs';
import path from 'node:path';
import os from 'node:os';
import { fileURLToPath } from 'node:url';

const require = createRequire(import.meta.url);
const { chromium } = require(process.env.PLAYWRIGHT || 'playwright');
const wurzel = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const PORT = 8094;
const PL_PORT = 8095;
const PL = `http://localhost:${PL_PORT}`;
const ADRESSE = `http://localhost:${PORT}`;
const bilder = process.argv[2] || null;
if (bilder) mkdirSync(bilder, { recursive: true });

let pruefungen = 0;
function ist(b, was) { if (!b) throw new Error(`fehlgeschlagen: ${was}`); pruefungen++; }

const umgebung = JSON.parse(execFileSync('php', [path.join(wurzel, 'tests/browser/aufbau.php'), ADRESSE, '--buchen', '--vorrat', '--paperless'], { encoding: 'utf8' }).trim().split('\n').pop());
const server = spawn('php', ['-S', `localhost:${PORT}`, path.join(wurzel, 'werkzeuge/entwicklungsserver.php')], {
  cwd: wurzel, env: { ...process.env, KUMERO_KONFIG: umgebung.konfig, KUMERO_ENTWICKLUNG: '1' }, stdio: ['ignore', 'ignore', 'pipe'],
});
const zustand = path.join(os.tmpdir(), `paperless_nachbau_browser_${process.pid}.json`);
const nachbau = spawn('php', ['-S', `localhost:${PL_PORT}`, path.join(wurzel, 'tests/Hilfe/paperless_nachbau.php')], {
  env: { ...process.env, PAPERLESS_NACHBAU_ZUSTAND: zustand }, stdio: 'ignore',
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



  await nav('Buchen einrichten');
  await page.locator('#schnell-knopf').click();
  await page.locator('#einrichtung-bereit').waitFor();

  // Der Nutzer verbindet sein Paperless: Token wird geprüft, verschlüsselt gespeichert, gleich entsperrt
  await nav('Paperless – mein Zugang');
  await page.locator('#pl-adresse').fill(PL);
  await page.locator('#pl-bezeichnung').fill('Praxisarchiv');
  await page.locator('#pl-token').fill('tok_muster_0815');
  await page.locator('#pl-tag').fill('zu buchen');
  await page.locator('#pl-neu-passphrase').fill('eine lange Muster-Passphrase');
  await bild('1-zugang-neu');
  await page.locator('#pl-anlegen').click();
  await meldung('Verbunden und entsperrt.');
  await page.locator('.pl-verbindung .marke-klein').filter({ hasText: 'entsperrt' }).waitFor();
  await page.locator('[data-pl=testen]').click();
  await page.locator('.pl-status').filter({ hasText: 'Erreichbar · Paperless 3.4.2 · API-Version 10 · Konto erika.muster' }).waitFor();
  ist(true, 'Verbindungstest nennt Version und Konto');
  await page.locator('[data-pl=tags]').click();
  await page.locator('.pl-tagliste label').filter({ hasText: 'Kostenstelle Empfang' }).locator('input').check();
  ist(await page.locator('.pl-tagliste label').filter({ hasText: 'zu buchen' }).locator('input').isDisabled(), 'Arbeits-Tag ist nicht wählbar');
  await page.locator('[data-pl=tags-uebernehmen]').click();
  await meldung('1 Tag(s) angelegt.');
  await bild('2-zugang-tags');

  // Sperren und mit der Passphrase wieder entsperren
  await page.locator('#pl-sperren').click();
  await meldung('Paperless ist gesperrt.');
  await page.locator('#pl-passphrase').fill('falsch falsch falsch');
  await page.locator('#pl-entsperren').click();
  await meldung('Die Passphrase passt zu keiner deiner Verbindungen');
  await page.locator('#pl-passphrase').fill('eine lange Muster-Passphrase');
  await page.locator('#pl-entsperren').click();
  await meldung('Paperless ist entsperrt.');

  // Arbeitsvorrat: Belege mit dem Tag für offene Belege
  await nav('Arbeitsvorrat');
  await page.locator('#vorratsliste tr[data-id]').nth(1).waitFor();
  ist(await page.locator('#vorratsliste tr[data-id]').count() === 2, 'zwei offene Belege aus Paperless');
  ist((await page.locator('#vorratsliste tr[data-id]').first().innerText()).includes('Paperless'), 'Quelle Paperless in der Liste');
  await bild('3-vorrat-paperless');
  await page.locator('#vorratsliste tr[data-id]').first().click();
  await page.keyboard.press('Enter');

  // Buchungsmaske: Beleg geprüft (MD5 und SHA-256 des Originals), Vorschau, Tag aus Paperless
  await page.locator('#vorrat-angaben').filter({ hasText: 'Rechnung Musterlieferant RE-0815' }).waitFor();
  await page.locator('#beleg-marke').filter({ hasText: 'geprüft' }).waitFor();
  ist(await page.locator('#beleg-marke').innerText() === 'geprüft', 'Beleg aus Paperless ist geprüft');
  ist(await page.locator('#beleg').inputValue() === `${PL}/documents/5210/`, 'Beleglink in der einheitlichen Schreibweise');
  ist(await page.locator('#text').inputValue() === 'Musterlieferant GmbH', 'Buchungstext aus dem Korrespondenten');
  await page.locator('#beleg-sehen').click();
  await page.waitForFunction(() => { const i = document.querySelector('#beleg-dialog img'); return i && i.complete && i.naturalWidth > 0; });
  ist(true, 'Vorschaubild über kumero geladen');
  await bild('4-maske-vorschau');
  await page.locator('#beleg-schliessen').click();
  await page.locator('.tagwahl summary').click();
  ist(await page.locator('.tagliste label').filter({ hasText: 'Kostenstelle Empfang' }).count() === 1, 'Tag aus Paperless steht in der Maske zur Wahl');
  await page.locator('.tagwahl summary').click();
  await page.locator('#konto').fill('4930');
  await page.locator('#gegenkonto').fill('1200');
  await page.locator('#betrag').fill('109,43');
  await page.locator('#betrag').press('Tab');
  await page.locator('#buchen').click();
  await page.locator('#vorrat-angaben').filter({ hasText: 'Kassenbon Musterbäcker' }).waitFor();
  await meldung('Gebucht als Nr.');

  // Buchungsliste: Vorschau am Beleg; feste Ansicht: Beleg geprüft
  await nav('Buchungsliste');
  await page.locator('#ansicht').selectOption('journal');
  await page.locator('#buchungsliste tr[data-id]').first().waitFor();
  await page.locator('#buchungsliste .beleg-auge').first().click();
  await page.waitForFunction(() => { const i = document.querySelector('#beleg-dialog img'); return i && i.complete && i.naturalWidth > 0; });
  ist(true, 'Vorschau aus der Buchungsliste');
  await page.locator('#beleg-schliessen').click();
  await page.locator('#buchungsliste tr[data-id]').first().click();
  await page.keyboard.press('Enter');
  await page.locator('#fest-belege').filter({ hasText: 'geprüft' }).waitFor();
  ist(!(await page.locator('#fest-belege').innerText()).includes('ungeprüft'), 'festgeschriebener Beleg ist geprüft');
  await bild('5-buchung-beleg');

  // Der gebuchte Beleg hat in Paperless sein Tag verloren, der andere liegt noch im Vorrat
  await nav('Arbeitsvorrat');
  await page.locator('#vorratsliste tr[data-id]').first().waitFor();
  ist(await page.locator('#vorratsliste tr[data-id]').count() === 1, 'ein Beleg bleibt offen');
  const tags = JSON.parse(readFileSync(zustand, 'utf8')).documents[5210].tags;
  ist(JSON.stringify(tags) === '[2]', `Tag für offene Belege in Paperless entfernt: ${JSON.stringify(tags)}`);
} finally {
  await browser.close();
  server.kill();
  nachbau.kill();
  rmSync(zustand, { force: true });
  execFileSync('php', [path.join(wurzel, 'tests/browser/aufbau.php'), '--abbau']);
}
// die absichtlich falsche Passphrase (403) gehört zum Test
const echteFehler = fehler.filter((f) => !/status of (401|403|404)/.test(f) && !/HTTP 403 .*\/paperless\/entsperren/.test(f));
if (echteFehler.length) {
  console.error(echteFehler.join('\n'));
  console.error(serverLog.split('\n').filter((z) => /error|warn|kumero\[/i.test(z)).slice(-20).join('\n'));
  process.exit(1);
}
console.log(`${pruefungen} Prüfungen im Browser, alle grün`);
