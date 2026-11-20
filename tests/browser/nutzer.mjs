// SPDX-License-Identifier: AGPL-3.0-or-later
// Browsertest der Seite „Nutzer und Rollen“ (VERWALTUNG.md Abschnitt 6 und 15; F125): Der Administrator legt eine
// Nutzerin an, bekommt Link und Code genau einmal, weist eine Rolle zu, sperrt und entsperrt; die Nutzerin richtet
// mit Link und Code ihren Passkey ein und meldet sich an. Nur synthetische Daten.
//
//   KUMERO_TEST_PGHOST=/tmp/pgtest KUMERO_TEST_PGUSER=postgres \
//   PLAYWRIGHT=/opt/npm-tools/node_modules/playwright CHROME=/usr/bin/google-chrome node tests/browser/nutzer.mjs [Bildverzeichnis]
import { spawn, execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { mkdirSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const require = createRequire(import.meta.url);
const { chromium } = require(process.env.PLAYWRIGHT || 'playwright');
const wurzel = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const PORT = 8094;
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
/** Ein Browser je Person: eigene Sitzung, eigener Passkey-Speicher. */
async function person() {
  const context = await browser.newContext({ viewport: { width: 1400, height: 900 }, locale: 'de-DE' });
  await context.grantPermissions(['clipboard-read', 'clipboard-write']);
  const page = await context.newPage();
  page.on('console', (m) => { if (m.type() === 'error') fehler.push(`console: ${m.text()}`); });
  page.on('pageerror', (e) => fehler.push(`pageerror: ${e.message}`));
  page.on('response', (r) => { if (r.status() >= 400 && r.status() !== 401) fehler.push(`HTTP ${r.status()} ${r.url()}`); });
  const cdp = await context.newCDPSession(page);
  await cdp.send('WebAuthn.enable');
  await cdp.send('WebAuthn.addVirtualAuthenticator', { options: {
    protocol: 'ctap2', transport: 'internal', hasResidentKey: true, hasUserVerification: true, isUserVerified: true, automaticPresenceSimulation: true,
  } });
  return {
    page,
    bild: async (name) => { if (bilder) await page.screenshot({ path: path.join(bilder, `${name}.png`), fullPage: true }); },
    meldung: (text) => page.locator('.meldung').filter({ hasText: text }).first().waitFor(),
    gesperrt: async (id, grund) => await page.locator(`#${id}`).isDisabled() && (await page.locator(`#${id}`).getAttribute('title')).includes(grund),
  };
}

try {
  // Adam (Administrator): Der Link der Kommandozeile hat keinen Code – das Feld ist gesperrt und sagt warum
  const a = await person();
  await a.page.goto(umgebung.administrator);
  await a.page.getByText('nur einmal verwendbar').waitFor();
  ist(await a.gesperrt('registrierung-code', 'Zu diesem Link gehört kein Code'), 'Link ohne Code: Feld gesperrt mit Grund');
  await a.page.getByRole('button', { name: 'Passkey registrieren' }).click();
  await a.page.getByText('Passkey registriert. Jetzt anmelden.').waitFor();
  await a.page.locator('#anmelden').click();
  await a.page.getByRole('navigation').getByText('Nutzer und Rollen', { exact: true }).click();
  await a.page.locator('#nu-liste tbody tr').nth(1).waitFor();
  ist(await a.page.locator('#nu-liste tbody tr').count() === 2, 'Liste: Administrator und Verantwortliche');
  ist((await a.page.locator('#nu-liste tbody tr').first().textContent()).includes('Admin, Adam (du)'), 'die eigene Zeile ist gekennzeichnet');
  ist(await a.gesperrt('nu-aendern', 'Erst einen Nutzer in der Liste wählen'), 'Bearbeiten gesperrt mit Grund');
  ist(await a.gesperrt('nu-sperren', 'Erst einen Nutzer in der Liste wählen oder markieren'), 'Sperren gesperrt mit Grund');
  ist(!(await a.page.locator('#nu-anlegen').isDisabled()), 'Anlegen ist frei');
  await a.bild('1-liste');

  // anlegen mit Rolle und Zugang: Link und Code erscheinen einmal
  await a.page.locator('#nu-anlegen').click();
  await a.page.locator('#nu-f-vornamen').fill('Berta');
  await a.page.locator('#nu-f-familienname').fill('Buchhalter');
  await a.page.locator('#nu-f-email').fill('keine-adresse');
  await a.page.locator('#nu-dialog-anlegen-senden').click();
  ist(await a.page.locator('#nu-dialog-anlegen').isVisible(), 'ungültige E-Mail-Adresse: der Dialog bleibt offen');
  await a.page.locator('#nu-f-email').fill('berta@example.org');
  await a.page.locator('#nu-dialog-anlegen input[value="Buchhalter"]').check();
  await a.page.locator('#nu-dialog-anlegen-senden').click();
  await a.page.locator('#nu-dialog-zugang-ergebnis').waitFor();
  const link = await a.page.locator('#nu-zugang-link').inputValue();
  const code = (await a.page.locator('#nu-zugang-code').textContent()).replace(/\s/g, '');
  ist(/#registrierung=browserpraxis\.[A-Za-z0-9_-]{43}$/.test(link) && /^[A-HJKMNP-Z2-9]{6}$/.test(code), 'Link und Code werden gezeigt');
  ist((await a.page.locator('#nu-dialog-zugang-ergebnis').textContent()).includes('kumero zeigt Link und Code nicht wieder'), 'mit dem Hinweis, dass es das einzige Mal ist');
  await a.bild('2-zugang');
  await a.page.locator('#nu-zugang-schliessen').click();
  await a.page.locator('#nu-liste tr.gewaehlt [data-zustand="zugang"]').waitFor();
  ist(await a.page.locator('#nu-liste tbody tr').count() === 3, 'die neue Nutzerin steht in der Liste, gewählt, mit offenem Zugang');
  ist((await a.page.locator('#nu-liste tr.gewaehlt').textContent()).includes('Buchhalter, Berta') && !(await a.page.content()).includes(code), 'der Code steht nirgends mehr auf der Seite');

  // Rolle zuweisen und beenden
  await a.page.locator('#nu-rolle').click();
  await a.page.locator('#nu-f-rolle').selectOption('Leser');
  await a.page.locator('#nu-dialog-rolle-senden').click();
  await a.meldung('Rolle Leser zugewiesen.');
  await a.page.locator('#nu-liste tr.gewaehlt').filter({ hasText: 'Buchhalter, Leser' }).waitFor();
  ist(true, 'beide Rollen in der Liste');
  await a.page.locator('#nu-rolle-ende').click();
  await a.page.locator('#nu-dialog-rolle-ende label').filter({ hasText: 'Leser' }).locator('input').check();
  await a.page.locator('#nu-dialog-rolle-ende-senden').click();
  await a.meldung('Rolle beendet.');
  await a.page.locator('#nu-dialog-rolle-ende').waitFor({ state: 'detached' });
  ist(!(await a.page.locator('#nu-liste tr.gewaehlt').textContent()).includes('Leser'), 'Rolle Leser beendet');

  // sperren und entsperren – für die markierten Einträge; der letzte Administrator bleibt
  ist(await a.gesperrt('nu-entsperren', 'Nicht gesperrt') && await a.gesperrt('nu-passkey', 'Der Nutzer hat keinen Passkey'), 'Entsperren und Passkey widerrufen gesperrt mit Grund');
  await a.page.locator('#nu-sperren').click();
  await a.meldung('Nutzer gesperrt.');
  await a.page.locator('#nu-liste tr.gewaehlt [data-zustand="gesperrt"]').waitFor();
  ist(await a.gesperrt('nu-zugang', 'Der Nutzer ist gesperrt'), 'kein Zugang für Gesperrte, mit Grund');
  await a.page.locator('#nu-alle').check();
  ist((await a.page.locator('#nu-liste .liste-fuss').textContent()).includes('3 markiert'), 'alle markiert');
  ist(await a.gesperrt('nu-aendern', 'nur einen markieren'), 'Bearbeiten geht nur für einen');
  await a.page.locator('#nu-entsperren').click();
  await a.meldung('Nutzer entsperrt.');
  await a.page.locator('#nu-sperren').click();
  await a.meldung('wird nicht gesperrt');
  ist(await a.page.locator('#nu-liste [data-zustand="gesperrt"]').count() === 1, 'gesperrt wurde nur, wer gesperrt werden darf: Administrator und Verantwortliche bleiben');
  await a.bild('3-gesperrt');
  await a.page.locator('#nu-alle').uncheck();
  await a.page.locator('#nu-liste tbody tr').filter({ hasText: 'Buchhalter, Berta' }).click();
  await a.page.locator('#nu-entsperren').click();
  await a.meldung('Nutzer entsperrt.');

  // Bücher: Adam legt ein zweites an (Auftrag an den Dienst) und wechselt im Kopf dorthin – ohne neue Anmeldung
  ist(await a.page.locator('#buch-wahl').isDisabled() && (await a.page.locator('#buch-wahl').getAttribute('title')).includes('nur dieses Buch'), 'ein Buch: die Auswahl im Kopf ist gesperrt und sagt warum');
  await a.page.getByRole('navigation').getByText('Bücher', { exact: true }).click();
  await a.page.locator('#bu-liste tbody tr').first().waitFor();
  ist((await a.page.locator('#bu-liste tbody tr').first().textContent()).includes('haupt'), 'Seite „Bücher“ zeigt das erste Buch');
  await a.page.locator('#bu-anlegen').click();
  await a.page.locator('#bu-f-kennung').fill('zwei');
  await a.page.locator('#bu-f-name').fill('Datenschutz-Mandate');
  await a.page.locator('#bu-f-art').selectOption('bilanz');
  await a.page.locator('#bu-f-testbuch').check();
  await a.page.locator('#bu-dialog-anlegen-senden').click();
  await a.meldung('Buch beauftragt.');
  await a.page.locator('#bu-stand', { hasText: 'wird angelegt' }).waitFor();
  ist(await a.gesperrt('bu-anlegen', 'Es wird gerade ein Buch angelegt'), 'während des Auftrags kein zweiter, mit Grund');
  execFileSync('php', [path.join(wurzel, 'bin/kumero'), 'system:auftraege'], { env: { ...process.env, KUMERO_KONFIG: umgebung.konfig, KUMERO_DB_NUTZER: 'kumero_owner', KUMERO_VORLAGE_DB: 'kumero_test_php_vorlage' } });
  await a.page.locator('#bu-liste tbody tr').nth(1).waitFor({ timeout: 15000 });
  ist((await a.page.locator('#bu-liste tbody tr').nth(1).textContent()).includes('Testbuch'), 'das zweite Buch steht in der Liste, als Testbuch');
  await a.page.locator('#buch-wahl:not([disabled])').waitFor();
  await a.bild('3a-buecher');
  await a.page.locator('#buch-wahl').selectOption('zwei');
  await a.page.waitForFunction(() => document.documentElement.classList.contains('testbuch'));
  ist(await a.page.locator('#nutzermenue summary').textContent() !== '', 'weiter angemeldet nach dem Wechsel des Buchs');
  await a.page.getByRole('navigation').getByText('Nutzer und Rollen', { exact: true }).click();
  await a.page.locator('#nu-liste tbody tr').nth(2).waitFor();
  ist((await a.page.locator('#nu-hinweis').textContent()).includes('Datenschutz-Mandate') && !(await a.page.locator('#nu-liste tbody tr').filter({ hasText: 'Buchhalter, Berta' }).textContent()).includes('Buchhalter, Berta@') , 'die Seite nennt das gewählte Buch');
  ist((await a.page.locator('#nu-liste tbody tr').filter({ hasText: 'Buchhalter, Berta' }).textContent()).includes('keine Rolle'), 'im zweiten Buch hat Berta keine Rolle – Rollen gelten je Buch');
  await a.bild('3b-zweites-buch');
  await a.page.locator('#buch-wahl').selectOption('haupt');
  await a.page.waitForFunction(() => !document.documentElement.classList.contains('testbuch'));

  // Module (F142): einschalten, ausschalten – je Buch bzw. am Mandanten, als Auftrag an den Dienst
  const dienst = () => execFileSync('php', [path.join(wurzel, 'bin/kumero'), 'system:auftraege'], { env: { ...process.env, KUMERO_KONFIG: umgebung.konfig, KUMERO_DB_NUTZER: 'kumero_owner', KUMERO_VORLAGE_DB: 'kumero_test_php_vorlage' } });
  await a.page.getByRole('navigation').getByText('Module', { exact: true }).click();
  await a.page.locator('#mo-tabelle tbody tr[data-modul]').first().waitFor();
  ist(await a.gesperrt('mo-ein', 'Erst ein Modul in der Liste wählen') && await a.gesperrt('mo-aus', 'Erst ein Modul') && await a.gesperrt('mo-nach', 'auf dem Stand des Releases'), 'Module: Knöpfe gesperrt mit Grund');
  const modulZeile = (modul, buch) => a.page.locator(`#mo-tabelle tr[data-modul="${modul}"][data-buch="${buch}"]`);
  ist(await modulZeile('lager', 'zwei').count() === 1 && await modulZeile('lager', 'haupt').count() === 1 && await modulZeile('personal', '').count() === 1, 'ein Modul eines Buchs steht je Buch da, eines am Mandanten einmal');
  await modulZeile('arbeitsvorrat', 'zwei').click();
  ist(await a.gesperrt('mo-ein', 'Erst einschalten: buchen'), 'ein Modul, das auf einem anderen aufbaut, nennt es');
  await modulZeile('lager', 'zwei').click();
  ist((await modulZeile('lager', 'zwei').textContent()).includes('nie eingeschaltet') && await a.gesperrt('mo-aus', 'nicht aktiv'), 'Lager im zweiten Buch: nie eingeschaltet');
  await a.page.locator('#mo-ein').click();
  await a.meldung('Beauftragt');
  await a.page.locator('#mo-stand', { hasText: 'Ein Auftrag läuft' }).waitFor();
  ist(await a.gesperrt('mo-ein', 'läuft gerade ein Auftrag'), 'während des Auftrags kein zweiter, mit Grund');
  dienst();
  await a.meldung('Modul eingeschaltet.');
  await modulZeile('lager', 'zwei').filter({ hasText: '● aktiv' }).waitFor();
  ist((await modulZeile('lager', 'haupt').textContent()).includes('nie eingeschaltet') && (await a.page.locator('#mo-stand').textContent()).includes('lager im Buch zwei eingeschaltet'), 'aktiv nur im zweiten Buch; die Statuszeile nennt den letzten Auftrag');
  await a.bild('3c-module');
  await a.page.locator('#mo-aus').click();
  ist((await a.page.locator('dialog[open]').textContent()).includes('Daten, Rechte, Rollen und Zuweisungen bleiben'), 'vor dem Ausschalten: was bleibt');
  await a.page.locator('dialog[open] .knopf.primaer').click();
  await a.page.locator('#mo-stand', { hasText: 'Ein Auftrag läuft' }).waitFor();
  dienst();
  await a.meldung('Modul ausgeschaltet.');
  await modulZeile('lager', 'zwei').filter({ hasText: '○ ausgeschaltet' }).waitFor();
  ist(true, 'ausgeschaltet – die Zeile bleibt mit ihrem Stand');
  await modulZeile('personal', '').click();
  await a.page.locator('#mo-ein').click();
  await a.page.locator('#mo-stand', { hasText: 'Ein Auftrag läuft' }).waitFor();
  dienst();
  await a.meldung('Modul eingeschaltet.');
  await modulZeile('personal', '').filter({ hasText: '● aktiv' }).waitFor();
  ist(true, 'ein Modul am Mandanten eingeschaltet');

  // Berta: erst Link, dann Code
  const b = await person();
  await b.page.goto(link);
  await b.page.getByText('Zugang für „Musterpraxis“').waitFor();
  ist(!(await b.page.locator('#registrierung-code').isDisabled()), 'zum Link gehört ein Code');
  await b.page.locator('#registrierung-code').fill(code[0] === 'A' ? `B${code.slice(1)}` : `A${code.slice(1)}`);
  await b.page.getByRole('button', { name: 'Passkey registrieren' }).click();
  await b.meldung('Der Code stimmt nicht.');
  await b.page.locator('#registrierung-code').fill(`${code.slice(0, 3).toLowerCase()} ${code.slice(3)}`);
  await b.bild('4-einrichten');
  await b.page.getByRole('button', { name: 'Passkey registrieren' }).click();
  await b.page.getByText('Passkey registriert. Jetzt anmelden.').waitFor();
  await b.page.locator('#anmelden').click();
  await b.page.locator('#mandant-name').waitFor();
  ist((await b.page.locator('#mandant-name').textContent()).includes('Musterpraxis'), 'Berta ist im Mandanten angemeldet');
  ist(await b.page.getByRole('navigation').getByText('Nutzer und Rollen', { exact: true }).count() === 0, 'als Buchhalterin sieht sie die Nutzerverwaltung nicht');

  // zurück bei Adam: Berta hat einen Passkey und war angemeldet
  await a.page.getByRole('navigation').getByText('Nutzer und Rollen', { exact: true }).click();
  await a.page.reload();
  await a.page.locator('#nu-liste tbody tr').nth(2).waitFor();
  const zeile = await a.page.locator('#nu-liste tbody tr').filter({ hasText: 'Buchhalter, Berta' }).textContent();
  ist(!zeile.includes('noch nie') && !zeile.includes('offen bis'), 'Passkey eingerichtet, Zugang verbraucht, Anmeldung vermerkt');
  await a.bild('5-fertig');
} finally {
  await browser.close();
  server.kill();
  execFileSync('php', [path.join(wurzel, 'tests/browser/aufbau.php'), '--abbau']);
}
// gewollt abgewiesen: ungültige E-Mail-Adresse (400), falscher Code (403), Sperren der Letzten (409)
const echteFehler = fehler.filter((f) => !/status of (400|401|403|409)/.test(f) && !/^HTTP (400|409) .*\/nutzer/.test(f) && !/^HTTP 403 .*\/registrierung\/optionen/.test(f));
if (echteFehler.length) {
  console.error(echteFehler.join('\n'));
  console.error(serverLog.split('\n').filter((z) => /error|warn|kumero\[/i.test(z)).slice(-20).join('\n'));
  process.exit(1);
}
console.log(`${pruefungen} Prüfungen im Browser, alle grün`);
