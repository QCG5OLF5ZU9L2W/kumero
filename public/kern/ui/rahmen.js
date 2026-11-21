// SPDX-License-Identifier: AGPL-3.0-or-later
// Rahmen der Weboberfläche (OBERFLAECHE.md 2, 4, 5; F80): Kopf, Menü aus der API, Router, Seiten der Module.
import { api, anmelden, passkeyRegistrieren, abgemeldetMelden } from './api.js';
import { h, knopf, meldungsleiste, feld, sperren, umgebung } from './bausteine.js';

const zustand = { ich: null, mandant: null, buch: null, buecher: [], menue: null, texte: {}, aufraeumen: null, abgelaufen: false, beruehrt: false };
// Seiten des Mandanten (nicht eines Buchs): Bücher, Nutzer und Rollen (BUECHER.md Abschnitt 11)
const MANDANTENSEITEN = ['kern/buecher', 'kern/nutzer'];
const wurzel = document.getElementById('app');
const meldung = meldungsleiste();

// Eine Identität gehört zu genau einem Mandanten (F114): Der Passkey bestimmt bei der Anmeldung, in welchen
// Mandanten es geht. Eine Auswahl gibt es nicht mehr – wer in einen anderen will, meldet sich dort an (U3).
// System-Verwalter haben keinen Mandanten.

// ── Pfade: #/system/<modul>/<ziel>?…, #/m/<mandant>/<modul>/<ziel>?… (Seiten des Mandanten) oder
//    #/m/<mandant>/b/<buch>/<modul>/<ziel>?… (Seiten eines Buchs, BUECHER.md B2) ──
function pfadLesen() {
  const roh = location.hash.replace(/^#/, '') || '/system/kern/start';
  const [weg, frage = ''] = roh.split('?');
  const t = weg.split('/').filter(Boolean);
  const param = Object.fromEntries(new URLSearchParams(frage));
  if (t[0] === 'm' && t[2] === 'b' && t.length >= 6) return { system: false, mandant: t[1], buch: t[3], modul: t[4], ziel: t[5], param, roh };
  if (t[0] === 'm' && t.length >= 4) return { system: false, mandant: t[1], buch: null, modul: t[2], ziel: t[3], param, roh };
  if (t[0] === 'system' && t.length >= 3) return { system: true, mandant: null, buch: null, modul: t[1], ziel: t[2], param, roh };
  return { system: true, mandant: null, buch: null, modul: 'kern', ziel: 'start', param: {}, roh: '/system/kern/start' };
}

const merkSchluessel = () => `kumero.buch.${zustand.mandant}`;
function buchMerken(kennung) { try { localStorage.setItem(merkSchluessel(), kennung); } catch { /* nur eine Bequemlichkeit */ } }
function buchGemerkt() { try { return localStorage.getItem(merkSchluessel()); } catch { return null; } }

/** Bücher des Mandanten laden und das Buch wählen: aus der Adresse, sonst das zuletzt gewählte, sonst das erste. */
async function buecherLaden() {
  if (!zustand.mandant) { zustand.buecher = []; zustand.buch = null; return; }
  zustand.buecher = (await api('GET', `/m/${zustand.mandant}/api/v1/buecher`)).buecher || [];
  const hat = (k) => zustand.buecher.some((b) => b.kennung === k);
  const p = pfadLesen();
  zustand.buch = [p.buch, zustand.buch, buchGemerkt(), zustand.buecher[0]?.kennung].find((k) => k && hat(k)) || null;
}

async function texte(url) {
  if (!url) return {};
  if (!zustand.texte[url]) {
    zustand.texte[url] = fetch(url, { credentials: 'same-origin' }).then((r) => (r.ok ? r.json() : {})).catch(() => ({}));
  }
  return zustand.texte[url];
}

async function menueLaden() {
  const m = zustand.mandant;
  const b = zustand.buch;
  zustand.menue = await api('GET', m ? `/m/${m}${b ? `/b/${b}` : ''}/api/v1/oberflaeche/menue` : '/api/v1/oberflaeche/menue');
}

const buchText = (b) => b.name + (b.testbuch ? ' (Testbuch)' : (b.betriebsart === 'test' ? ' (Test)' : ''));

/** Auswahl des Buchs im Kopf, an festem Platz (F106). Wechsel des Buchs ist kein neues Anmelden (B2). */
function buchwahlZeichnen() {
  const el = document.getElementById('buch-wahl');
  if (!el) return;
  el.replaceChildren(...(zustand.buecher.length ? zustand.buecher : [{ kennung: '', name: 'kein Buch' }])
    .map((b) => h('option', { value: b.kennung }, b.kennung ? buchText(b) : b.name)));
  el.value = zustand.buch || '';
  sperren(el, zustand.buecher.length > 1 ? null : (zustand.buecher.length ? 'Dieser Mandant hat für dich nur dieses Buch' : 'Für dich gibt es in diesem Mandanten noch kein Buch'));
  const aktuell = zustand.buecher.find((b) => b.kennung === zustand.buch);
  document.documentElement.classList.toggle('testbuch', Boolean(aktuell?.testbuch));
}

async function buchWechseln(kennung) {
  if (!zustand.buecher.some((b) => b.kennung === kennung)) return;
  zustand.buch = kennung;
  buchMerken(kennung);
  await menueLaden();
  buchwahlZeichnen();
  const p = pfadLesen();
  // dieselbe Seite im anderen Buch, wenn es sie dort gibt; sonst die Übersicht
  const gleich = !p.system && p.buch && zustand.menue?.seiten?.[`${p.modul}/${p.ziel}`];
  const ziel = gleich ? `#/m/${zustand.mandant}/b/${kennung}/${p.modul}/${p.ziel}` : '#/system/kern/start';
  if (location.hash === ziel) await seiteZeigen(); else location.hash = ziel;
}

// ── Einstellungen des Nutzers (PROFIL.md): Schreibweise, Schriftgröße, Kontrast ──
function einstellungenAnwenden() {
  const ich = zustand.ich;
  // Die Sprache des Nutzers geht vor; ist keine gesetzt, gilt die des Browsers (U5)
  umgebung.lokal = ich?.sprache || navigator.language || 'de-DE';
  const e = ich?.einstellungen || {};
  const k = document.documentElement.classList;
  for (const c of [...k]) if (c.startsWith('schrift-') || c.startsWith('kontrast-')) k.remove(c);
  k.add(`schrift-${(e['kern.schriftgroesse'] || 'normal').replace('_', '-')}`, `kontrast-${e['kern.kontrast'] || 'system'}`);
}

// ── Anzeige ────────────────────────────────────────────────────────────────
function anmeldungZeigen() {
  const token = new URLSearchParams(location.hash.replace(/^#/, '')).get('registrierung');
  const bez = h('input', { id: 'registrierung-bezeichnung', placeholder: 'z. B. KeePassXC Arbeitsplatz', maxlength: '100' });
  // Zum Link gehört ein Code, der auf anderem Weg kam (VERWALTUNG.md Abschnitt 6). Das Feld steht immer da;
  // ein Link ohne Code (Notausgang der Kommandozeile) sperrt es und nennt den Grund (F106).
  const code = h('input', { id: 'registrierung-code', maxlength: '9', size: '9', autocomplete: 'one-time-code', autocapitalize: 'characters', spellcheck: false,
    placeholder: 'ABC DEF', title: 'Sechs Zeichen; Groß- und Kleinschreibung ist egal' });
  const stand = h('p', { id: 'registrierung-stand' }, 'Der Link wird geprüft …');
  if (token) {
    api('POST', '/api/v1/registrierung/pruefen', { json: { token } }).then((r) => {
      stand.textContent = `Zugang${r.mandant ? ` für „${r.mandant.name}“` : ''}`
        + `${r.gueltig_bis ? `, gültig bis ${new Date(r.gueltig_bis).toLocaleString(umgebung.lokal, { dateStyle: 'short', timeStyle: 'short' })}` : ''}`
        + ', nur einmal verwendbar. Der Browser fragt gleich nach deinem Passkey.';
      code.required = r.code_noetig;
      sperren(code, r.code_noetig ? null : 'Zu diesem Link gehört kein Code');
    }).catch(() => {
      stand.textContent = 'Dieser Link ist ungültig, abgelaufen oder schon verbraucht. Bitte lass dir einen neuen Zugang ausstellen.';
      sperren(code, 'Der Link ist ungültig');
    });
  }
  const inhalt = token
    ? h('form', { class: 'anmeldung', onsubmit: async (ev) => {
      ev.preventDefault();
      try {
        await passkeyRegistrieren(token, bez.value, code.disabled ? null : code.value);
        history.replaceState(null, '', location.pathname);
        meldung.setzen('Passkey registriert. Jetzt anmelden.', 'erfolg');
        anmeldungZeigen();
      } catch (e) { meldung.fehler(e); }
    } },
    h('h1', {}, 'Passkey registrieren'),
    stand,
    feld('Code', code, 'Den Code hast du getrennt vom Link bekommen. Nach fünf falschen Eingaben ist der Zugang ungültig.'),
    feld('Bezeichnung', bez), h('button', { type: 'submit', class: 'knopf primaer' }, 'Passkey registrieren'))
    : h('div', { class: 'anmeldung' },
      h('h1', {}, 'kumero'), h('p', {}, 'Anmelden mit deinem Passkey.'),
      knopf('Mit Passkey anmelden', async () => {
        try { await anmelden(); meldung.setzen(''); await start(); } catch (e) { meldung.fehler(e); }
      }, 'primaer', { id: 'anmelden' }));
  if (!window.PublicKeyCredential) {
    meldung.setzen('Dieser Browser unterstützt keine Passkeys (oder die Seite ist nicht über https bzw. http://localhost geöffnet).', 'fehler');
  }
  wurzel.replaceChildren(h('main', { class: 'anmeldeseite' }, meldung, inhalt));
}

/** Fehlende Angaben zur Person ergänzen (VERWALTUNG.md Abschnitt 6 und 11): einmalig, danach ändert sie die Verwaltung des Mandanten. */
function angabenZeigen() {
  const ich = zustand.ich;
  const vornamen = h('input', { id: 'angaben-vornamen', required: true, maxlength: '200', autocomplete: 'given-name', value: ich.vornamen || '' });
  const familienname = h('input', { id: 'angaben-familienname', required: true, maxlength: '200', autocomplete: 'family-name', value: ich.familienname || '' });
  const email = h('input', { id: 'angaben-email', type: 'email', required: true, maxlength: '254', autocomplete: 'email', value: ich.email || '' });
  wurzel.replaceChildren(h('main', { class: 'anmeldeseite' }, meldung,
    h('form', { class: 'anmeldung', onsubmit: async (ev) => {
      ev.preventDefault();
      try {
        await api('PATCH', '/api/v1/ich', { json: { vornamen: vornamen.value, familienname: familienname.value, email: email.value } });
        meldung.setzen('');
        await start();
      } catch (e) { meldung.setzen(e.felder || e.message, 'fehler'); }
    } },
    h('h1', {}, 'Deine Angaben'),
    h('p', {}, `Für den Mandanten „${ich.mandanten[0]?.name || ich.mandant}“ fehlen noch Vorname(n) und E-Mail-Adresse. `
      + 'Sie stehen künftig bei deinem Zugang – über die E-Mail-Adresse erreicht dich die Verwaltung des Mandanten, etwa wenn du einen neuen Passkey brauchst.'),
    feld('Vorname(n)', vornamen), feld('Familienname', familienname), feld('E-Mail-Adresse', email),
    h('div', { class: 'anmeldung-knoepfe' },
      h('button', { type: 'submit', class: 'knopf primaer', id: 'angaben-speichern' }, 'Speichern und weiter'),
      knopf('Abmelden', async () => { try { await api('POST', '/api/v1/abmeldung'); } finally { zustand.ich = null; anmeldungZeigen(); } }, 'leise')))));
}

/**
 * Die Sitzung ist abgelaufen (30 Minuten ohne Aufruf, spätestens nach 12 Stunden) oder wurde beendet: Irgendein Aufruf
 * bekam „Bitte anmelden“. Die Seite bleibt stehen, wie sie ist – darüber liegt die Anmeldung. Danach geht es an
 * derselben Stelle weiter: Hat der Nutzer auf der Seite schon etwas eingegeben, bleibt sie mit den Eingaben stehen,
 * sonst wird sie neu geladen.
 */
function sitzungAbgelaufen() {
  if (!zustand.ich || zustand.abgelaufen) return;
  zustand.abgelaufen = true;
  const vorher = zustand.ich;
  const stand = h('p', { class: 'hinweis', role: 'alert', id: 'sitzung-stand' }, '\u00a0');   // fester Platz für eine Fehlermeldung der Anmeldung
  const zurAnmeldeseite = () => { d.close(); zustand.abgelaufen = false; zustand.ich = null; anmeldungZeigen(); };
  const kn = knopf('Mit Passkey anmelden', async () => {
    try {
      await anmelden();
      const ich = await api('GET', '/api/v1/ich');
      d.close();
      zustand.abgelaufen = false;
      // ein anderer Passkey (anderer Mandant, andere Person): alles von vorn, nichts von der alten Seite bleibt
      if (ich.subjekt !== vorher.subjekt || (ich.mandant || null) !== (vorher.mandant || null)) { await start(); return; }
      zustand.ich = ich;
      try { await menueLaden(); navZeichnen(); } catch { /* das Menü von vorher bleibt */ }
      if (zustand.beruehrt) meldung.setzen('Wieder angemeldet. Deine Eingaben stehen noch da – bitte wiederhole den letzten Schritt.', 'erfolg');
      else await seiteZeigen();
    } catch (e) {
      stand.className = 'hinweis fehlertext';
      stand.textContent = e?.name === 'NotAllowedError' ? 'Vom Browser oder Passkey abgebrochen.' : (e?.message || String(e));
    }
  }, 'primaer', { id: 'sitzung-anmelden' });
  const d = h('dialog', { class: 'dialog', id: 'sitzung-abgelaufen' },
    h('h2', {}, 'Bitte neu anmelden'),
    h('p', {}, 'Deine Sitzung ist abgelaufen – nach 30 Minuten ohne Eingabe, spätestens nach 12 Stunden. '
      + 'Die Seite bleibt stehen, wie sie ist; nach der Anmeldung geht es hier weiter.'),
    stand,
    h('div', { class: 'dialog-knoepfe' },
      knopf('Zur Anmeldeseite', zurAnmeldeseite, '', { id: 'sitzung-abmelden', title: 'Verwirft, was auf der Seite noch nicht gespeichert ist' }),
      kn));
  d.addEventListener('cancel', (ev) => ev.preventDefault());   // Esc schließt nicht: ohne Anmeldung geht nichts weiter
  d.addEventListener('close', () => d.remove());
  document.body.append(d);
  d.showModal();
  kn.focus();
}
abgemeldetMelden(sitzungAbgelaufen);
// Hat der Nutzer auf der gezeigten Seite schon etwas eingegeben? Dann wird sie nach dem neuen Anmelden nicht neu geladen.
wurzel.addEventListener('input', (ev) => { if (ev.target.closest?.('#seite')) zustand.beruehrt = true; });

function rahmenZeigen() {
  const ich = zustand.ich;
  const m = ich.mandanten[0];
  // Fester Platz für den Mandanten (F106); wechseln heißt neu anmelden (U3)
  const wo = h('strong', { id: 'mandant-name', 'data-mandant': m?.kennung || '',
    title: m ? 'Diese Anmeldung gilt für diesen Mandanten. In einen anderen Mandanten kommst du, indem du dich abmeldest und dich mit dem Passkey dieses Mandanten anmeldest.'
      : 'System-Verwalter arbeiten für die Installation, nicht in einem Mandanten.' },
  m ? m.name : 'Verwaltung der Installation');
  const buchwahl = h('select', { id: 'buch-wahl', class: 'buch-wahl', 'aria-label': 'Buch', title: 'Das Buch, in dem du arbeitest. Jedes Buch ist eine eigene Buchhaltung.',
    onchange: (ev) => buchWechseln(ev.target.value) });

  const nav = h('nav', { class: 'navigation', id: 'navigation', 'aria-label': 'Menü' });
  const leiste = menueleiste(nav);
  const kopf = h('header', { class: 'kopf' },
    leiste.knopf,
    h('a', { class: 'marke', href: '#/system/kern/start' }, 'kumero'),
    h('div', { class: 'kopf-mandant' }, m ? h('span', {}, 'Mandant') : null, wo, m ? h('span', {}, 'Buch') : null, m ? buchwahl : null),
    // Meldungen stehen in der Statusleiste (F106): fester Platz, der Inhalt der Seite verschiebt sich nie
    h('div', { class: 'kopf-meldung' }, meldung),
    ich.verwalter ? h('span', { class: 'marke-verwalter' }, 'Verwalter') : null,
    nutzermenue(ich));
  wurzel.replaceChildren(kopf, h('div', { class: 'koerper' }, nav, leiste.rand, leiste.griff,
    h('main', { class: 'inhalt' }, h('div', { id: 'seite' }))));
  leiste.anwenden();
  buchwahlZeichnen();
  navZeichnen();
}

/**
 * Menüleiste verschiebbar und ausblendbar (F137, OBERFLAECHE.md T5 und T6). Die Breite wird am rechten Rand gezogen –
 * Maus, Finger oder Tastatur (Pfeile, mit Umschalt größer; Pos1/Ende; Doppelklick stellt die Vorgabe her). Ein Knopf
 * im Kopf blendet das Menü aus und wieder ein; ausgeblendet bleibt ein schmaler Rand mit einem Knopf. Beides merkt
 * sich der Browser (kein Personenbezug, nicht am Server). Nichts bewegt sich von selbst (F106).
 */
const NAV_MERKNAME = 'kumero.navigation';
const NAV_MIN = 160;
let navFenster = null;
window.addEventListener('resize', () => navFenster?.());
function menueleiste(nav) {
  let breite = null;   // null = Vorgabe aus der Gestaltung
  let aus = false;
  try {
    const g = JSON.parse(localStorage.getItem(NAV_MERKNAME) || '{}');
    if (Number.isFinite(g.breite) && g.breite >= NAV_MIN) breite = g.breite;
    aus = g.aus === true;
  } catch { /* ohne Speicher gilt die Vorgabe */ }
  const merken = () => { try { localStorage.setItem(NAV_MERKNAME, JSON.stringify({ breite, aus })); } catch { /* dann eben nur für diese Sitzung */ } };
  const grenze = () => Math.max(NAV_MIN, Math.min(640, Math.round(window.innerWidth * 0.6)));

  const griff = h('div', { class: 'nav-griff nicht-drucken', id: 'navigation-teiler', role: 'separator', tabindex: '0', 'aria-orientation': 'vertical', 'aria-controls': 'navigation',
    'aria-label': 'Breite des Menüs', title: 'Ziehen, um das Menü breiter oder schmaler zu machen · Pfeiltasten · Doppelklick: Vorgabe' });
  const knopf = h('button', { type: 'button', class: 'kopf-menue', id: 'menue-knopf', 'aria-controls': 'navigation', onclick: () => schalten(!aus) }, '☰');
  const rand = h('button', { type: 'button', class: 'nav-rand nicht-drucken', id: 'navigation-rand', title: 'Menü einblenden', 'aria-label': 'Menü einblenden',
    onclick: () => { schalten(false); knopf.focus(); } }, '»');

  function setzen(px, merke = true) {
    breite = px === null ? null : Math.round(Math.min(Math.max(px, NAV_MIN), grenze()));
    nav.style.width = breite === null ? '' : `${breite}px`;
    griff.setAttribute('aria-valuemin', String(NAV_MIN));
    griff.setAttribute('aria-valuemax', String(grenze()));
    if (nav.isConnected && !aus) griff.setAttribute('aria-valuenow', String(Math.round(nav.getBoundingClientRect().width)));
    if (merke) merken();
  }
  function schalten(neu, merke = true) {
    aus = neu;
    nav.hidden = aus; griff.hidden = aus; rand.hidden = !aus;
    knopf.setAttribute('aria-expanded', aus ? 'false' : 'true');
    knopf.title = aus ? 'Menü einblenden' : 'Menü ausblenden';
    knopf.setAttribute('aria-label', knopf.title);
    if (merke) merken();
  }
  const jetzt = () => nav.getBoundingClientRect().width;
  let start = null;
  griff.addEventListener('pointerdown', (ev) => {
    if (ev.button !== 0) return;
    start = { x: ev.clientX, breite: jetzt() };
    griff.setPointerCapture(ev.pointerId);
    griff.classList.add('zieht');
    ev.preventDefault();
  });
  griff.addEventListener('pointermove', (ev) => { if (start) setzen(start.breite + (ev.clientX - start.x), false); });
  const ende = () => { if (!start) return; start = null; griff.classList.remove('zieht'); merken(); };
  griff.addEventListener('pointerup', ende);
  griff.addEventListener('pointercancel', ende);
  griff.addEventListener('dblclick', () => setzen(null));
  griff.addEventListener('keydown', (ev) => {
    const schritt = ev.shiftKey ? 64 : 16;
    if (ev.key === 'ArrowRight') setzen(jetzt() + schritt);
    else if (ev.key === 'ArrowLeft') setzen(jetzt() - schritt);
    else if (ev.key === 'Home') setzen(NAV_MIN);
    else if (ev.key === 'End') setzen(grenze());
    else return;
    ev.preventDefault();
  });
  // Wird das Fenster schmaler, bleibt dem Inhalt sein Platz
  navFenster = () => { if (breite !== null && breite > grenze()) setzen(grenze(), false); };
  return { knopf, griff, rand, anwenden: () => { schalten(aus, false); setzen(breite, false); } };
}

/** Nutzermenü oben rechts (PROFIL.md U1): Profil und Einstellungen, Abmelden. */
function nutzermenue(ich) {
  const menue = h('details', { class: 'nutzermenue', id: 'nutzermenue' },
    h('summary', { title: 'Profil, Einstellungen, Abmelden' }, ich.name),
    h('div', { class: 'nutzermenue-liste', role: 'menu' },
      h('a', { role: 'menuitem', href: '#/system/kern/profil', id: 'zum-profil', onclick: () => { menue.open = false; } }, 'Profil und Einstellungen'),
      h('button', { type: 'button', role: 'menuitem', id: 'abmelden', onclick: async () => {
        try { await api('POST', '/api/v1/abmeldung'); } finally { zustand.ich = null; anmeldungZeigen(); }
      } }, 'Abmelden')));
  menue.addEventListener('keydown', (ev) => { if (ev.key === 'Escape') { menue.open = false; menue.querySelector('summary').focus(); } });
  document.addEventListener('click', (ev) => { if (menue.open && !menue.contains(ev.target)) menue.open = false; });
  return menue;
}

/** Zähler an einem Menüeintrag (OBERFLAECHE.md: `zaehler`): Zahl aus der API des Moduls, nur gezeigt, wenn größer null. */
function zaehler(pfad) {
  const el = h('span', { class: 'nav-zaehler', hidden: true });
  api('GET', pfad).then((r) => {
    const n = Number(r?.anzahl);
    if (n > 0) { el.textContent = String(n); el.hidden = false; }
  }).catch(() => { /* ein Zähler ist nur eine Hilfe */ });
  return el;
}

function navZeichnen() {
  const nav = document.getElementById('navigation');
  if (!nav) return;
  const aktuell = '#' + pfadLesen().roh;
  const eintrag = (e, tiefe) => {
    const aktiv = e.pfad === aktuell || aktuell.startsWith(e.pfad + '?');
    return h('li', {},
      h('a', { href: e.pfad, class: ['nav-eintrag', aktiv ? 'aktiv' : '', `tiefe-${tiefe}`], 'aria-current': aktiv ? 'page' : null },
        e.symbol ? h('img', { src: e.symbol, alt: '', class: 'symbol' }) : h('span', { class: 'symbol' }),
        h('span', { class: 'nav-titel' }, e.titel),
        e.zaehler ? zaehler(e.zaehler) : null),
      e.unter?.length ? h('ul', {}, e.unter.map((u) => eintrag(u, tiefe + 1))) : null);
  };
  nav.replaceChildren(...(zustand.menue?.bereiche || []).map((b) => h('section', { class: 'bereich' },
    h('h2', {}, b.titel), h('ul', {}, b.eintraege.map((e) => eintrag(e, 1))))));
}

async function seiteZeigen() {
  const p = pfadLesen();
  const behaelter = document.getElementById('seite');
  if (!behaelter) return;
  if (typeof zustand.aufraeumen === 'function') { try { zustand.aufraeumen(); } catch { /* egal */ } }
  zustand.aufraeumen = null;
  zustand.beruehrt = false;
  meldung.setzen('');
  behaelter.className = '';   // Seiten setzen bei Bedarf „listenseite“ (fester Kopf, nur die Zeilen rollen)

  // Lesezeichen und Links in einen anderen Mandanten: Diese Anmeldung gilt dort nicht (U3)
  if (!p.system && p.mandant !== zustand.mandant) {
    navZeichnen();
    behaelter.replaceChildren(h('p', { class: 'hinweis', id: 'anderer-mandant' },
      `Diese Seite gehört zum Mandanten „${p.mandant}“. Deine Anmeldung gilt `
      + (zustand.mandant ? `für „${zustand.mandant}“` : 'für die Verwaltung der Installation')
      + '. Melde dich ab und mit dem Passkey des anderen Mandanten an.'));
    return;
  }
  if (!p.system) {
    const mandantenseite = MANDANTENSEITEN.includes(`${p.modul}/${p.ziel}`);
    // Ein altes Lesezeichen ohne Buch (#/m/<mandant>/<modul>/<ziel>) führt in das gewählte Buch
    if (!p.buch && !mandantenseite && zustand.buch) {
      history.replaceState(null, '', `#/m/${p.mandant}/b/${zustand.buch}/${p.modul}/${p.ziel}${location.hash.includes('?') ? `?${location.hash.split('?')[1]}` : ''}`);
      return seiteZeigen();
    }
    if (p.buch && p.buch !== zustand.buch) {
      if (!zustand.buecher.some((b) => b.kennung === p.buch)) {
        navZeichnen();
        behaelter.replaceChildren(h('p', { class: 'hinweis', id: 'anderes-buch' }, `Das Buch „${p.buch}“ gibt es in diesem Mandanten nicht, oder du hast dort keine Rolle.`));
        return;
      }
      zustand.buch = p.buch;
      buchMerken(p.buch);
      await menueLaden();
      buchwahlZeichnen();
    }
  }
  navZeichnen();
  const seite = zustand.menue?.seiten?.[`${p.modul}/${p.ziel}`];
  if (!seite) {
    behaelter.replaceChildren(h('p', { class: 'hinweis' }, 'Diese Seite gibt es nicht oder das Modul ist hier nicht aktiv.'));
    return;
  }
  behaelter.replaceChildren(h('p', { class: 'laden' }, 'Wird geladen …'));
  try {
    const [modul, t] = await Promise.all([import(seite.url), texte(seite.texte)]);
    const mandant = p.system ? zustand.mandant : p.mandant;
    const kontext = {
      ich: zustand.ich,
      einstellungen: zustand.ich.einstellungen || {},
      lokal: umgebung.lokal,
      mandant,
      // Titel der Seiten nennen, wo man arbeitet: im Buch dessen Namen, sonst den des Mandanten
      mandantName: (!p.system && p.buch && zustand.buecher.find((x) => x.kennung === p.buch)?.name) || zustand.ich.mandanten.find((m) => m.kennung === mandant)?.name || null,
      system: p.system,
      param: p.param,
      // Seiten eines Buchs sprechen mit dem Buch, Seiten des Mandanten mit dem Mandanten (BUECHER.md Abschnitt 8)
      apiBasis: p.system ? '/api/v1' : `/m/${p.mandant}${p.buch ? `/b/${p.buch}` : ''}/api/v1`,
      mandantApi: mandant ? `/m/${mandant}/api/v1` : null,
      buchApi: mandant && zustand.buch ? `/m/${mandant}/b/${zustand.buch}/api/v1` : null,
      buch: zustand.buch,
      buchInfo: zustand.buecher.find((x) => x.kennung === zustand.buch) || null,
      buchName: zustand.buecher.find((x) => x.kennung === zustand.buch)?.name || null,
      buecher: zustand.buecher,
      // Anfang eines Seitenpfads: im Buch #/m/<mandant>/b/<buch>, sonst #/m/<mandant>
      seitenBasis: mandant ? `#/m/${mandant}${zustand.buch ? `/b/${zustand.buch}` : ''}` : '#/system',
      buecherNeuLaden: async () => { await buecherLaden(); await menueLaden(); buchwahlZeichnen(); navZeichnen(); return zustand.buecher; },
      api,
      t: (k, ersatz = {}) => String(t[k] ?? k).replace(/\{(\w+)\}/g, (_, n) => ersatz[n] ?? `{${n}}`),
      darf: (recht) => (p.system ? zustand.menue.rechte_system : zustand.menue.rechte_mandant || []).includes(recht),
      navigieren: (pfad) => { location.hash = pfad; },
      melden: meldung.setzen,
      fehler: meldung.fehler,
      menueNeuLaden: async () => { await menueLaden(); navZeichnen(); },
      // Gibt es diesen Menüeintrag für den Nutzer? So erkennt eine Seite, ob ein anderes Modul aktiv und erlaubt ist.
      menueHat: (id) => (zustand.menue?.bereiche || []).some((b) => b.eintraege.some(function hat(e) { return e.id === id || (e.unter || []).some(hat); })),
      // Dateien, mit denen aktive Module eine Seite des Kerns erweitern (F102), z. B. Spalten der Kontenliste
      erweiterungen: (punkt) => zustand.menue?.erweiterungen?.[punkt] || [],
      // nach Änderungen am Profil: Profil neu laden und Einstellungen sofort anwenden
      ichNeuLaden: async () => { zustand.ich = await api('GET', '/api/v1/ich'); einstellungenAnwenden(); return zustand.ich; },
    };
    behaelter.replaceChildren();
    zustand.aufraeumen = await modul.zeigen(behaelter, kontext);
  } catch (e) {
    behaelter.replaceChildren();
    meldung.fehler(e);
  }
}

async function start() {
  try {
    zustand.ich = await api('GET', '/api/v1/ich');
  } catch (e) {
    if (e.status !== 401) { wurzel.replaceChildren(meldung); meldung.fehler(e); return; }
    zustand.ich = null;
  }
  if (!zustand.ich) { anmeldungZeigen(); return; }
  einstellungenAnwenden();
  // Überführter Bestand (U6): Vorname(n) und E-Mail fehlen noch und werden einmal abgefragt
  if (zustand.ich.angaben_fehlen) { angabenZeigen(); return; }
  zustand.mandant = zustand.ich.mandant || null;
  try {
    await buecherLaden();
    await menueLaden();
  } catch (e) { wurzel.replaceChildren(meldung); meldung.fehler(e); return; }
  rahmenZeigen();
  await seiteZeigen();
}

window.addEventListener('hashchange', () => { if (zustand.ich) seiteZeigen(); });
start();
