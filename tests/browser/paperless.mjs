// SPDX-License-Identifier: AGPL-3.0-or-later
// Browsertest des Moduls paperless (PAPERLESS.md, F92) gegen den nachgebauten Paperless-Server: Zugang des Nutzers,
// Entsperren, Tags, Arbeitsvorrat aus Paperless, geprüfter Beleg und Vorschau in Buchungsmaske und Buchungsliste.
//
//   … node tests/browser/paperless.mjs [Bildverzeichnis]   (Umgebung wie tests/browser/buchen.mjs)
import { spawn, execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { mkdirSync, mkdtempSync, rmSync, readFileSync } from 'node:fs';
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

const umgebung = JSON.parse(execFileSync('php', [path.join(wurzel, 'tests/browser/aufbau.php'), ADRESSE, '--buchen', '--vorrat', '--paperless', '--import'], { encoding: 'utf8' }).trim().split('\n').pop());
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

  // Zuordnung Paperless-Feld → Buchung (F95): Vorschlag aus den Feldnamen, als Vorgabe des Mandanten gespeichert
  await page.locator('[data-pl=zuordnung]').click();
  await page.locator('#pl-zuordnung-stand').filter({ hasText: 'Noch keine Zuordnung' }).waitFor();
  ist(await page.locator('select[data-ziel=gegenpartei][data-stelle="0"]').inputValue() === '["fest","korrespondent"]', 'ohne Zuordnung gilt der Korrespondent');
  await page.locator('[data-pl=zuordnung-vorschlag]').click();
  await page.waitForFunction(() => document.querySelector('select[data-ziel=belegnummer][data-stelle="0"]')?.value === '["feld","Erkannt: Rechnungsnummer"]');
  ist(await page.locator('select[data-ziel=gegenpartei][data-stelle="1"]').inputValue() === '["fest","korrespondent"]', 'Vorschlag: Feld zuerst, Korrespondent als zweite Quelle');
  ist(await page.locator('select[data-ziel=netto][data-stelle="0"]').inputValue() === '["feld","Erkannt: Betrag netto"]', 'Netto vorgeschlagen');
  await page.locator('select[data-ziel=zahlweise][data-stelle="0"]').selectOption('["feld","Zahlart"]');
  await page.locator('select[data-tag="richtung:ausgabe"]').selectOption('zu buchen');
  await bild('2a-zuordnung');
  await page.locator('[data-pl=zuordnung-mandant]').click();
  await meldung('Vorgabe des Mandanten gespeichert.');
  await page.locator('#pl-zuordnung-stand').filter({ hasText: 'Es gilt die Vorgabe des Mandanten.' }).waitFor();
  ist(await page.locator('select[data-tag="richtung:ausgabe"]').inputValue() === 'zu buchen', 'Tag als Quelle der Richtung bleibt gespeichert');

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
  // Vorschau beim Überfahren (X6, F97): erscheint am Rand, ohne die Liste zu verschieben; auch per Taste V
  const auge = page.locator('#vorratsliste tr[data-id]').first().locator('.beleg-auge');
  await auge.hover();
  await page.waitForFunction(() => { const s = document.getElementById('beleg-schwebe'); const i = s?.querySelector('img'); return s && !s.hidden && i.complete && i.naturalWidth > 0; });
  const lage = await page.evaluate(() => { const s = document.getElementById('beleg-schwebe').getBoundingClientRect(); const a = document.querySelector('#vorratsliste .beleg-auge').getBoundingClientRect(); return { links: s.left, augeMitte: a.left + a.width / 2, augeRechts: a.right, breite: window.innerWidth, rechts: s.right }; });
  ist((lage.links > lage.augeMitte && lage.links < lage.augeRechts + 40) || lage.rechts < lage.augeMitte, `Vorschau hängt neben dem Mauszeiger: ${JSON.stringify(lage)}`);
  ist(lage.rechts <= lage.breite, 'und bleibt ganz im Fenster');
  await bild('3-vorrat-paperless');
  await page.locator('h1, h2').first().hover();
  await page.waitForFunction(() => document.getElementById('beleg-schwebe').hidden);
  await page.locator('#vorratsliste tr[data-id]').first().click({ position: { x: 5, y: 5 } });
  await page.keyboard.press('v');
  await page.waitForFunction(() => !document.getElementById('beleg-schwebe').hidden);
  ist(true, 'Vorschau per Tastatur (V)');
  await page.keyboard.press('v');
  // Steuerkonto für den ersten Beleg vorbelegen (X2): Netto und Steuer kommen aus Paperless
  const erste = await page.locator('#vorratsliste tr[data-id]').first().innerText();
  ist(erste.includes('RE-0815') && erste.includes('109,43'), `Belegnummer und Betrag aus den Feldern von Paperless: ${erste}`);
  await page.locator('#vorratsliste tr[data-id]').first().locator('input[type=checkbox]').check();
  await page.locator('#vorrat-vorbelegen').click();
  await page.locator('#stapel-konto').fill('4930');
  await page.locator('#stapel-gegenkonto').fill('1200');
  await page.locator('#stapel-steuerkonto').fill('1400');
  await page.locator('#stapel-ja').click();
  await page.locator('#stapel-dialog').waitFor({ state: 'detached' });
  await page.locator('#vorratsliste tr[data-id]').first().locator('input[type=checkbox]').uncheck();
  await page.locator('#vorratsliste tr[data-id]').first().click({ position: { x: 5, y: 5 } });
  await page.keyboard.press('Enter');

  // Buchungsmaske: Beleg geprüft (MD5 und SHA-256 des Originals), Vorschau, Tag aus Paperless
  await page.locator('#vorrat-angaben').filter({ hasText: 'Rechnung Musterlieferant RE-0815' }).waitFor();
  await page.locator('#beleg-marke').filter({ hasText: 'geprüft' }).waitFor();
  ist(await page.locator('#beleg-marke').innerText() === 'geprüft', 'Beleg aus Paperless ist geprüft');
  ist(await page.locator('#beleg').inputValue() === `${PL}/documents/5210/`, 'Beleglink in der einheitlichen Schreibweise');
  ist(await page.locator('#text').inputValue() === 'Musterlieferant GmbH & Co. KG RE-0815', 'Buchungstext aus Rechnungssteller und Rechnungsnummer');
  // Splitbuchung mit Vorsteuer (X2): Aufwand netto, Vorsteuer, Bank brutto
  const splitZeile = (n) => page.locator('.split-tabelle tbody tr').nth(n).locator('input').evaluateAll((l) => l.map((i) => i.value).filter(Boolean).join('|'));
  await page.locator('#split-rest.ausgeglichen').waitFor();
  const split = [await splitZeile(0), await splitZeile(1), await splitZeile(2)];
  ist(split[0].startsWith('4930') && split[0].includes('91,96') && split[1].startsWith('1400') && split[1].includes('17,47') && split[2].startsWith('1200') && split[2].includes('109,43'), `Splitbuchung vorbelegt: ${JSON.stringify(split)}`);
  await page.locator('#beleg-sehen').click();
  await page.waitForFunction(() => { const i = document.querySelector('#beleg-dialog img'); return i && i.complete && i.naturalWidth > 0; });
  ist(true, 'Vorschaubild über kumero geladen');
  await bild('4-maske-vorschau');
  await page.locator('#beleg-schliessen').click();
  await page.locator('.tagwahl summary:visible').first().click();
  ist(await page.locator('.tagliste label:visible').filter({ hasText: 'Kostenstelle Empfang' }).count() === 1, 'Tag aus Paperless steht in der Maske zur Wahl');
  await page.locator('.tagwahl summary:visible').first().click();
  await bild('4a-maske-split');
  // Überfahren des Knopfs „Vorschau“ zeigt die kleine Vorschau neben dem Zeiger
  await page.locator('#beleg-sehen').hover();
  await page.waitForFunction(() => { const s = document.getElementById('beleg-schwebe'); const i = s?.querySelector('img'); return s && !s.hidden && i.complete && i.naturalWidth > 0; });
  const lage2 = await page.evaluate(() => { const s = document.getElementById('beleg-schwebe').getBoundingClientRect(); const b = document.getElementById('beleg-sehen').getBoundingClientRect(); return { links: s.left, rechts: s.right, mitte: b.left + b.width / 2, breite: window.innerWidth }; });
  ist((lage2.links > lage2.mitte || lage2.rechts < lage2.mitte) && lage2.rechts <= lage2.breite && lage2.links >= 0, `Vorschau beim Überfahren in der Buchungserfassung neben dem Zeiger (rechts, ohne Platz links): ${JSON.stringify(lage2)}`);
  await bild('4b-maske-schwebe');
  await page.locator('h1').first().hover();
  await page.waitForFunction(() => document.getElementById('beleg-schwebe').hidden);
  // unbar: Der Tag des Geldflusses wird eingegeben, nicht geraten
  await page.locator('#buchungsdatum').fill('02.03.2026');
  await page.locator('#buchungsdatum').press('Tab');
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
  // Der Abgleich mit den Quellen läuft im Hintergrund, höchstens alle fünf Minuten – oder sofort über den Knopf
  ist((await page.locator('#vorrat-stand').innerText()).startsWith('Abgeglichen um'), 'Stand des letzten Abgleichs wird genannt, kein neuer Abgleich innerhalb der Frist');
  await page.locator('#vorrat-abgleichen').click();
  await page.waitForFunction(() => !document.getElementById('vorrat-abgleichen').disabled && document.getElementById('vorrat-stand').textContent.startsWith('Abgeglichen um'));
  const tags = JSON.parse(readFileSync(zustand, 'utf8')).documents[5210].tags;
  ist(JSON.stringify(tags) === '[2]', `Tag für offene Belege in Paperless entfernt: ${JSON.stringify(tags)}`);

  // E-Rechnung als PDF (F110): Zwischenablage mit Vorschau, nach der Buchung Übergabe an Paperless, Beleg nachgereicht, Datei gelöscht
  const muster = mkdtempSync(path.join(os.tmpdir(), 'kumero-erechnung-'));
  execFileSync('php', [path.join(wurzel, 'werkzeuge/e-rechnung-muster.php'), muster]);
  const holen = (pfad) => page.evaluate(async (p) => { const r = await fetch(`/m/browserpraxis/api/v1${p}`, { headers: { Accept: 'application/json' } }); return { status: r.status, typ: r.headers.get('content-type'), json: (r.headers.get('content-type') || '').includes('json') ? await r.json() : null }; }, pfad);
  await nav('Datei einlesen');
  await page.locator('#import-datei').setInputFiles([path.join(muster, 'zwei-saetze.pdf')]);
  await page.locator('#rechnung-einliefern').filter({ hasText: '1 Rechnung in den Arbeitsvorrat einliefern' }).click();
  await meldung('1 Rechnung in den Arbeitsvorrat eingeliefert.');
  await nav('Arbeitsvorrat');
  const er = page.locator('#vorratsliste tr[data-id]').filter({ hasText: 'Mustermarkt eG' });
  await er.waitFor();
  const eintraege = (await holen('/arbeitsvorrat/eintraege?zustand=offen&abgleich=nein')).json.eintraege;
  const vorl = eintraege.find((x) => x.belegnummer === 'RE-MUSTER-5005').belege[0];
  ist(/\/m\/browserpraxis\/import\/dateien\/[0-9a-f-]{36}$/.test(vorl.link) && vorl.md5 && vorl.sha256, `der Eintrag trägt den vorläufigen Beleg mit Prüfsummen: ${vorl.link}`);
  const bildAntwort = await holen(`/belege/vorschau?link=${encodeURIComponent(vorl.link)}`);
  ist(bildAntwort.status === 200 && bildAntwort.typ === 'image/png', `Vorschaubild aus der Zwischenablage: ${bildAntwort.status} ${bildAntwort.typ}`);
  await er.locator('.beleg-auge').click();
  await page.waitForFunction(() => { const i = document.querySelector('#beleg-dialog img'); return i && i.complete && i.naturalWidth > 100; });
  ist(true, 'Vorschau des PDF im Arbeitsvorrat');
  await bild('7-zwischenablage-vorschau');
  await page.locator('#beleg-schliessen').click();
  // der vorläufige Link gelangt nie in eine Buchung – das verhindert der Kern
  const abgelehnt = await page.evaluate(async (link) => {
    const r = await fetch('/m/browserpraxis/api/v1/buchungen', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'Idempotency-Key': crypto.randomUUID() },
      body: JSON.stringify({ text: 'Muster', belege: [{ link }], zeilen: [] }) });
    return `${r.status} ${(await r.json()).code}`;
  }, vorl.link);
  ist(abgelehnt === '422 beleg.vorlaeufig', `vorläufiger Beleg an einer Buchung wird abgelehnt: ${abgelehnt}`);
  // buchen: In der Maske steht „Zwischenablage“; gespeichert wird ohne den vorläufigen Beleg
  for (const [feld, nr] of [['konto', '4930'], ['gegenkonto', '1200']]) {
    await er.locator(`input[data-feld="${feld}"]`).fill(nr);
    await er.locator(`input[data-feld="${feld}"]`).dispatchEvent('change');
  }
  await er.locator('td').nth(3).click();
  await page.locator('#vorrat-buchen').click();
  await page.locator('#vorrat-angaben').filter({ hasText: 'RE-MUSTER-5005' }).waitFor();
  await page.locator('#beleg-marke').filter({ hasText: 'Zwischenablage' }).waitFor();
  ist(true, 'die Maske kennzeichnet den Beleg als Zwischenablage');
  await page.locator('#buchen').click();
  await meldung('Gebucht als Nr.');
  // Abgleich: erst gesendet (Paperless verarbeitet noch), dann gefunden → nachgereicht und in kumero gelöscht
  const abgleich = () => page.evaluate(async () => (await fetch('/m/browserpraxis/api/v1/arbeitsvorrat/abgleich', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'Idempotency-Key': crypto.randomUUID() }, body: '{}' })).status);
  ist(await abgleich() === 200, 'erster Abgleich');
  ist(JSON.parse(readFileSync(zustand, 'utf8')).hochgeladen === 1, 'das PDF ist an Paperless gesendet');
  ist((await holen(`/belege/vorschau?link=${encodeURIComponent(vorl.link)}`)).status === 200, 'solange Paperless die Datei noch nicht führt, bleibt sie in kumero');
  ist(await abgleich() === 200, 'zweiter Abgleich');
  const weg = await holen(`/belege/vorschau?link=${encodeURIComponent(vorl.link)}`);
  ist(weg.status === 410 && weg.json.detail.includes('an das Belegarchiv übergeben'), `danach hält kumero die Datei nicht mehr: ${weg.status} ${weg.json?.detail}`);
  ist(JSON.parse(readFileSync(zustand, 'utf8')).hochgeladen === 1, 'nur einmal gesendet');
  const gebucht = (await holen('/buchungen')).json;
  const bu = (gebucht.buchungen || gebucht.eintraege || gebucht).find((x) => (x.text || '').includes('RE-MUSTER-5005'));
  const nachgereicht = (await holen(`/buchungen/${bu.id}`)).json.belege_nachgereicht;
  ist(nachgereicht.length === 1 && /\/documents\/\d+\/$/.test(nachgereicht[0].link) && nachgereicht[0].sha256 === vorl.sha256, `der Beleg ist an der Buchung nachgereicht, mit dem Link aus Paperless: ${JSON.stringify(nachgereicht)}`);

  // Die Buchungsliste zeigt auch den nachgereichten Beleg: Zeichen zum Öffnen und Vorschau
  await nav('Buchungsliste');
  const zeileNach = page.locator('tr').filter({ hasText: 'RE-MUSTER-5005' }).first();
  await zeileNach.waitFor();
  ist(/\/documents\/\d+\/$/.test(await zeileNach.locator('a.beleg-zeichen').getAttribute('href')), 'Buchungsliste: Der nachgereichte Beleg lässt sich öffnen');
  ist(await zeileNach.locator('.beleg-auge').count() === 1, 'Buchungsliste: Vorschau des nachgereichten Belegs');
  await bild('7a-liste-nachgereicht');

  // XRechnung als reines XML (F112): ohne die Einstellung des Mandanten wird das XML nicht zwischengelagert; mit ihr gibt es Vorschau und Original
  await nav('Datei einlesen');
  await page.locator('#import-datei').setInputFiles([path.join(muster, 'xrechnung-cii.xml')]);
  await page.locator('.rechnung-ohne-original').filter({ hasText: 'Das XML wird nicht gespeichert' }).waitFor();
  ist(true, 'ohne die Einstellung sagt die Prüftabelle, dass das XML nicht gespeichert wird');
  await nav('Paperless – mein Zugang');
  await page.locator('#pl-ablage-xml').check();
  await page.locator('#pl-ablage-speichern').click();
  await meldung('Gespeichert');
  await page.reload();
  await page.locator('#pl-ablage-xml').waitFor();
  ist(await page.locator('#pl-ablage-xml').isChecked(), 'die Einstellung „nimmt E-Rechnungen als XML an“ bleibt gespeichert');
  await bild('8-belegablage-xml');
  await nav('Datei einlesen');
  await page.locator('#import-datei').setInputFiles([path.join(muster, 'xrechnung-cii.xml')]);
  await page.locator('#rechnung-einliefern').filter({ hasText: '1 Rechnung in den Arbeitsvorrat einliefern' }).click();
  await meldung('1 Rechnung in den Arbeitsvorrat eingeliefert.');
  ist(await page.locator('.rechnung-ohne-original').count() === 0, 'mit der Einstellung kommt das XML in die Zwischenablage');
  await nav('Arbeitsvorrat');
  const xr = page.locator('#vorratsliste tr[data-id]').filter({ hasText: 'RE-MUSTER-1001' });
  await xr.waitFor();
  const xmlBeleg = (await holen('/arbeitsvorrat/eintraege?zustand=offen&abgleich=nein')).json.eintraege.find((x) => x.belegnummer === 'RE-MUSTER-1001').belege[0];
  const xmlBild = await holen(`/belege/vorschau?link=${encodeURIComponent(xmlBeleg.link)}`);
  ist(xmlBild.status === 200 && xmlBild.typ === 'image/svg+xml', `Vorschau der XRechnung als Blatt: ${xmlBild.status} ${xmlBild.typ}`);
  await xr.locator('.beleg-auge').click();
  await page.waitForFunction(() => { const i = document.querySelector('#beleg-dialog img'); return i && i.complete && i.naturalWidth > 100; });
  ist(true, 'das Blatt mit den Angaben der XRechnung wird im Arbeitsvorrat gezeigt');
  await bild('9-xrechnung-vorschau');
  await page.locator('#beleg-schliessen').click();

  // Keine Vorschau: Der konkrete Grund steht da – hier: Paperless ist gesperrt
  await nav('Paperless – mein Zugang');
  await page.locator('#pl-sperren').click();
  await meldung('Paperless ist gesperrt.');
  // neu laden: Ein Bild, das die Seite schon gezeigt hat, hält der Browser sonst noch im Speicher
  await page.reload();
  await nav('Buchungsliste');
  await page.locator('#ansicht').selectOption('journal');
  await page.locator('#buchungsliste .beleg-auge').first().click();
  await page.locator('#beleg-grund').filter({ hasText: 'Paperless ist gesperrt' }).waitFor();
  ist((await page.locator('#beleg-grund').innerText()).includes('entsperren'), 'ohne Vorschau steht der Grund da: gesperrt, mit dem Weg zum Entsperren');
  await bild('6-kein-bild-grund');
  await page.locator('#beleg-schliessen').click();
} finally {
  await browser.close();
  server.kill();
  nachbau.kill();
  rmSync(zustand, { force: true });
  execFileSync('php', [path.join(wurzel, 'tests/browser/aufbau.php'), '--abbau']);
}
// die absichtlich falsche Passphrase (403) gehört zum Test
const echteFehler = fehler.filter((f) => !/status of (401|403|404|409)/.test(f) && !/HTTP 403 .*\/paperless\/entsperren/.test(f) && !/HTTP (409|410) .*\/belege\/vorschau/.test(f) && !/status of (410|422)/.test(f) && !/HTTP 422 .*\/buchungen/.test(f));
if (echteFehler.length) {
  console.error(echteFehler.join('\n'));
  console.error(serverLog.split('\n').filter((z) => /error|warn|kumero\[/i.test(z)).slice(-20).join('\n'));
  process.exit(1);
}
console.log(`${pruefungen} Prüfungen im Browser, alle grün`);
