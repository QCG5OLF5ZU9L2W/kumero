// SPDX-License-Identifier: AGPL-3.0-or-later
// Rahmen der Weboberfläche (OBERFLAECHE.md 2, 4, 5; F80): Kopf, Menü aus der API, Router, Seiten der Module.
import { api, anmelden, passkeyRegistrieren } from './api.js';
import { h, knopf, meldungsleiste, feld } from './bausteine.js';

const zustand = { ich: null, mandant: null, menue: null, texte: {}, aufraeumen: null };
const wurzel = document.getElementById('app');
const meldung = meldungsleiste();

function merken(mandant) {
  try { localStorage.setItem('kumero.mandant', mandant || ''); } catch { /* ohne Speicher weiter */ }
}
function gemerkt() {
  try { return localStorage.getItem('kumero.mandant') || null; } catch { return null; }
}

// ── Pfade: #/system/<modul>/<ziel>?… oder #/m/<mandant>/<modul>/<ziel>?… ──
function pfadLesen() {
  const roh = location.hash.replace(/^#/, '') || '/system/kern/start';
  const [weg, frage = ''] = roh.split('?');
  const t = weg.split('/').filter(Boolean);
  const param = Object.fromEntries(new URLSearchParams(frage));
  if (t[0] === 'm' && t.length >= 4) return { system: false, mandant: t[1], modul: t[2], ziel: t[3], param, roh };
  if (t[0] === 'system' && t.length >= 3) return { system: true, mandant: null, modul: t[1], ziel: t[2], param, roh };
  return { system: true, mandant: null, modul: 'kern', ziel: 'start', param: {}, roh: '/system/kern/start' };
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
  try {
    zustand.menue = await api('GET', m ? `/m/${m}/api/v1/oberflaeche/menue` : '/api/v1/oberflaeche/menue');
  } catch (e) {
    if (!m) throw e;
    meldung.setzen(`Mandant ${m}: ${e.message}`, 'warnung');
    zustand.mandant = null;
    merken(null);
    zustand.menue = await api('GET', '/api/v1/oberflaeche/menue');
  }
}

// ── Anzeige ────────────────────────────────────────────────────────────────
function anmeldungZeigen() {
  const token = new URLSearchParams(location.hash.replace(/^#/, '')).get('registrierung');
  const bez = h('input', { placeholder: 'z. B. KeePassXC Arbeitsplatz', maxlength: '100' });
  const inhalt = token
    ? h('form', { class: 'anmeldung', onsubmit: async (ev) => {
      ev.preventDefault();
      try {
        await passkeyRegistrieren(token, bez.value);
        history.replaceState(null, '', location.pathname);
        meldung.setzen('Passkey registriert. Jetzt anmelden.', 'erfolg');
        anmeldungZeigen();
      } catch (e) { meldung.fehler(e); }
    } },
    h('h1', {}, 'Passkey registrieren'),
    h('p', {}, 'Dieser Link ist 15 Minuten gültig und nur einmal verwendbar. Der Browser fragt gleich nach deinem Passkey.'),
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

function rahmenZeigen() {
  const ich = zustand.ich;
  const wahl = h('select', { id: 'mandant-wahl', 'aria-label': 'Mandant', onchange: async () => {
    zustand.mandant = wahl.value || null;
    merken(zustand.mandant);
    await menueLaden();
    navZeichnen();
    const p = pfadLesen();
    // Mandantenseite: im neuen Mandanten dieselbe Seite öffnen
    if (!p.system && zustand.mandant) location.hash = `#/m/${zustand.mandant}/${p.modul}/${p.ziel}`;
    else seiteZeigen();
  } },
  h('option', { value: '' }, ich.mandanten.length ? '– Mandant wählen –' : '– kein Mandant –'),
  ich.mandanten.map((m) => h('option', { value: m.kennung }, m.name + (m.betriebsart === 'test' ? ' (Test)' : ''))));
  wahl.value = zustand.mandant || '';

  const kopf = h('header', { class: 'kopf' },
    h('a', { class: 'marke', href: '#/system/kern/start' }, 'kumero'),
    h('label', { class: 'kopf-mandant' }, h('span', {}, 'Mandant'), wahl),
    h('span', { class: 'kopf-luecke' }),
    h('span', { class: 'kopf-nutzer' }, ich.name, ich.verwalter ? h('span', { class: 'marke-verwalter' }, 'Verwalter') : null),
    knopf('Abmelden', async () => {
      try { await api('POST', '/api/v1/abmeldung'); } finally { zustand.ich = null; anmeldungZeigen(); }
    }, 'leise'));
  wurzel.replaceChildren(kopf, h('div', { class: 'koerper' }, h('nav', { class: 'navigation', id: 'navigation', 'aria-label': 'Menü' }),
    h('main', { class: 'inhalt' }, meldung, h('div', { id: 'seite' }))));
  navZeichnen();
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
        h('span', { class: 'nav-titel' }, e.titel)),
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
  meldung.setzen('');

  // Mandant aus dem Pfad übernehmen (Lesezeichen, Links)
  if (!p.system && p.mandant !== zustand.mandant) {
    if (!zustand.ich.mandanten.some((m) => m.kennung === p.mandant)) {
      behaelter.replaceChildren(h('p', {}, 'Mandant unbekannt oder kein Zugang.'));
      return;
    }
    zustand.mandant = p.mandant;
    merken(p.mandant);
    document.getElementById('mandant-wahl').value = p.mandant;
    await menueLaden();
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
      mandant,
      mandantName: zustand.ich.mandanten.find((m) => m.kennung === mandant)?.name || null,
      system: p.system,
      param: p.param,
      apiBasis: p.system ? '/api/v1' : `/m/${p.mandant}/api/v1`,
      api,
      t: (k, ersatz = {}) => String(t[k] ?? k).replace(/\{(\w+)\}/g, (_, n) => ersatz[n] ?? `{${n}}`),
      darf: (recht) => (p.system ? zustand.menue.rechte_system : zustand.menue.rechte_mandant || []).includes(recht),
      navigieren: (pfad) => { location.hash = pfad; },
      melden: meldung.setzen,
      fehler: meldung.fehler,
      menueNeuLaden: async () => { await menueLaden(); navZeichnen(); },
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
  const p = pfadLesen();
  const vorschlag = !p.system ? p.mandant : gemerkt();
  zustand.mandant = zustand.ich.mandanten.some((m) => m.kennung === vorschlag) ? vorschlag
    : (zustand.ich.mandanten.length === 1 ? zustand.ich.mandanten[0].kennung : null);
  await menueLaden();
  rahmenZeigen();
  await seiteZeigen();
}

window.addEventListener('hashchange', () => { if (zustand.ich) seiteZeigen(); });
start();
