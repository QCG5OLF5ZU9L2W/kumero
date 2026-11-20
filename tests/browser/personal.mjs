// SPDX-License-Identifier: AGPL-3.0-or-later
// Browsertest des Moduls personal (PERSONAL.md Stufe 1, F136) und der Rollen am Mandanten (F135): Erika führt das
// Personal und ist selbst Mitarbeiterin. Sie legt Personen an, eine Fassung des Vertrags, eine Frist; verschiebt den
// Teiler zwischen Liste und Akte (F137), filtert am Spaltenkopf (F91), schlägt unter „Meine Daten“ eine Änderung vor
// und übernimmt sie. Adam, der Administrator, sieht die Rollen am Mandanten und weist eine zu. Nur erfundene Personen.
//
//   KUMERO_TEST_PGHOST=/tmp/pgtest KUMERO_TEST_PGUSER=postgres \
//   PLAYWRIGHT=/opt/npm-tools/node_modules/playwright CHROME=/usr/bin/google-chrome node tests/browser/personal.mjs [Bildverzeichnis]
import { spawn, execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { mkdirSync } from 'node:fs';
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
const tag = (plus) => { const d = new Date(); d.setDate(d.getDate() + plus); return `${String(d.getDate()).padStart(2, '0')}.${String(d.getMonth() + 1).padStart(2, '0')}.${d.getFullYear()}`; };

const umgebung = JSON.parse(execFileSync('php', [path.join(wurzel, 'tests/browser/aufbau.php'), ADRESSE, '--personal'], { encoding: 'utf8' }).trim().split('\n').pop());
const server = spawn('php', ['-S', `localhost:${PORT}`, path.join(wurzel, 'werkzeuge/entwicklungsserver.php')], {
  cwd: wurzel, env: { ...process.env, KUMERO_KONFIG: umgebung.konfig, KUMERO_ENTWICKLUNG: '1' }, stdio: ['ignore', 'ignore', 'pipe'],
});
let serverLog = '';
server.stderr.on('data', (d) => { serverLog += d; });
await new Promise((r) => setTimeout(r, 700));

const browser = await chromium.launch({ executablePath: process.env.CHROME || undefined, headless: true });
const fehler = [];
async function person() {
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
  return {
    page,
    bild: async (name) => { if (bilder) await page.screenshot({ path: path.join(bilder, `${name}.png`), fullPage: true }); },
    meldung: (text) => page.locator('.meldung').filter({ hasText: text }).first().waitFor(),
    gesperrt: async (id, grund) => await page.locator(`#${id}`).isDisabled() && (await page.locator(`#${id}`).getAttribute('title')).includes(grund),
    anmelden: async (link) => {
      await page.goto(link);
      await page.getByRole('button', { name: 'Passkey registrieren' }).click();
      await page.getByText('Passkey registriert. Jetzt anmelden.').waitFor();
      await page.locator('#anmelden').click();
      await page.locator('#mandant-name').waitFor();
    },
    menue: (text) => page.getByRole('navigation').getByText(text, { exact: true }).click(),
  };
}

try {
  const e = await person();
  await e.anmelden(umgebung.link);
  const nav = await e.page.getByRole('navigation').textContent();
  ist(nav.includes('Personal') && nav.includes('Meine Daten') && nav.includes('Personen') && nav.includes('Fristen und Nachweise'), 'Bereich „Personal“ mit seinen drei Einträgen');

  // Personen: leere Liste, Knöpfe gesperrt mit Grund, Akte an festem Platz
  await e.menue('Personen');
  await e.page.locator('#pe-leer').waitFor();
  ist((await e.page.locator('#pe-leer').textContent()).includes('Noch keine Person'), 'leere Liste sagt, wie es losgeht');
  ist(await e.gesperrt('pe-aendern', 'Erst eine Person in der Liste wählen') && await e.gesperrt('pe-vertrag', 'Erst eine Person') && await e.gesperrt('pe-loesch', 'Erst eine Person'), 'Bearbeiten, Vertrag und Löschfrist gesperrt mit Grund');
  ist((await e.page.locator('#pe-akte-leer').textContent()).includes('Wähle eine Person'), 'der Platz der Akte steht von Anfang an');
  ist(await e.page.locator('#pe-tabelle thead details.sp-filter').count() === 9, 'jede Spalte trägt Sortierung und Filter');

  await e.page.locator('#pe-neu').click();
  await e.page.locator('#pe-f-nr').fill('100');
  await e.page.locator('#pe-f-familienname').fill('Muster');
  await e.page.locator('#pe-f-vornamen').fill('Erika');
  await e.page.locator('#pe-f-nutzer').selectOption({ label: 'Erika Muster' });
  await e.page.locator('#pe-f-strasse').fill('Lindenweg 7');
  await e.page.locator('#pe-f-email').fill('keine-adresse');
  await e.page.locator('#pe-dialog-person-senden').click();
  await e.page.locator('#pe-dialog-person-fehler').filter({ hasText: 'E-Mail: Keine E-Mail-Adresse' }).waitFor();
  ist(await e.page.locator('#pe-dialog-person').isVisible(), 'ungültige Eingabe: der Dialog bleibt offen und nennt das Feld');
  await e.page.locator('#pe-f-email').fill('erika@privat.example.org');
  await e.page.locator('#pe-dialog-person-senden').click();
  await e.meldung('Person angelegt.');
  await e.page.locator('#pe-akte-titel').filter({ hasText: 'Akte: Muster, Erika' }).waitFor();
  ist((await e.page.locator('#pe-liste tr.gewaehlt').textContent()).includes('Muster, Erika (du)'), 'die neue Person ist gewählt und als die eigene gekennzeichnet');
  ist((await e.page.locator('#pe-privat').textContent()).includes('Lindenweg 7') && (await e.page.locator('#pe-kein-vertrag').count()) === 1, 'Akte: private Angaben, noch kein Vertrag');
  ist((await e.page.locator('#pe-liste tr.gewaehlt [data-zustand="fehlt"]').textContent()).includes('Frist fehlt'), 'ohne Löschfrist: „Frist fehlt“ mit Zeichen und Text');

  // Vertrag: Fassung mit Stunden je Wochentag
  await e.page.locator('#pe-vertrag').click();
  ist((await e.page.locator('#pe-v-summe').textContent()).includes('40 Std.'), 'Vorbelegung: fünf Tage zu acht Stunden');
  ist(await e.page.locator('#pe-v-befristet').isDisabled(), '„Befristet bis“ steht da und ist bei unbefristet gesperrt');
  await e.page.locator('#pe-v-gueltig').fill('01.01.2026');
  await e.page.locator('#pe-v-beginn').fill('01.01.2026');
  await e.page.locator('#pe-v-funktion').fill('Praxisleitung');
  await e.page.locator('#pe-v-tag-4').fill('4,5');
  ist((await e.page.locator('#pe-v-summe').textContent()).includes('36,5 Std.'), 'Summe rechnet mit');
  await e.page.locator('#pe-v-urlaub').fill('30');
  await e.page.locator('#pe-v-buch').selectOption('haupt');
  await e.page.locator('#pe-dialog-vertrag-senden').click();
  await e.meldung('Fassung angelegt.');
  await e.page.locator('#pe-vertraege [data-zustand="gilt"]').waitFor();
  const fassung = await e.page.locator('#pe-vertraege tbody tr').first().textContent();
  ist(fassung.includes('36,5 Std.') && fassung.includes('30 Tage') && fassung.includes('Musterpraxis') && fassung.includes('Praxisleitung'), 'Fassung in der Akte: Stunden, Urlaub, Buch, Funktion');
  ist((await e.page.locator('#pe-liste tr.gewaehlt').textContent()).includes('Praxisleitung'), 'die Funktion steht in der Liste');
  await e.bild('1-akte');

  // Teiler: verschiebbar mit der Tastatur und der Maus, gemerkt über das Neuladen
  const hoehe = () => e.page.locator('#pe-teilung .teil-unten').evaluate((el) => el.getBoundingClientRect().height);
  const vorher = await hoehe();
  await e.page.locator('#pe-teilung-teiler').focus();
  await e.page.keyboard.press('ArrowUp');
  ist(Math.round(await hoehe()) === Math.round(vorher) + 16, 'Pfeil nach oben: die Akte wird höher');
  const griff = await e.page.locator('#pe-teilung-teiler').boundingBox();
  await e.page.mouse.move(griff.x + griff.width / 2, griff.y + 4);
  await e.page.mouse.down();
  await e.page.mouse.move(griff.x + griff.width / 2, griff.y + 4 + 60, { steps: 4 });
  await e.page.mouse.up();
  const gezogen = await hoehe();
  ist(Math.abs(gezogen - (vorher + 16 - 60)) <= 2, 'mit der Maus nach unten gezogen: die Akte wird niedriger');
  await e.page.reload();
  await e.page.locator('#pe-tabelle tbody tr[data-id]').first().waitFor();
  ist(Math.abs(await hoehe() - gezogen) <= 1, 'die Aufteilung bleibt nach dem Neuladen');

  // zweite Person; Filter am Spaltenkopf
  await e.page.locator('#pe-neu').click();
  await e.page.locator('#pe-f-nr').fill('101');
  await e.page.locator('#pe-f-familienname').fill('Beispiel');
  await e.page.locator('#pe-f-vornamen').fill('Bert');
  await e.page.locator('#pe-f-vorgesetzter').selectOption({ label: 'Muster, Erika' });
  await e.page.locator('#pe-dialog-person-senden').click();
  await e.meldung('Person angelegt.');
  await e.page.locator('#pe-tabelle tbody tr[data-id]').nth(1).waitFor();
  await e.page.locator('#pe-tabelle th details[data-spalte="name"] summary').click();
  await e.page.locator('#pe-tabelle th details[data-spalte="name"] li').filter({ hasText: 'Beispiel, Bert' }).locator('.sp-ein').click();
  ist(await e.page.locator('#pe-tabelle tbody tr[data-id]').count() === 1 && (await e.page.locator('#pe-liste .liste-fuss').textContent()).includes('1 von 2 Personen · gefiltert'), 'Filter „finden“: eine von zwei Personen');
  await e.page.locator('#pe-tabelle th details[data-spalte="name"] .sp-aufheben').click();
  ist(await e.page.locator('#pe-tabelle tbody tr[data-id]').count() === 2, 'Filter aufgehoben');
  await e.page.locator('#seite h1').click();
  await e.page.locator('#pe-suche').fill('praxisleit');
  ist(await e.page.locator('#pe-tabelle tbody tr[data-id]').count() === 1, 'Suche über Name, Nummer und Funktion');
  await e.page.locator('#pe-suche').fill('');

  // Löschfrist an einem Objekt
  await e.page.locator('#pe-tabelle tbody tr').filter({ hasText: 'Muster, Erika (du)' }).click();
  await e.page.locator('#pe-akte-titel').filter({ hasText: 'Muster, Erika' }).waitFor();
  await e.page.locator('#pe-loesch').click();
  ist(await e.page.locator('#pe-l-objekt option').count() === 3, 'Objekte der Akte: Stamm, private Angaben, eine Fassung');
  await e.page.locator('#pe-l-objekt').selectOption('2');
  await e.page.locator('#pe-l-ab').fill('31.12.2036');
  await e.page.locator('#pe-l-grund').fill('Vorgabe des Verantwortlichen');
  await e.page.locator('#pe-dialog-loesch-senden').click();
  await e.meldung('Löschfrist festgelegt.');
  await e.page.locator('#pe-vertraege tbody tr').first().filter({ hasText: 'löschen ab 31.12.2036' }).waitFor();
  ist(true, 'die Frist steht am Objekt');

  // Fristen und Nachweise
  await e.menue('Fristen und Nachweise');
  await e.page.locator('#pf-leer').waitFor();
  ist(await e.gesperrt('pf-erledigt', 'Erst eine Frist in der Liste wählen'), 'Erledigt gesperrt mit Grund');
  await e.page.locator('#pf-neu').click();
  await e.page.locator('#pf-f-person').selectOption({ label: 'Beispiel, Bert' });
  await e.page.locator('#pf-f-art').selectOption({ label: 'Unterweisung' });
  await e.page.locator('#pf-f-faellig').fill(tag(3));
  await e.page.locator('#pf-f-bemerkung').fill('Hygiene');
  await e.page.locator('#pf-dialog-neu-senden').click();
  await e.meldung('Frist angelegt.');
  await e.page.locator('#pf-tabelle tr.gewaehlt [data-zustand="faellig"]').waitFor();
  ist((await e.page.locator('#pf-tabelle tr.gewaehlt').textContent()).includes('▲ fällig in 3 Tagen'), 'Zustand mit Zeichen und Text');
  await e.bild('2-fristen');
  await e.page.locator('#pf-erledigt').click();
  await e.page.locator('dialog[open]').getByRole('button', { name: 'Erledigt' }).click();
  await e.meldung('Als erledigt vermerkt.');
  await e.page.locator('#pf-leer').waitFor();
  ist(true, 'erledigte Fristen stehen nicht mehr in der Liste');

  // Selfservice: vorschlagen – und als Personal übernehmen
  await e.menue('Meine Daten');
  await e.page.locator('#pm-stamm').waitFor();
  ist((await e.page.locator('#pm-stamm').textContent()).includes('Muster, Erika') && (await e.page.locator('#pm-vertrag').textContent()).includes('36,5 Std. je Woche'), 'Meine Daten: Stamm und geltender Vertrag');
  await e.page.locator('#pm-vorschlag').click();
  await e.page.locator('#pm-dialog-vorschlag-senden').click();
  await e.page.locator('#pm-dialog-vorschlag-fehler').filter({ hasText: 'nichts geändert' }).waitFor();
  await e.page.locator('#pm-f-telefon').fill('0000 333333');
  await e.page.locator('#pm-dialog-vorschlag-senden').click();
  await e.meldung('Vorschlag gesendet');
  await e.page.locator('#pm-vorschlaege [data-zustand="offen-vorschlag"]').waitFor();
  ist(!(await e.page.locator('#pm-privat').textContent()).includes('0000 333333'), 'in der Akte steht der Vorschlag noch nicht');
  await e.bild('3-meine');
  await e.menue('Personen');
  await e.page.locator('#pe-tabelle tbody tr').filter({ hasText: 'Muster, Erika (du)' }).click();
  await e.page.locator('#pe-vorschlaege .pe-uebernehmen').click();
  await e.meldung('Vorschlag übernommen.');
  await e.page.locator('#pe-privat').filter({ hasText: '0000 333333' }).waitFor();
  ist((await e.page.locator('#pe-vorschlaege').textContent()).includes('✓ übernommen'), 'übernommen: steht in der Akte');

  // Adam (Administrator): Rollen am Mandanten in eigener Spalte und in derselben Auswahl; vom Personal sieht er nichts
  const a = await person();
  await a.anmelden(umgebung.administrator);
  ist(!(await a.page.getByRole('navigation').textContent()).includes('Meine Daten'), 'der Administrator hat keinen Eintrag des Personals');
  await a.menue('Nutzer und Rollen');
  await a.page.locator('#nu-liste tbody tr').nth(1).waitFor();
  ist((await a.page.locator('#nu-liste thead').textContent()).includes('Rollen am Mandanten'), 'Spalte „Rollen am Mandanten“');
  await a.page.locator('#nu-liste tbody tr').filter({ hasText: 'Muster, Erika' }).click();
  ist((await a.page.locator('#nu-liste tr.gewaehlt .nu-rollen-mandant').textContent()) === 'Mitarbeiter, Personal', 'Erikas Rollen am Mandanten');
  await a.page.locator('#nu-rolle').click();
  const wahl = await a.page.locator('#nu-f-rolle option').allTextContents();
  ist(wahl.includes('Lohn – am Mandanten') && wahl.includes('Buchhalter – im Buch'), 'die Auswahl sagt je Rolle, wo sie gilt');
  await a.page.locator('#nu-f-rolle').selectOption({ label: 'Lohn – am Mandanten' });
  await a.page.locator('#nu-dialog-rolle-senden').click();
  await a.meldung('Rolle Lohn zugewiesen.');
  await a.page.locator('#nu-liste tr.gewaehlt .nu-rollen-mandant').filter({ hasText: 'Lohn, Mitarbeiter, Personal' }).waitFor();
  await a.page.locator('#nu-rolle-ende').click();
  await a.page.locator('#nu-dialog-rolle-ende label').filter({ hasText: 'Lohn – am Mandanten' }).locator('input').check();
  await a.page.locator('#nu-dialog-rolle-ende-senden').click();
  await a.meldung('Rolle beendet.');
  await a.page.locator('#nu-liste tr.gewaehlt .nu-rollen-mandant').filter({ hasText: /^Mitarbeiter, Personal$/ }).waitFor();
  ist(true, 'Rolle am Mandanten zugewiesen und beendet');
  await a.bild('4-rollen');
} finally {
  await browser.close();
  server.kill();
  execFileSync('php', [path.join(wurzel, 'tests/browser/aufbau.php'), '--abbau']);
}
// gewollt abgewiesen: ungültige E-Mail-Adresse (400)
const echteFehler = fehler.filter((f) => !/status of (400|401)/.test(f) && !/^HTTP 400 .*\/personal\/personen$/.test(f));
if (echteFehler.length) {
  console.error(echteFehler.join('\n'));
  console.error(serverLog.split('\n').filter((z) => /error|warn|kumero\[/i.test(z)).slice(-20).join('\n'));
  process.exit(1);
}
console.log(`${pruefungen} Prüfungen im Browser, alle grün`);
