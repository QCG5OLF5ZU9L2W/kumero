// SPDX-License-Identifier: AGPL-3.0-or-later
// Browsertest der Seite „Anlagen“ (ANLAGEN.md, F129): Einstellungen des Buchs, Anlagegut aus einer Buchung anlegen,
// Nutzungsdauer aus der AfA-Tabelle mit Abweichung, AfA-Lauf als Entwurf, Abstimmung zum Journal, Abgang. Alles erfunden.
//
//   KUMERO_TEST_PGHOST=/tmp/pgtest KUMERO_TEST_PGUSER=postgres \
//   PLAYWRIGHT=/opt/npm-tools/node_modules/playwright CHROME=/usr/bin/google-chrome node tests/browser/anlagen.mjs [Bildverzeichnis]
import { spawn, execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { mkdirSync, readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const require = createRequire(import.meta.url);
const { chromium } = require(process.env.PLAYWRIGHT || 'playwright');
const wurzel = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const PORT = 8098;
const ADRESSE = `http://localhost:${PORT}`;
const bilder = process.argv[2] || null;
if (bilder) mkdirSync(bilder, { recursive: true });

let pruefungen = 0;
function ist(b, was) { if (!b) throw new Error(`fehlgeschlagen: ${was}`); pruefungen++; }

const umgebung = JSON.parse(execFileSync('php', [path.join(wurzel, 'tests/browser/aufbau.php'), ADRESSE, '--buchen', '--anlagen'], { encoding: 'utf8' }).trim().split('\n').pop());
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
  const zeilen = () => page.locator('#an-tabelle tbody tr').evaluateAll((trs) => trs.map((tr) => [...tr.children].map((td) => td.textContent)));

  await page.goto(umgebung.link);
  await page.getByText('Passkey registrieren', { exact: true }).first().waitFor();
  await page.locator('#registrierung-bezeichnung').fill('Virtueller Passkey');
  await page.getByRole('button', { name: 'Passkey registrieren' }).click();
  await page.getByText('Passkey registriert. Jetzt anmelden.').waitFor();
  await page.locator('#anmelden').click();
  await page.getByRole('navigation').getByText('Konten', { exact: true }).waitFor();

  const jahr = new Date().getFullYear();
  const api = (m, p, j) => page.evaluate(async ([m2, p2, j2]) => {
    const r = await fetch(`/m/browserpraxis/b/haupt/api/v1${p2}`, { method: m2, credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'Idempotency-Key': crypto.randomUUID(), 'If-Match': '*' }, body: j2 === undefined ? undefined : JSON.stringify(j2) });
    return [r.status, r.status === 204 ? null : await r.json()];
  }, [m, p, j]);
  const ok = async (m, p, j) => { const [s, r] = await api(m, p, j); if (s >= 300) throw new Error(`${m} ${p}: ${s} ${JSON.stringify(r)}`); return r; };
  await ok('POST', '/konten', { nr: '0420', bezeichnung: 'Praxiseinrichtung', kontoart: 'aktiv' });
  await ok('POST', '/konten', { nr: '4830', bezeichnung: 'Abschreibungen auf Sachanlagen', kontoart: 'aufwand' });
  await ok('POST', '/geschaeftsjahre', { kennung: String(jahr), beginn: `${jahr}-01-01`, ende: `${jahr}-12-31` });
  await ok('POST', '/nummernkreise', { kuerzel: 'ALLG', bezeichnung: 'Allgemein' });
  await ok('PUT', `/nummernkreise/ALLG/jahre/${jahr}`, { vorlage: 'ALLG-{JJJJ}-{NR:5}', zuruecksetzen: true });
  await ok('POST', '/buchungsarten', { kuerzel: 'BANK', bezeichnung: 'Bank', nummernkreis: 'ALLG' });
  const kauf = await ok('POST', '/buchungen', { buchungsart: 'BANK', belegdatum: `${jahr}-03-15`, buchungsdatum: `${jahr}-03-15`, text: 'Behandlungsliege (Muster)',
    zeilen: [{ konto: '0420', seite: 'S', betrag: '1200.00' }, { konto: '1200', seite: 'H', betrag: '1200.00' }], vorbereiten: true });
  await ok('POST', '/freigaben', { buchungen: [kauf.id] });

  // Seite öffnen: leer, „Neu“ ist gesperrt, bis der Vorsteuerabzug des Buchs feststeht
  await page.reload();
  await page.getByRole('navigation').getByText('Anlagen', { exact: true }).click();
  await page.locator('#an-leer').waitFor();
  ist(await gesperrt('an-neu', 'ob dieses Buch Vorsteuer abzieht') && await gesperrt('an-lauf', 'nichts mehr zu buchen') && await gesperrt('an-aendern', 'Erst ein Anlagegut'), 'Knöpfe gesperrt mit Grund');
  await bild('1-leer');
  await page.locator('#an-einstellung').click();
  await page.locator('#an-f-vorsteuer').selectOption('nein');
  await page.locator('#an-f-anlagekonten').fill('0400-0499');
  await page.locator('#an-dialog-einstellung-senden').click();
  await meldung('Einstellungen gespeichert.');
  await page.locator('#an-ohne-gut').waitFor();
  ist((await page.locator('#an-ohne-gut').textContent()) === '1 Buchung auf einem Anlagekonto ohne Anlagegut' && (await page.locator('#an-stand').textContent()).includes('brutto (kein Vorsteuerabzug)'), 'Buchung ohne Anlagegut erkannt; Kosten brutto');

  // Anlagegut aus der Buchung: Kosten, Datum und Konto sind vorbelegt
  await page.locator('#an-neu').click();
  await page.locator('#an-dialog').waitFor();
  await page.locator('#an-f-aus').selectOption({ index: 1 });
  ist((await page.locator('#an-f-kosten').inputValue()) === '1.200,00' && (await page.locator('#an-f-bestandskonto').inputValue()) === '0420' && (await page.locator('#an-f-zugang').inputValue()) === `15.03.${jahr}`
    && (await page.locator('#an-f-bezeichnung').inputValue()) === 'Behandlungsliege (Muster)', 'aus der Buchung vorbelegt');
  ist(await gesperrt('an-f-begruendung', 'vom Vorschlag der Tabelle abweicht'), 'Begründung gesperrt, solange nichts abweicht');
  await page.locator('#an-f-tabelle').selectOption({ label: 'Büromöbel – 13 Jahre (AV 6.15)' });
  ist((await page.locator('#an-f-nutzungsdauer').inputValue()) === '13' && (await page.locator('#an-f-nd-hinweis').textContent()).includes('Vorschlag der Tabelle: 13 Jahre'), 'Nutzungsdauer aus der AfA-Tabelle vorgeschlagen');
  await page.locator('#an-f-nutzungsdauer').fill('3');
  await page.locator('#an-f-nutzungsdauer').dispatchEvent('change');
  ist((await page.locator('#an-f-nd-hinweis').textContent()).includes('Weicht vom Vorschlag der Tabelle ab (13 Jahre)') && !(await page.locator('#an-f-begruendung').isDisabled()), 'Abweichung benannt, Begründung frei');
  await page.locator('#an-f-begruendung').fill('gebraucht gekauft');
  await page.locator('#an-f-aufwandskonto').fill('4830');
  await page.waitForFunction(() => document.querySelectorAll('#an-f-plan tbody tr').length === 4);
  ist((await page.locator('#an-f-plan tbody tr').first().locator('td').nth(2).textContent()) === '333,33' && await page.locator('#an-f-plan tbody tr').count() === 4, 'Plan: vier Jahre, zehn Monate im ersten');
  ist((await page.locator('#an-f-vorschlag').textContent()).includes('gelten netto'), 'Kosten brutto ohne Nettobetrag: kein Vorschlag, sondern die Bitte um den Nettobetrag');
  await page.locator('#an-f-netto').fill('1008,40');
  await page.locator('#an-f-netto').dispatchEvent('change');
  await page.waitForFunction(() => document.querySelector('#an-f-vorschlag').textContent.includes('Netto über 1.000,00 €'));
  ist((await page.locator('#an-f-vorschlag').textContent()).includes('Verteilung über die Nutzungsdauer'), 'mit Nettobetrag: Vorschlag zur Art');
  await bild('2-anlegen');
  await page.locator('#an-dialog-senden').click();
  await meldung('Anlagegut angelegt.');
  let z = await zeilen();
  ist(JSON.stringify(z[0].slice(1)) === JSON.stringify(['Behandlungsliege (Muster)', `15.03.${jahr}`, '1.200,00', 'linear, 3 Jahre', '0,00', '333,33', '', '866,67', 'offen']), `Zeile: ${JSON.stringify(z[0])}`);
  ist(await page.locator('#an-ohne-gut').count() === 0, 'die Buchung hat jetzt ein Anlagegut');
  ist((await page.locator('#an-abstimmung').textContent()).includes('Differenz 333,33'), 'Abstimmung nennt die Differenz, solange die Absetzung nicht gebucht ist');

  // AfA-Lauf: ein Entwurf, danach nichts mehr zu buchen
  await page.locator('#an-lauf').click();
  await page.locator('#an-lauf-tabelle tbody tr').first().waitFor();
  ist(JSON.stringify(await page.locator('#an-lauf-tabelle tbody tr').first().locator('td').allTextContents()) === JSON.stringify(['Behandlungsliege (Muster)', 'AfA des Jahres', `31.12.${jahr}`, '4830', '0420', '333,33']), 'Lauf zeigt, was gebucht wird');
  await bild('3-lauf');
  await page.locator('#an-dialog-lauf-senden').click();
  await meldung('1 Entwurf angelegt');
  z = await zeilen();
  ist(z[0][9] === 'Entwurf' && await gesperrt('an-lauf', 'nichts mehr zu buchen'), 'Entwurf vermerkt; ein zweiter Lauf fände nichts');
  const entwurf = (await ok('GET', '/buchungen?status=entwurf,freigabebereit')).eintraege.find((x) => x.text === `AfA ${jahr} Behandlungsliege (Muster)`);
  ist(entwurf && entwurf.zeilen[0].konto === '4830' && entwurf.zeilen[0].seite === 'S' && entwurf.zeilen[1].konto === '0420' && entwurf.zeilen[1].betrag === '333.33' && entwurf.buchungsdatum === `${jahr}-12-31`, 'der Entwurf steht im Journal: Aufwand an Bestand zum Jahresende');
  await ok('POST', `/buchungen/${entwurf.id}/vorbereiten`);
  await ok('POST', '/freigaben', { buchungen: [entwurf.id] });
  await page.reload();
  await page.locator('#an-tabelle').waitFor();
  z = await zeilen();
  ist(z[0][9] === 'festgeschrieben' && (await page.locator('#an-abstimmung').textContent()).includes('entsprechen dem Saldo der Anlagekonten (866,67)'), `festgeschrieben, Abstimmung stimmt: ${await page.locator('#an-abstimmung').textContent()}`);
  await bild('4-verzeichnis');

  // Abgang im Dezember: der Rest des Buchwerts ist zu buchen
  await page.locator('#an-tabelle tbody tr').first().click();
  await page.locator('#an-abgang').click();
  await page.locator('#an-f-abgang').fill(`20.12.${jahr}`);
  await page.locator('#an-f-abgang').dispatchEvent('change');
  await page.locator('#an-dialog-abgang-senden').click();
  await meldung('Abgang eingetragen.');
  z = await zeilen();
  ist(z[0][6] === '333,33' && z[0][7] === '866,67' && z[0][8] === '0,00' && z[0][9] === 'offen 866,67' && z[0][1].includes(`Abgang 20.12.${jahr}`), `Abgang: ${JSON.stringify(z[0])}`);
  ist(!(await page.locator('#an-lauf').isDisabled()), 'der Lauf bietet den Rest an');

  // Druckansicht
  await page.emulateMedia({ media: 'print' });
  ist(!(await page.locator('.navigation').isVisible()) && !(await page.locator('#an-neu').isVisible()) && await page.locator('#an-tabelle').isVisible(), 'Druck: nur Stand und Verzeichnis');
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
