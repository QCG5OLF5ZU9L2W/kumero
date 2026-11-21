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
  ist(nav.includes('Personal') && nav.includes('Meine Daten') && nav.includes('Personen') && nav.includes('Fristen und Nachweise') && nav.includes('Lohnvorbereitung') && nav.includes('Beurteilungen und Ziele'), 'Bereich „Personal“ mit seinen Einträgen');
  // Wer das Personal führt und noch keine Person ist, erfährt unter „Meine Daten“, wo er sich verknüpft
  await e.menue('Meine Daten');
  await e.page.locator('#pm-leer').filter({ hasText: 'Lege dich unter „Personen“ als Person an' }).waitFor();
  ist(true, 'Meine Daten ohne Person: der Hinweis nennt den Weg');

  // Personen: leere Liste, Knöpfe gesperrt mit Grund, Akte an festem Platz
  await e.menue('Personen');
  await e.page.locator('#pe-leer').waitFor();
  ist((await e.page.locator('#pe-leer').textContent()).includes('Noch keine Person'), 'leere Liste sagt, wie es losgeht');
  ist(await e.gesperrt('pe-aendern', 'Erst eine Person in der Liste wählen') && await e.gesperrt('pe-vertrag', 'Erst eine Person') && await e.gesperrt('pe-loesch', 'Erst eine Person'), 'Bearbeiten, Vertrag und Löschfrist gesperrt mit Grund');
  ist((await e.page.locator('#pe-akte-leer').textContent()).includes('Wähle eine Person'), 'der Platz der Akte steht von Anfang an');
  ist(await e.page.locator('#pe-tabelle thead details.sp-filter').count() === 9, 'jede Spalte trägt Sortierung und Filter');

  await e.page.locator('#pe-neu').click();
  // Personalnummer (F147): vergibt kumero zufällig – das Feld ist gesperrt und nennt den Grund; akademischer Titel
  ist(await e.gesperrt('pe-f-nr', 'vergibt kumero') && await e.gesperrt('pe-f-nr-neu', 'Erst nach dem Anlegen'), 'Personalnummer und „neue vergeben“ gesperrt mit Grund');
  ist(await e.gesperrt('pe-f-zugang-neu', 'legt der Administrator an') && await e.page.locator('#pe-f-lohnnr').isEnabled(), '„Zugang anlegen“ ohne Amt gesperrt mit Grund; Feld für die Nummer beim Lohnbüro');
  await e.bild('01a-person-anlegen');
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
  // Kündigungsfrist (F148): gesetzlich vorbelegt; der Wortlaut ist nur bei „individuell“ frei
  ist(await e.page.locator('#pe-v-kuendigung-art').inputValue() === 'gesetzlich' && await e.gesperrt('pe-v-kuendigung', 'nur zu einer individuellen'), 'Kündigungsfrist: gesetzlich vorbelegt, Wortlaut gesperrt mit Grund');
  await e.page.locator('#pe-v-kuendigung-art').selectOption('individuell');
  ist(!(await e.page.locator('#pe-v-kuendigung').isDisabled()), 'individuell: der Wortlaut ist frei');
  await e.page.locator('#pe-v-kuendigung-art').selectOption('gesetzlich');
  // Vergütung und Sondervereinbarungen (F153): Wert und Monat sind gesperrt, solange kein Urlaubsgeld vereinbart ist
  ist(await e.gesperrt('pe-v-ug-wert', 'Nur, wenn es vereinbart ist') && !(await e.page.locator('#pe-v-geld-betrag').isDisabled()), 'Urlaubsgeld: Wert gesperrt mit Grund; der Betrag ist frei');
  await e.page.locator('#pe-v-arbeitszeit').fill('Gleitzeit, Kernzeit 9 bis 12 Uhr');
  await e.page.locator('#pe-v-geld-betrag').fill('2.850,00');
  await e.page.locator('#pe-v-ug-art').selectOption('betrag');
  await e.page.locator('#pe-v-ug-wert').fill('500');
  await e.page.locator('#pe-v-ug-monat').selectOption('6');
  await e.page.locator('#pe-v-wg-art').selectOption('prozent');
  await e.page.locator('#pe-v-wg-wert').fill('50');
  await e.bild('02a-vertrag-kuendigung');
  await e.page.locator('#pe-dialog-vertrag-senden').click();
  await e.meldung('Fassung angelegt.');
  await e.page.locator('#pe-vertraege [data-zustand="gilt"]').waitFor();
  const fassung = await e.page.locator('#pe-vertraege tbody tr').first().textContent();
  ist(fassung.includes('36,5 Std.') && fassung.includes('30 Tage') && fassung.includes('Musterpraxis') && fassung.includes('Praxisleitung'), 'Fassung in der Akte: Stunden, Urlaub, Buch, Funktion');
  ist(fassung.includes('gesetzlich – heute: 4 Wochen zum 15. oder zum Monatsende'), 'die Akte nennt die Kündigungsfrist, die heute gilt');
  ist(fassung.includes('2.850,00 € im Monat') && (await e.page.locator('#pe-vertraege tbody tr .pe-geld').first().getAttribute('title')) === 'Urlaubsgeld: 500,00 €, mit dem Lohn für Juni · Weihnachtsgeld: 50 % eines Monatsgehalts',
    'die Akte nennt die Vergütung; Urlaubs- und Weihnachtsgeld stehen am Zeichen');
  // Kündigungsregeln: die gesetzliche ist vorbelegt, ein Tarifvertrag lässt sich anlegen
  await e.page.locator('#pe-kuendigungsregeln').click();
  ist(await e.page.locator('#pe-k-grund').inputValue() === '4' && await e.page.locator('#pe-k-ab-6').inputValue() === '20' && await e.page.locator('#pe-k-monate-6').inputValue() === '7' && await e.gesperrt('pe-k-still', 'nicht stilllegen'), 'gesetzliche Regel: vier Wochen, Staffel bis 20 Jahre → 7 Monate; nicht stillzulegen');
  await e.bild('02b-kuendigungsregeln');
  await e.page.locator('#pe-k-wahl').selectOption('');
  ist(await e.gesperrt('pe-k-vorgabe', 'nur für die gesetzliche') && await e.page.locator('#pe-k-ab-0').inputValue() === '', 'neuer Tarifvertrag: leere Staffel, Vorgabe gesperrt mit Grund');
  await e.page.locator('#pe-k-name').fill('Haustarif Muster');
  await e.page.locator('#pe-k-grund').fill('6');
  await e.page.locator('#pe-k-ab-0').fill('3');
  await e.page.locator('#pe-k-monate-0').fill('3');
  await e.page.locator('#pe-dialog-kuendigung-senden').click();
  await e.meldung('Tarifvertrag angelegt.');
  await e.page.locator('#pe-vertrag').click();
  await e.page.locator('#pe-v-kuendigung-art').waitFor();
  ist((await e.page.locator('#pe-v-kuendigung-art option').allTextContents()).join('|') === 'gesetzlich (§ 622 BGB)|Tarifvertrag: Haustarif Muster|individuell – Wortlaut daneben', 'der Tarifvertrag steht am Vertrag zur Wahl');
  await e.page.locator('#pe-dialog-vertrag-abbrechen').click();
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
  ist(await e.page.locator('#pe-f-titel').isEnabled() && await e.page.locator('#pe-f-nr').inputValue() === '', 'Feld für den akademischen Titel; die Nummer ist leer, bis kumero sie vergibt');
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

  // ── Stufe 2: Abwesenheiten (F138) – Erika beantragt Urlaub, meldet sich krank und führt als Personal die Liste ──
  const bisMontag = ((8 - new Date().getDay()) % 7) + 7;   // ein Montag in ein bis zwei Wochen
  await e.menue('Meine Daten');
  await e.page.locator('#pm-urlaub').waitFor();
  ist((await e.page.locator('#pm-urlaub tbody tr').first().textContent()).includes('30') && await e.page.locator('#pm-keine-abwesenheit').count() === 1, 'Meine Daten: Urlaubskonto aus dem Vertrag, noch keine Abwesenheit');
  await e.page.locator('#pm-beantragen').click();
  ist(!(await e.page.locator('#pm-a-art option').allTextContents()).includes('Arbeitsunfähigkeit') && await e.page.locator('#pm-a-festgestellt').isDisabled(), 'Antrag: ohne AU; die Felder der Bescheinigung stehen da und sind gesperrt');
  await e.page.locator('#pm-a-art').selectOption({ label: 'Urlaub' });
  await e.page.locator('#pm-a-von').fill(tag(bisMontag));
  await e.page.locator('#pm-a-von').press('Tab');
  await e.page.locator('#pm-a-bis').fill(tag(bisMontag + 4));
  await e.page.locator('#pm-a-bis').press('Tab');
  ist(await e.page.locator('#pm-a-halb').isDisabled() && (await e.page.locator('#pm-a-hinweis').textContent()).includes('zählt gegen das Urlaubskonto'), 'mehrere Tage: „halber Tag“ gesperrt; die Art sagt, was sie bedeutet');
  await e.page.locator('#pm-a-bemerkung').fill('Familienfeier');
  await e.page.locator('#pm-dialog-abwesenheit-senden').click();
  await e.meldung('Antrag gestellt');
  await e.page.locator('#pm-abwesenheiten [data-zustand="abw-beantragt"]').waitFor();
  ist((await e.page.locator('#pm-abwesenheiten tbody tr').first().textContent()).includes('Urlaub') && (await e.page.locator('#pm-urlaub').textContent()).includes('5'), 'der Antrag steht da, fünf Arbeitstage beantragt');
  await e.page.locator('#pm-krank').click();
  ist(await e.page.locator('#pm-a-bemerkung').isDisabled() && (await e.page.locator('#pm-a-bemerkung').getAttribute('title')).includes('keine Angaben zur Erkrankung'), 'krank melden: keine Bemerkung möglich, mit Grund');
  await e.page.locator('#pm-a-unfall').check();
  await e.page.locator('#pm-dialog-abwesenheit-senden').click();
  await e.meldung('Gemeldet.');
  await e.page.locator('#pm-abwesenheiten tbody tr').filter({ hasText: 'Arbeitsunfähigkeit' }).filter({ hasText: 'Arbeitsunfall' }).waitFor();
  ist(await e.page.locator('#pm-abwesenheiten tbody tr').filter({ hasText: 'Arbeitsunfähigkeit' }).locator('.pm-zurueck').isDisabled(), 'was begonnen hat, lässt sich nicht selbst zurücknehmen – der Knopf steht da und ist gesperrt');
  await e.bild('5-meine-abwesenheiten');

  await e.menue('Abwesenheiten');
  await e.page.locator('#pa-tabelle tbody tr[data-id]').nth(1).waitFor();
  ist(await e.page.locator('#pa-tabelle thead details.sp-filter').count() === 7, 'Abwesenheiten: jede Spalte trägt Sortierung und Filter');
  ist(await e.gesperrt('pa-genehmigen', 'Erst eine Abwesenheit in der Liste wählen') && await e.gesperrt('pa-nachweis', 'Erst eine Abwesenheit'), 'Knöpfe gesperrt mit Grund');
  await e.page.getByRole('navigation').locator('a').filter({ hasText: 'Abwesenheiten' }).locator('.nav-zaehler').filter({ hasText: /^1$/ }).waitFor();
  ist(true, 'Zähler im Menü: ein offener Antrag');
  await e.page.locator('#pa-tabelle tbody tr').filter({ hasText: 'Urlaub' }).click();
  ist(await e.gesperrt('pa-nachweis', 'Nur bei einer Arbeitsunfähigkeit'), 'Nachweis nur bei einer AU');
  await e.page.locator('#pa-genehmigen').click();
  await e.page.locator('dialog[open]').getByRole('button', { name: 'Genehmigen' }).click();
  await e.meldung('Antrag genehmigt.');
  await e.page.locator('#pa-tabelle tr.gewaehlt [data-zustand="abw-genehmigt"]').waitFor();
  ist(await e.gesperrt('pa-genehmigen', 'Das ist kein offener Antrag'), 'entschieden: Genehmigen gesperrt mit Grund');
  // Doppelklick führt zur Bearbeitung (F150): „Ende ändern“, sonst „Arbeitstage“ oder der Nachweis – je nach Stand der Abwesenheit
  await e.page.locator('#pa-tabelle tbody tr').filter({ hasText: 'Arbeitsunfähigkeit' }).dblclick();
  await e.page.locator('dialog[open]').waitFor();
  ist(true, 'Doppelklick auf eine Abwesenheit öffnet ihre Bearbeitung');
  await e.page.locator('dialog[open]').getByRole('button', { name: 'Abbrechen' }).click();
  await e.page.locator('#pa-tabelle tbody tr').filter({ hasText: 'Arbeitsunfähigkeit' }).click();
  await e.page.locator('#pa-nachweis').click();
  await e.page.locator('dialog[open]').getByRole('button', { name: 'Liegt vor' }).click();
  await e.meldung('Nachweis vermerkt.');
  await e.page.locator('#pa-tabelle tr.gewaehlt').filter({ hasText: '✓ Nachweis liegt vor' }).waitFor();
  await e.page.locator('#pa-neu').click();
  await e.page.locator('#pa-f-person').selectOption({ label: 'Beispiel, Bert' });
  await e.page.locator('#pa-f-art').selectOption({ label: 'Elternzeit' });
  await e.page.locator('#pa-f-von').fill(tag(-3));
  await e.page.locator('#pa-f-von').press('Tab');
  await e.page.locator('#pa-f-bis').fill(tag(20));
  await e.page.locator('#pa-dialog-neu-senden').click();
  await e.meldung('Abwesenheit eingetragen.');
  await e.page.locator('#pa-tabelle tr.gewaehlt').filter({ hasText: 'Beispiel, Bert' }).filter({ hasText: '● läuft' }).waitFor();
  ist(await e.page.locator('#pa-kalender tr[data-person] td[data-abw="genehmigt"]').count() > 3, 'Kalender: wer fehlt wann');
  await e.bild('6-abwesenheiten');
  await e.page.locator('#pa-konto').click();
  await e.page.locator('#pa-k-person').selectOption({ label: 'Muster, Erika' });
  await e.page.locator('#pa-k-uebertrag').fill('2,5');
  await e.page.locator('#pa-dialog-konto-senden').click();
  await e.meldung('Urlaubskonto gespeichert.');
  const konto = await e.page.locator('#pa-konten tbody tr').filter({ hasText: 'Muster, Erika' }).textContent();
  ist(konto.includes('2,5') && konto.includes('27,5'), 'Urlaubskonten: Übertrag eingetragen, Rest gerechnet (30 + 2,5 − 5)');
  await e.bild('7-urlaubskonten');
  // Arbeitsort, Regelwerk der Feiertage, Urlaubsregel, Korrektur der Arbeitstage (0.7.46, F139)
  ist(await e.page.locator('#pa-leiste-eintrag button').count() === 7 && await e.page.locator('#pa-leiste-einrichtung button').count() === 6, 'zwei feste Leisten: der Eintrag und die Einrichtung');
  await e.page.locator('#pa-orte').click();
  await e.page.locator('#pa-o-name').fill('Augsburg');
  ist(await e.page.locator('#pa-o-f-allerheiligen').isChecked() && !(await e.page.locator('#pa-o-f-friedensfest').isChecked()) && await e.page.locator('#pa-o-standard').isChecked(), 'Arbeitsort: mit dem Land vorbelegt; der erste ist der des Mandanten');
  await e.page.locator('#pa-o-land').selectOption('SN');
  ist(!(await e.page.locator('#pa-o-f-allerheiligen').isChecked()) && await e.page.locator('#pa-o-f-buss_und_bettag').isChecked(), 'anderes Land: andere Vorbelegung');
  await e.page.locator('#pa-o-land').selectOption('BY');
  await e.page.locator('#pa-o-f-friedensfest').check();
  await e.bild('9-arbeitsort');
  await e.page.locator('#pa-dialog-orte-senden').click();
  await e.meldung('Arbeitsort gespeichert.');
  await e.page.locator('#pa-feiertage').click();
  ist(await e.page.locator('#pa-ft-tabelle tbody tr').count() === 21 && await e.page.locator('#pa-ft-laender-allerheiligen').inputValue() === 'BW, BY, NW, RP, SL', 'Regelwerk: zwanzig Feiertage und eine Zeile für einen eigenen');
  ist(await e.page.locator('#pa-ft-laender-neujahr').isDisabled() && await e.page.locator('#pa-ft-neu-tag').isEnabled(), 'Felder, die zur Art nicht gehören, sind gesperrt');
  await e.page.locator('#pa-ft-neu-name').fill('Praxisjubiläum');
  await e.page.locator('#pa-ft-neu-tag').fill('2');
  await e.page.locator('#pa-ft-neu-monat').fill('11');
  await e.page.locator('#pa-ft-neu-bundesweit').check();
  await e.bild('10-feiertage');
  await e.page.locator('#pa-dialog-feiertage-senden').click();
  await e.meldung('Regelwerk gespeichert.');
  await e.page.locator('#pa-feiertage').click();
  ist(await e.page.locator('#pa-ft-tabelle tbody tr').count() === 22, 'der eigene Feiertag steht im Regelwerk');
  await e.page.locator('#pa-ft-laender-allerheiligen').fill('BW');
  await e.page.locator('#pa-dialog-feiertage-senden').click();
  await e.meldung('Regelwerk gespeichert.');
  await e.page.locator('#pa-feiertage').click();
  ist(await e.page.locator('#pa-ft-laender-allerheiligen').inputValue() === 'BW', 'Regelwerk angepasst');
  await e.page.locator('#pa-ft-vorgabe').click();
  await e.meldung('Vorgabe eingelesen: 20 Feiertage');
  await e.page.locator('#pa-feiertage').click();
  ist(await e.page.locator('#pa-ft-laender-allerheiligen').inputValue() === 'BW, BY, NW, RP, SL' && await e.page.locator('#pa-ft-tabelle tbody tr').count() === 22, 'Vorgabe aus der Steuerdatei wieder da; der eigene Feiertag bleibt');
  await e.page.locator('#pa-dialog-feiertage-abbrechen').click();
  await e.page.locator('#pa-regel').click();
  ist((await e.page.locator('#pa-r-liste').textContent()).includes('Teilurlaub nach § 5 BUrlG') && await e.page.locator('#pa-r-tag').inputValue() === '31' && await e.page.locator('#pa-r-monat').inputValue() === '3', 'Urlaubsregel: Vorgabe nach dem Gesetz, Verfall am 31.03.');
  await e.page.locator('#pa-r-verfall').selectOption('nie');
  ist(await e.gesperrt('pa-r-tag', 'Ohne Verfall kein Stichtag'), 'ohne Verfall ist der Stichtag gesperrt, mit Grund');
  await e.page.locator('#pa-r-verfall').selectOption('selbst');
  await e.bild('11-urlaubsregel');
  await e.page.locator('#pa-dialog-regel-senden').click();
  await e.meldung('Urlaubsregel gespeichert.');
  await e.page.locator('#pa-regeltext').filter({ hasText: 'nach dem Stichtag von selbst' }).waitFor();
  ist(true, 'die geltende Regel steht unter den Urlaubskonten');
  await e.page.locator('#pa-tabelle tbody tr').filter({ hasText: 'Urlaub' }).click();
  await e.page.locator('#pa-tage').click();
  ist(await e.gesperrt('pa-g-tage', 'Wird neu gerechnet'), 'Arbeitstage: neu rechnen ist die Vorgabe');
  await e.page.locator('#pa-g-neu').uncheck();
  ist(await e.gesperrt('pa-g-folge', 'Nur bei einer Abwesenheit über den Jahreswechsel'), '„im Folgejahr“ gesperrt mit Grund');
  await e.page.locator('#pa-g-tage').fill('4,5');
  await e.page.locator('#pa-dialog-tage-senden').click();
  await e.meldung('Arbeitstage berichtigt.');
  await e.page.locator('#pa-tabelle tr.gewaehlt').filter({ hasText: '4,5 ✎' }).waitFor();
  ist(true, 'von Hand berichtigt und so gekennzeichnet');
  await e.bild('12-abwesenheiten-neu');
  await e.page.locator('#pa-frei').click();
  await e.page.locator('#pa-t-datum').fill(tag(bisMontag + 30));
  // Betriebsferien als Zeitraum: jeder Tag von–bis wird ein freier Tag
  await e.page.locator('#pa-t-bis').fill(tag(bisMontag + 32));
  await e.page.locator('#pa-t-name').fill('Betriebsferien');
  await e.page.locator('#pa-dialog-frei-senden').click();
  await e.meldung('Gespeichert.');
  // Jahresansicht einer Person: zwölf Monate, die freien Tage des Betriebs stehen als B darin
  await e.page.locator('#pa-reiter-jahr').click();
  await e.page.locator('#pa-jahr .jk-tabelle tbody tr').nth(11).waitFor();
  const imJahr = [30, 31, 32].filter((n) => { const x = new Date(); x.setDate(x.getDate() + bisMontag + n); return x.getFullYear() === new Date().getFullYear(); }).length;
  ist(await e.page.locator('#pa-jahr td[data-art="frei"]').count() === imJahr && (await e.page.locator('#pa-jahr-person option:checked').textContent()).includes('Muster, Erika'),
    'Jahresansicht: zwölf Monate der gewählten Person, Betriebsferien als freie Tage');
  ist(await e.page.locator('#pa-jahr td[data-abw]').count() > 0, 'die Abwesenheiten der Person stehen im Jahr');
  await e.bild('12a-jahresansicht');
  await e.page.locator('#pa-reiter-kalender').click();
  await e.page.locator('#pa-arten').click();
  ist(await e.page.locator('#pa-a-tabelle tbody tr').count() === 10 && await e.page.locator('#pa-a-tabelle input[aria-label="Arbeitsunfähigkeit: braucht Genehmigung"]').isDisabled(), 'Arten: neun vorbelegt und eine Zeile für eine neue; bei der AU steht die Genehmigung fest');
  await e.page.locator('#pa-dialog-arten-abbrechen').click();
  await e.menue('Personen');
  await e.page.locator('#pe-tabelle tbody tr').filter({ hasText: 'Muster, Erika (du)' }).click();
  await e.page.locator('#pe-abwesenheiten').filter({ hasText: 'Arbeitsunfähigkeit' }).waitFor();
  ist((await e.page.locator('#pe-urlaub').textContent()).includes('Übertrag 2,5') && (await e.page.locator('#pe-akte').textContent()).includes('Augsburg (wie der Mandant)'), 'die Akte zeigt Abwesenheiten, Urlaubskonto und Arbeitsort');

  // ── Stufe 3: Lohnvorbereitung (F140) – Erika führt Zuschläge und prüft; übergeben darf nur die Rolle „Lohn“ ──
  await e.menue('Lohnvorbereitung');
  await e.page.locator('#lo-tabelle tbody tr[data-id]').first().waitFor();
  ist(await e.gesperrt('lo-pruefen', 'Erst eine Person in der Liste wählen') && await e.gesperrt('lo-uebergeben', 'Rolle „Lohn“') && await e.gesperrt('lo-zuordnen', 'Es gibt noch keine Regel'), 'Lohnvorbereitung: Knöpfe gesperrt mit Grund');
  ist((await e.page.locator('#lo-keine-wahl').count()) === 1 && (await e.page.locator('#lo-liste .liste-fuss').textContent()).includes('offen'), 'Liste des Vormonats, Unterlage an festem Platz');
  await e.page.locator('#lo-regeln').click();
  ist(await e.page.locator('#lo-g-betrag').isDisabled() && await e.page.locator('#lo-g-zeitart').isDisabled() && !(await e.page.locator('#lo-g-satz').isDisabled()), 'Regel: Felder stehen alle da; was nicht passt, ist gesperrt');
  await e.page.locator('#lo-g-name').fill('Umsatzbeteiligung');
  await e.page.locator('#lo-g-ab').fill('01.01.2026');
  await e.page.locator('#lo-g-satz').fill('12,5');
  await e.page.locator('#lo-dialog-regeln-senden').click();
  await e.meldung('Gespeichert.');
  await e.page.locator('#lo-regeln').click();
  ist((await e.page.locator('#lo-g-tabelle tbody tr').first().textContent()).includes('12,5 % vom Betrag aller Leistungen'), 'die Regel steht mit ihrer Fassung in einem Satz da');
  await e.bild('13-zuschlagsregeln');
  await e.page.locator('#lo-dialog-regeln-abbrechen').click();
  await e.page.locator('#lo-zuordnen').click();
  await e.page.locator('#lo-o-funktion').fill('Praxisleitung');
  await e.page.locator('#lo-o-von').fill('01.01.2026');
  await e.page.locator('#lo-dialog-zuordnen-senden').click();
  await e.meldung('Gespeichert.');
  await e.page.locator('#lo-tabelle tbody tr').filter({ hasText: 'Muster, Erika' }).click();
  await e.page.locator('#lo-unterlage .lo-unterlage').waitFor();
  const unterlage = () => e.page.locator('#lo-unterlage').textContent();
  ist((await unterlage()).includes('Nachweis fehlt – keine bestätigten Leistungen') && (await unterlage()).includes('Sollstunden') && (await unterlage()).includes('Praxisleitung'), 'Unterlage: Vertrag, Soll und „Nachweis fehlt“ statt eines Betrags');
  await e.page.locator('#lo-zuschlag').click();
  await e.page.locator('#lo-z-betrag').fill('150');
  await e.page.locator('#lo-z-grund').fill('Zielerreichung');
  await e.page.locator('#lo-dialog-zuschlag-senden').click();
  await e.meldung('Zuschlag angelegt.');
  await e.page.locator('#lo-unterlage').filter({ hasText: '150,00 €' }).waitFor();
  ist((await unterlage()).includes('150,00 €') && (await e.page.locator('#lo-liste tr.gewaehlt').textContent()).includes('! Nachweis fehlt'), 'Zuschlag von Hand in Unterlage und Summe; Hinweis in der Liste');
  await e.page.locator('#lo-bemerkung').click();
  await e.page.locator('#lo-b-text').fill('Zielprämie laut Vereinbarung');
  await e.page.locator('#lo-dialog-bemerkung-senden').click();
  await e.meldung('Gespeichert.');
  await e.page.locator('#lo-liste tr.gewaehlt').filter({ hasText: 'Bemerkung' }).waitFor();
  await e.page.locator('#lo-pruefen').click();
  ist((await e.page.locator('dialog[open]').textContent()).includes('fehlt der Nachweis'), 'vor dem Prüfen: Hinweis auf den fehlenden Nachweis');
  await e.page.locator('dialog[open] .knopf.primaer').click();
  await e.meldung('Geprüft.');
  await e.page.locator('#lo-liste tr.gewaehlt').filter({ hasText: '▲ geprüft' }).waitFor();
  ist((await e.page.locator('#lo-liste tr.gewaehlt').textContent()).includes('▲ geprüft') && await e.gesperrt('lo-zuschlag', 'erst die Prüfung zurücknehmen') && await e.gesperrt('lo-uebergeben', 'Rolle „Lohn“'), 'geprüft: Zuschläge gesperrt, übergeben darf das Personal nicht');
  await e.bild('14-lohnvorbereitung');
  await e.page.evaluate(() => { window.print = () => {}; });
  await e.page.locator('#lo-sammel').click();
  ist(await e.page.locator('#lo-druck .lo-unterlage').count() >= 1 && await e.page.locator('#seite.lo-druckt').count() === 1, 'Sammelliste: je Person eine Unterlage im Druckbereich');
  await e.page.emulateMedia({ media: 'print' });
  ist(await e.page.locator('#lo-tabelle').isHidden() && await e.page.locator('#lo-druck .lo-unterlage').first().isVisible(), 'im Druck steht nur die Unterlage');
  await e.bild('15-lohn-druck');
  await e.page.emulateMedia({ media: 'screen' });
  await e.page.evaluate(() => window.dispatchEvent(new Event('afterprint')));
  ist(await e.page.locator('#seite.lo-druckt').count() === 0, 'nach dem Druck ist die Seite wie vorher');
  await e.page.locator('#lo-oeffnen').click();
  await e.meldung('Prüfung zurückgenommen.');

  // ── Stufe 4: Beurteilungen und Ziele (F141) – Erika führt sie als Personal; ihre eigene legt ein anderer an (hier: die Datenbank) ──
  const sql = (abfrage) => execFileSync('psql', ['-X', '-q', '-At', '-v', 'ON_ERROR_STOP=1', '-h', process.env.KUMERO_TEST_PGHOST, '-U', process.env.KUMERO_TEST_PGUSER, '-d', 'kumero_browserpraxis', '-c', abfrage], { encoding: 'utf8' }).trim();
  await e.menue('Beurteilungen und Ziele');
  await e.page.locator('#be-leer').waitFor();
  ist(await e.gesperrt('be-neu', 'Es gibt noch keine Vorlage') && await e.gesperrt('be-einschaetzen', 'Erst eine Beurteilung') && await e.gesperrt('be-ziel', 'Reiter „Ziele“'), 'Beurteilungen: Knöpfe gesperrt mit Grund');
  await e.page.locator('#be-vorlagen').click();
  ist(await e.page.locator('#be-v-still').isDisabled(), 'eine neue Vorlage ist nicht stillgelegt');
  await e.page.locator('#be-v-name').fill('Jahresgespräch');
  await e.page.locator('#be-v-niedrig').fill('trifft nicht zu');
  await e.page.locator('#be-v-hoch').fill('trifft voll zu');
  await e.page.locator('#be-v-kriterien').fill('Fachkenntnis | Wissen und Können im eigenen Gebiet\nZusammenarbeit');
  await e.page.locator('#be-dialog-vorlagen-senden').click();
  await e.meldung('Vorlage gespeichert.');
  await e.page.locator('#be-neu:not([disabled])').waitFor();
  await e.page.locator('#be-neu').click();
  ist((await e.page.locator('#be-n-person option').allTextContents()).join() === 'Beispiel, Bert' && (await e.page.locator('#be-n-vorlage').textContent()).includes('2 Kriterien'), 'anlegen für andere, nie für sich selbst');
  await e.page.locator('#be-n-beurteiler').selectOption('');
  await e.page.locator('#be-dialog-neu-senden').click();
  await e.meldung('Beurteilung angelegt.');
  await e.page.locator('#be-einzel .be-ansicht').waitFor();
  ist((await e.page.locator('#be-liste tr.gewaehlt').textContent()).includes('Selbsteinschätzung fehlt · Einschätzung fehlt') && await e.gesperrt('be-abschliessen', 'Erst die Einschätzung abgeben'), 'neue Beurteilung gewählt; abschließen noch gesperrt');
  await e.page.locator('#be-einschaetzen').click();
  await e.page.locator('#be-dialog-einschaetzung-senden').click();
  ist(await e.page.locator('#be-dialog-einschaetzung[open]').count() === 1, 'ohne Werte bleibt der Dialog offen');
  const wahlfelder = e.page.locator('#be-dialog-einschaetzung select');
  await wahlfelder.nth(0).selectOption('5');
  await wahlfelder.nth(1).selectOption('3');
  await e.page.locator('#be-dialog-einschaetzung input[type="text"]').nth(0).fill('Sicher in allen Abläufen');
  await e.page.locator('#be-e-gesamt').fill('Verlässlich und fachlich stark');
  await e.page.locator('#be-dialog-einschaetzung-senden').click();
  await e.meldung('Einschätzung abgegeben.');
  await e.page.locator('#be-einzel').filter({ hasText: 'Sicher in allen Abläufen' }).waitFor();
  await e.page.locator('#be-abschliessen').click();
  ist((await e.page.locator('#be-dialog-abschluss').textContent()).includes('keine Selbsteinschätzung abgegeben'), 'Abschluss ohne Selbsteinschätzung: Hinweis');
  await e.page.locator('#be-a-ergebnis').fill('Gemeinsames Bild; Schwerpunkt Schulungen');
  await e.page.locator('#be-dialog-abschluss-senden').click();
  await e.meldung('Beurteilung abgeschlossen.');
  await e.page.locator('#be-liste tr.gewaehlt').filter({ hasText: 'Kenntnisnahme fehlt' }).waitFor();
  ist(await e.gesperrt('be-einschaetzen', 'abgeschlossen oder aufgehoben') && (await e.page.locator('#be-einzel').textContent()).includes('Gemeinsames Bild'), 'abgeschlossen: Ergebnis steht da, nichts ändert sich mehr');
  await e.bild('16-beurteilung');
  await e.page.locator('#be-reiter-ziele').click();
  ist(await e.gesperrt('be-neu', 'Reiter „Beurteilungen“') && await e.gesperrt('be-stand', 'Erst ein Ziel'), 'im Reiter Ziele gelten die Knöpfe der Ziele');
  await e.page.locator('#be-ziel').click();
  await e.page.locator('#be-z-titel').fill('Hygieneschulung leiten');
  await e.page.locator('#be-z-frist').fill(tag(120));
  await e.page.locator('#be-dialog-ziel-senden').click();
  await e.meldung('Ziel vorgeschlagen.');
  await e.page.locator('#be-liste tr.gewaehlt').filter({ hasText: 'Bestätigung fehlt' }).waitFor();
  ist(await e.gesperrt('be-bewerten', 'noch nicht bestätigt'), 'bewertet wird erst ein vereinbartes Ziel');
  await e.bild('17-ziele');
  // Erikas eigene Beurteilung und ihr Ziel: von jemand anderem angelegt und eingeschätzt
  const erikaId = sql("SELECT id FROM personal.person WHERE familienname = 'Muster'");
  const eigene = sql(`SELECT personal.beurteilung_anlegen('system:test', '${erikaId}', (SELECT id FROM personal.beurteilungsvorlage LIMIT 1), '2025-01-01', '2025-12-31', NULL)`);
  sql(`SELECT personal.ziel_anlegen('system:test', '${erikaId}', '{"titel": "Praxishandbuch überarbeiten", "frist": "2027-06-30"}')`);
  await e.menue('Beurteilungen und Ziele');
  await e.page.locator('#be-tabelle tbody tr').filter({ hasText: 'Muster, Erika (du)' }).click();
  ist(await e.gesperrt('be-einschaetzen', 'deine eigene'), 'über die eigene Beurteilung entscheidet auch das Personal nicht');
  await e.menue('Meine Daten');
  await e.page.locator('#pm-beurteilungen details[open]').waitFor();
  ist(await e.page.locator('#pm-beurteilungen .pm-kenntnis').isDisabled() && (await e.page.locator('#pm-beurteilungen').textContent()).includes('noch nicht abgegeben'), 'Meine Beurteilung: offen, Kenntnisnahme gesperrt');
  await e.page.locator('#pm-beurteilungen .pm-selbst').click();
  await e.page.locator('#pm-dialog-selbst select').nth(0).selectOption('4');
  await e.page.locator('#pm-dialog-selbst select').nth(1).selectOption('5');
  await e.page.locator('#pm-dialog-selbst-senden').click();
  await e.meldung('Selbsteinschätzung abgegeben.');
  const kennungen = sql(`SELECT string_agg(k->>'kennung', ',') FROM personal.beurteilung b, jsonb_array_elements(b.kriterien) k WHERE b.id = '${eigene}'`).split(',');
  sql(`SELECT personal.beurteilung_einschaetzen('system:test', '${eigene}', false, '{"werte": {"${kennungen[0]}": 3, "${kennungen[1]}": 4}, "gesamt": "Solide"}')`);
  sql(`SELECT personal.beurteilung_abschliessen('system:test', '${eigene}', current_date, 'Einvernehmlich besprochen')`);
  await e.menue('Personen');
  await e.menue('Meine Daten');
  await e.page.locator('#pm-beurteilungen').filter({ hasText: 'Einvernehmlich besprochen' }).waitFor();
  ist((await e.page.locator('#pm-beurteilungen').textContent()).includes('Solide') && await e.page.locator('#pm-beurteilungen .pm-selbst').isDisabled(), 'nach dem Abschluss: Einschätzung des Beurteilers zu lesen, Selbsteinschätzung gesperrt');
  await e.page.locator('#pm-beurteilungen .pm-kenntnis').click();
  await e.page.locator('#pm-k-text').fill('Bei der Zusammenarbeit sehe ich mich anders');
  await e.page.locator('#pm-dialog-kenntnis-senden').click();
  await e.meldung('Zur Kenntnis genommen.');
  await e.page.locator('#pm-beurteilungen').filter({ hasText: 'sehe ich mich anders' }).waitFor();
  ist(await e.page.locator('#pm-ziele .pm-ziel-stand').isDisabled(), 'Stand melden erst nach der Bestätigung');
  await e.page.locator('#pm-ziele .pm-ziel-ja').click();
  await e.page.locator('dialog[open] .knopf.primaer').click();
  await e.meldung('Ziel vereinbart.');
  await e.page.locator('#pm-ziele').filter({ hasText: 'vereinbart · 0 %' }).waitFor();
  await e.page.locator('#pm-ziele .pm-ziel-stand').click();
  await e.page.locator('#pm-s-stand').fill('40');
  await e.page.locator('#pm-s-text').fill('Gliederung steht');
  await e.page.locator('#pm-dialog-stand-senden').click();
  await e.meldung('Stand gemeldet.');
  await e.page.locator('#pm-ziele').filter({ hasText: 'vereinbart · 40 %' }).waitFor();
  ist(await e.page.locator('#pm-ziele .pm-ziel-ja').isDisabled() && (await e.page.locator('#pm-ziele').textContent()).includes('Gliederung steht'), 'Ziel vereinbart, Stand gemeldet');
  await e.bild('18-meine-beurteilung');

  // ── Zeiterfassung (F145): eigenes Modul, „Meine Zeiten“ unter „Meine Daten“; Jahreskalender im Personal ──
  await e.menue('Meine Daten');
  await e.page.locator('#pm-kalender .jk-tabelle').waitFor();
  // die Seite lädt neu: auf den neunten Feiertag und einen Brückentag warten, nicht auf den alten Stand zählen
  await e.page.locator('#pm-kalender td[data-art="feiertag"]').nth(8).waitFor();
  await e.page.locator('#pm-kalender td[data-art="bruecke"]').first().waitFor();
  // warten, bis der neu geladene Stand steht – zwischen altem und neuem Stand ist der Kalender kurz leer
  await e.page.waitForFunction(() => document.querySelectorAll('#pm-kalender td[data-art="feiertag"]').length >= 9 && document.querySelectorAll('#pm-kalender td[data-art="bruecke"]').length >= 1);
  ist(true, 'Jahreskalender: Feiertage und Brückentage');
  ist((await e.page.locator('#pm-kalender td[data-tag$="-01-01"]').getAttribute('title')).includes('Neujahr') && await e.page.locator('#pm-kalender td[data-abw]').count() >= 1, 'Feiertag mit Namen; eigene Abwesenheiten im Kalender');
  await e.page.locator('#pm-kalender').scrollIntoViewIfNeeded();
  await e.bild('19-jahreskalender');
  await e.menue('Meine Zeiten');
  await e.page.locator('#zm-jetzt').waitFor();
  ist((await e.page.locator('#zm-jetzt').textContent()).includes('Nicht eingestempelt') && await e.gesperrt('zm-gehen', 'nicht eingestempelt') && await e.gesperrt('zm-monat', 'erst, wenn er vorbei ist'), 'Meine Zeiten: nicht eingestempelt, Knöpfe gesperrt mit Grund');
  // mobiles Arbeiten wird gesondert erfasst (F151): die Wahl gilt für den Abschnitt, der mit „Kommen“ beginnt
  await e.page.locator('#zm-ort').selectOption('mobil');
  await e.page.locator('#zm-kommen').click();
  await e.meldung('Gekommen.');
  await e.page.locator('#zm-jetzt').filter({ hasText: 'Eingestempelt seit' }).waitFor();
  ist(await e.gesperrt('zm-kommen', 'eingestempelt'), 'eingestempelt: Kommen gesperrt mit Grund');
  ist((await e.page.locator('#zm-jetzt').textContent()).includes('(mobil)') && await e.gesperrt('zm-ort', 'steht mit dem Kommen fest'), 'der Kopf nennt mobiles Arbeiten; die Wahl ist gesperrt mit Grund');
  ist((await e.page.locator('#zm-laeuft').textContent()).includes('läuft seit 0:0'), 'der Kopf zeigt die angelaufene Arbeitszeit – gerechnet im Browser');
  sql("UPDATE zeit.abschnitt SET von = '00:00' WHERE bis IS NULL");
  await e.page.locator('#zm-gehen').click();
  await e.meldung('Gegangen.');
  await e.page.locator('#zm-jetzt').filter({ hasText: 'Nicht eingestempelt' }).waitFor();
  ist(await e.page.locator('#zm-blatt tr.ze-heute').count() === 1 && (await e.page.locator('#zm-blatt tr.ze-heute').textContent()).includes('00:00–'), 'der Abschnitt von heute steht im Monatsblatt');
  ist(await e.page.locator('#zm-blatt th details[data-spalte="tag"]').count() === 1 && await e.page.locator('#zm-blatt tfoot tr.ze-summe').count() === 1, 'das Monatsblatt ist eine Tabelle mit Sortierung und Filter am Spaltenkopf; die Summe steht im Fuß');
  // Doppelklick auf den Tag öffnet seine Einzelheiten: die Anmerkung je Abschnitt (F150)
  await e.page.locator('#zm-blatt tr.ze-heute').dblclick();
  await e.page.locator('#zm-dialog-tag input[type="text"]').first().fill('Inventur im Lager');
  await e.page.locator('#zm-dialog-tag-senden').click();
  await e.meldung('Anmerkung gespeichert.');
  await e.page.locator('#zm-blatt tr.ze-heute td.ze-anmerkung').filter({ hasText: 'Inventur im Lager' }).waitFor();
  ist(await e.page.locator('#zm-blatt th details[data-spalte="anmerkung"]').count() === 1, 'die Anmerkung steht in ihrer Spalte');
  await e.page.locator('#zm-antrag').click();
  ist(await e.page.locator('#zm-a-abschnitt').isDisabled() && !(await e.page.locator('#zm-a-tag').isDisabled()), 'Nachtrag: der Tag ist frei, der Abschnitt gesperrt');
  await e.page.locator('#zm-a-art').selectOption('korrektur');
  ist(await e.page.locator('#zm-a-tag').isDisabled() && !(await e.page.locator('#zm-a-abschnitt').isDisabled()), 'Korrektur: umgekehrt');
  ist((await e.page.locator('#zm-dialog-antrag label, #zm-dialog-antrag .feld').allTextContents()).join(' ').includes('Kommen') && await e.page.locator('#zm-a-ort').inputValue() === '', 'das Formular nennt die Ereignisse Kommen und Gehen; bei einer Korrektur bleibt der Ort „wie bisher“');
  await e.page.locator('#zm-a-von').fill('07:00');
  await e.page.locator('#zm-a-bis').fill('15:30');
  await e.page.locator('#zm-a-grund').fill('Stempeln am Empfang vergessen');
  await e.bild('20a-antrag');
  await e.page.locator('#zm-dialog-antrag-senden').click();
  await e.meldung('Gesendet – wartet auf die Freigabe.');
  await e.page.locator('#zm-antraege').filter({ hasText: 'wartet auf die Freigabe' }).waitFor();
  await e.bild('20-meine-zeiten');
  await e.menue('Zeiten');
  await e.page.locator('#ze-tabelle tbody tr[data-id]').first().waitFor();
  ist(await e.gesperrt('ze-antrag', 'Erst eine Person') && await e.gesperrt('ze-monat-frei', 'Erst eine Person') && !(await e.page.locator('#ze-regeln').isDisabled()), 'Zeiten: Knöpfe gesperrt mit Grund');
  ist((await e.page.locator('#ze-stand').textContent()).includes('über 6:00 Std. 30 Min.'), 'die Regeln des Mandanten stehen da');
  ist((await e.page.locator('#ze-wo').textContent()).includes('Kommen und Gehen: unter „Meine Zeiten“'), 'die Übersicht sagt, wo gestempelt wird');
  ist((await e.page.locator('#ze-tabelle tbody tr').filter({ hasText: 'Beispiel, Bert' }).count()) === 1, 'auch wer keinen Vertrag hat, steht in der Liste');
  await e.page.locator('#ze-tabelle tbody tr').filter({ hasText: 'Muster, Erika (du)' }).click();
  await e.page.locator('#ze-offene').waitFor();
  ist((await e.page.locator('#ze-liste tr.gewaehlt').textContent()).includes('1 Antrag') && (await e.page.locator('#ze-offene').textContent()).includes('Kommen 07:00 · Gehen 15:30'), 'der offene Antrag steht in Liste und Blatt');
  await e.page.locator('#ze-antrag').click();
  await e.page.locator('#ze-dialog-antrag-senden').click();
  await e.meldung('Antrag angenommen.');
  await e.page.locator('#ze-blatt tr.ze-heute').filter({ hasText: '07:00–15:30 (mobil) ✎' }).waitFor();
  // was zählt, hängt am Wochentag des Laufs (außerhalb der Regelarbeitstage erst mit Genehmigung) – geprüft wird, was immer gilt
  ist((await e.page.locator('#ze-blatt tr.ze-heute').textContent()).includes('8:30') && (await e.page.locator('#ze-blatt tr.ze-heute').textContent()).includes('0:30 *'), 'übernommen: 8:30 Arbeit, 30 Minuten Pause nach der Regel abgezogen');
  // Doppelklick auf einen Tag, der eine Genehmigung braucht, führt zur Genehmigung (F150) – ob heute einer ist, hängt am Wochentag des Laufs
  if ((await e.page.locator('#ze-blatt tr.ze-heute').textContent()).includes('Genehmigung fehlt')) {
    await e.page.locator('#ze-blatt tr.ze-heute').dblclick();
    await e.page.locator('#ze-dialog-tag').waitFor();
    ist((await e.page.locator('#ze-t-welcher option:checked').textContent()).includes('Arbeit außerhalb der Regelarbeitstage'), 'Doppelklick auf den Tag öffnet seine Genehmigung');
    await e.page.locator('#ze-dialog-tag-abbrechen').click();
  }
  await e.page.locator('#ze-profil').click();
  ist(await e.page.locator('#ze-p-rahmen-von').isDisabled() && await e.page.locator('#ze-p-tag-0').isDisabled(), 'Zeitprofil: Rahmen nur bei Gleitzeit, eigene Tage erst mit Haken');
  await e.page.locator('#ze-p-modell').selectOption('gleitzeit');
  await e.page.locator('#ze-p-korrektur').selectOption('sofort');
  await e.page.locator('#ze-p-anfang').fill('12:30');
  await e.page.locator('#ze-dialog-profil-senden').click();
  await e.meldung('Zeitprofil gespeichert.');
  await e.page.locator('#ze-liste tr.gewaehlt').filter({ hasText: 'Gleitzeit' }).waitFor();
  await e.bild('21-zeiten');
  await e.page.locator('#ze-regeln').click();
  ist(await e.page.locator('#ze-r-ab-0').inputValue() === '6:00' && await e.page.locator('#ze-r-pause-1').inputValue() === '45', 'Regeln: Pausen nach der Vorgabe');
  await e.page.locator('#ze-r-abschluss').selectOption('aus');
  await e.page.locator('#ze-dialog-regeln-senden').click();
  await e.meldung('Regeln gespeichert.');
  await e.page.locator('#ze-stand').filter({ hasText: 'Monatsabschluss: aus' }).waitFor();
  ist(true, 'Monatsabschluss ausgeschaltet');

  // ── Leistungen (F154): Katalog, eintragen, bestätigen, löschen – eigenes Modul am Mandanten ──
  await e.menue('Leistungen');
  await e.page.locator('#le-leer').waitFor();
  ist(await e.gesperrt('le-bestaetigen', 'Erst eine Leistung') && await e.gesperrt('le-neu', 'Der Katalog ist leer') && !(await e.page.locator('#le-katalog').isDisabled()), 'Leistungen: Knöpfe gesperrt mit Grund; das Personal pflegt den Katalog');
  await e.page.locator('#le-katalog').click();
  await e.page.locator('#le-k-bezeichnung').fill('Massage 30 Minuten');
  await e.page.locator('#le-k-gruppe').fill('Therapie');
  await e.page.locator('#le-k-preis').fill('40,00');
  await e.bild('22-katalog');
  await e.page.locator('#le-dialog-katalog-senden').click();
  await e.meldung('Katalog gespeichert.');
  await e.menue('Meine Leistungen');
  await e.page.locator('#lm-keine').waitFor();
  ist(await e.gesperrt('lm-loeschen', 'Erst eine Leistung'), 'Meine Leistungen: Löschen gesperrt mit Grund');
  await e.page.locator('#lm-neu').click();
  ist(await e.page.locator('#lm-f-einzel').inputValue() === '40,00' && await e.gesperrt('lm-f-einzel', 'aus dem Katalog') && (await e.page.locator('#lm-f-betrag').textContent()).includes('40,00'), 'der Preis kommt aus dem Katalog; der Betrag rechnet mit');
  await e.page.locator('#lm-f-menge').fill('3');
  ist((await e.page.locator('#lm-f-betrag').textContent()).includes('120,00'), 'Menge × Preis');
  await e.page.locator('#lm-f-vorgang').fill('V-1001');
  await e.bild('23-leistung-eintragen');
  await e.page.locator('#lm-dialog-neu-senden').click();
  await e.meldung('Leistung eingetragen – wartet auf die Bestätigung.');
  await e.page.locator('#lm-tabelle tr.gewaehlt').filter({ hasText: 'wartet auf die Bestätigung' }).waitFor();
  ist((await e.page.locator('#lm-summe').textContent()).includes('120,00 € · davon bestätigt 0,00 €'), 'Summe des Monats; bestätigt ist noch nichts');
  await e.menue('Leistungen');
  await e.page.locator('#le-tabelle tbody tr[data-id]').first().click();
  ist(!(await e.page.locator('#le-bestaetigen').isDisabled()) && (await e.page.locator('#le-stand').textContent()).includes('Eine wartet auf die Bestätigung'), 'wer das Personal führt, bestätigt auch die eigene Leistung');
  await e.page.locator('#le-bestaetigen').click();
  await e.meldung('Leistung bestätigt.');
  await e.page.locator('#le-tabelle tr.gewaehlt').filter({ hasText: '✓ bestätigt' }).waitFor();
  ist(await e.gesperrt('le-bestaetigen', 'Schon bestätigt') && (await e.page.locator('#le-summe').textContent()).includes('davon bestätigt 120,00 €'), 'bestätigt: zählt für den Zuschlag');
  // Auswertung (F155): Summen je Person und Gruppe, Gliederung umschaltbar, Druck und CSV
  ist((await e.page.locator('#le-aw-tabelle tbody tr').first().textContent()).includes('Therapie') && (await e.page.locator('#le-aw-tabelle tfoot').textContent()).includes('120,00'), 'Auswertung: Umsatz je Person und Gruppe mit Summe');
  await e.page.locator('#le-gliederung').selectOption('leistung');
  ist((await e.page.locator('#le-aw-tabelle tbody tr').first().textContent()).includes('Massage 30 Minuten') && !(await e.page.locator('#le-csv').isDisabled()) && !(await e.page.locator('#le-druck').isDisabled()), 'Gliederung je Leistung; Drucken und CSV stehen bereit');
  await e.bild('24-leistungen');
  await e.menue('Meine Leistungen');
  await e.page.locator('#lm-tabelle tbody tr[data-id]').first().click();
  ist(await e.gesperrt('lm-loeschen', 'Bestätigt – löschen kann sie dein Vorgesetzter'), 'eine bestätigte Leistung löscht die Person nicht mehr selbst');

  // Abgelaufene Sitzung (0.7.44): Die Seite bleibt stehen, darüber liegt die Anmeldung; danach geht es an derselben Stelle weiter
  await e.page.context().clearCookies();
  await e.menue('Fristen und Nachweise');
  await e.page.locator('#sitzung-abgelaufen').waitFor();
  await e.bild('8-sitzung');
  ist(await e.page.locator('#navigation').isVisible() && (await e.page.locator('#sitzung-abgelaufen').textContent()).includes('Sitzung ist abgelaufen'), 'ohne Sitzung: Hinweis mit Anmeldung, der Rahmen bleibt stehen');
  await e.page.locator('#sitzung-anmelden').click();
  await e.page.locator('#sitzung-abgelaufen').waitFor({ state: 'detached' });
  await e.page.locator('#pf-tabelle').waitFor();
  ist((await e.page.locator('#seite h1').textContent()).includes('Fristen und Nachweise'), 'nach der Anmeldung steht die gewünschte Seite da, mit Inhalt');
  // mit Eingaben auf der Seite: sie bleibt stehen, nichts geht verloren
  await e.menue('Personen');
  await e.page.locator('#pe-tabelle tbody tr[data-id]').first().waitFor();
  await e.page.locator('#pe-suche').fill('muster');
  await e.page.context().clearCookies();
  await e.page.locator('#pe-tabelle tbody tr[data-id]').first().click();
  await e.page.locator('#sitzung-anmelden').click();
  await e.meldung('Wieder angemeldet. Deine Eingaben stehen noch da');
  ist(await e.page.locator('#pe-suche').inputValue() === 'muster', 'Eingaben auf der Seite überstehen das neue Anmelden');
  await e.page.locator('#pe-tabelle tbody tr[data-id]').first().click();
  await e.page.locator('#pe-akte-titel').filter({ hasText: 'Muster, Erika' }).waitFor();
  ist(true, 'der wiederholte Schritt gelingt');

  // Adam (Administrator): Rollen am Mandanten in eigener Spalte und in derselben Auswahl; vom Personal sieht er nichts
  const a = await person();
  await a.anmelden(umgebung.administrator);
  ist(!(await a.page.getByRole('navigation').textContent()).includes('Meine Daten'), 'der Administrator hat keinen Eintrag des Personals');
  await a.menue('Nutzer und Rollen');
  await a.page.locator('#nu-liste tbody tr').nth(1).waitFor();
  ist((await a.page.locator('#nu-liste thead').textContent()).includes('Rollen am Mandanten'), 'Spalte „Rollen am Mandanten“');
  await a.page.locator('#nu-liste tbody tr').filter({ hasText: 'Muster, Erika' }).click();
  ist((await a.page.locator('#nu-liste tr.gewaehlt .nu-rollen-mandant').textContent()) === 'Leistungen, Mitarbeiter, Personal, Zeiterfassung', 'Erikas Rollen am Mandanten');
  await a.page.locator('#nu-rolle').click();
  const wahl = await a.page.locator('#nu-f-rolle option').allTextContents();
  ist(wahl.includes('Lohn – am Mandanten') && wahl.includes('Buchhalter – im Buch'), 'die Auswahl sagt je Rolle, wo sie gilt');
  await a.page.locator('#nu-f-rolle').selectOption({ label: 'Lohn – am Mandanten' });
  await a.page.locator('#nu-dialog-rolle-senden').click();
  await a.meldung('Rolle Lohn zugewiesen.');
  await a.page.locator('#nu-liste tr.gewaehlt .nu-rollen-mandant').filter({ hasText: 'Leistungen, Lohn, Mitarbeiter, Personal, Zeiterfassung' }).waitFor();
  await a.page.locator('#nu-rolle-ende').click();
  await a.page.locator('#nu-dialog-rolle-ende label').filter({ hasText: 'Lohn – am Mandanten' }).locator('input').check();
  await a.page.locator('#nu-dialog-rolle-ende-senden').click();
  await a.meldung('Rolle beendet.');
  await a.page.locator('#nu-liste tr.gewaehlt .nu-rollen-mandant').filter({ hasText: /^Leistungen, Mitarbeiter, Personal, Zeiterfassung$/ }).waitFor();
  ist(true, 'Rolle am Mandanten zugewiesen und beendet');
  await a.bild('4-rollen');
  // Zugang anlegen im Dialog der Person (F149): nur der Administrator – Adam bekommt dafür die Rolle Personal
  sql("SELECT mandant.zuweisen('system:test', 'nutzer:' || (SELECT id FROM mandant.nutzer WHERE vornamen = 'Adam'), 'Personal')");
  await a.page.reload();
  await a.menue('Personen');
  await a.page.locator('#pe-tabelle tbody tr').filter({ hasText: 'Beispiel, Bert' }).click();
  await a.page.locator('#pe-akte').waitFor();
  await a.page.locator('#pe-aendern').click();
  await a.page.locator('#pe-f-zugang-neu').waitFor();
  ist(!(await a.page.locator('#pe-f-zugang-neu').isDisabled()), 'der Administrator kann im Dialog der Person einen Zugang anlegen');
  // der Knopf merkt nur vor – angelegt wird mit „Speichern“, „ohne Zugang“ nimmt es zurück (F150)
  await a.page.locator('#pe-f-zugang-neu').click();
  ist(await a.page.locator('#pe-f-nutzer').inputValue() === '__neu__' && await a.gesperrt('pe-f-zugang-neu', 'Vorgemerkt'), 'vorgemerkt: „neuer Zugang“ steht in der Auswahl');
  await a.page.locator('#pe-f-nutzer').selectOption('');
  ist(!(await a.page.locator('#pe-f-zugang-neu').isDisabled()), '„ohne Zugang“ nimmt die Vormerkung zurück');
  await a.page.locator('#pe-f-zugang-neu').click();
  await a.page.locator('#pe-f-email').fill('');
  await a.page.locator('#pe-dialog-person-senden').click();
  await a.page.locator('#pe-dialog-person-fehler').filter({ hasText: 'braucht es eine E-Mail-Adresse' }).waitFor();
  await a.page.locator('#pe-f-email').fill('bert@beispiel.example');
  await a.bild('5-zugang-vorgemerkt');
  await a.page.locator('#pe-dialog-person-senden').click();
  await a.page.locator('#pe-dialog-zugang').waitFor();
  ist((await a.page.locator('#pe-zugang-link').inputValue()).includes('/') && /^[A-Za-z0-9]{3} [A-Za-z0-9]{3}$/.test(await a.page.locator('#pe-zugang-code').textContent()), 'nach dem Speichern: Link und Code des neuen Zugangs – einmal gezeigt');
  await a.page.locator('#pe-zugang-schliessen').click();
  await a.page.locator('#pe-akte').filter({ hasText: 'verknüpft' }).waitFor();
  // Doppelklick auf die Zeile öffnet die Einzelheiten (F150)
  await a.page.locator('#pe-tabelle tbody tr').filter({ hasText: 'Beispiel, Bert' }).dblclick();
  await a.page.locator('#pe-dialog-person').waitFor();
  ist(await a.page.locator('#pe-f-nutzer option:checked').textContent() === 'Bert Beispiel' && await a.gesperrt('pe-f-zugang-neu', 'hat schon einen Zugang'), 'Doppelklick öffnet „Bearbeiten“; der Zugang ist verknüpft');
  await a.page.locator('#pe-dialog-person-abbrechen').click();
  ist(true, 'Person mit dem neuen Zugang gespeichert');
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
