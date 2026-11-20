// SPDX-License-Identifier: AGPL-3.0-or-later
// Rahmen der Weboberfläche (OBERFLAECHE.md 2, 4, 5; F80): Kopf, Menü aus der API, Router, Seiten der Module.
import { api, anmelden, passkeyRegistrieren } from './api.js';
import { h, knopf, meldungsleiste, feld, sperren, umgebung } from './bausteine.js';

const zustand = { ich: null, mandant: null, menue: null, texte: {}, aufraeumen: null };
const wurzel = document.getElementById('app');
const meldung = meldungsleiste();

// Eine Identität gehört zu genau einem Mandanten (F114): Der Passkey bestimmt bei der Anmeldung, in welchen
// Mandanten es geht. Eine Auswahl gibt es nicht mehr – wer in einen anderen will, meldet sich dort an (U3).
// System-Verwalter haben keinen Mandanten.

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
  zustand.menue = await api('GET', m ? `/m/${m}/api/v1/oberflaeche/menue` : '/api/v1/oberflaeche/menue');
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
  const bez = h('input', { placeholder: 'z. B. KeePassXC Arbeitsplatz', maxlength: '100' });
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

function rahmenZeigen() {
  const ich = zustand.ich;
  const m = ich.mandanten[0];
  // Fester Platz für den Mandanten (F106); wechseln heißt neu anmelden (U3)
  const wo = h('strong', { id: 'mandant-name', 'data-mandant': m?.kennung || '',
    title: m ? 'Diese Anmeldung gilt für diesen Mandanten. In einen anderen Mandanten kommst du, indem du dich abmeldest und dich mit dem Passkey dieses Mandanten anmeldest.'
      : 'System-Verwalter arbeiten für die Installation, nicht in einem Mandanten.' },
  m ? m.name + (m.betriebsart === 'test' ? ' (Test)' : '') : 'Verwaltung der Installation');

  const kopf = h('header', { class: 'kopf' },
    h('a', { class: 'marke', href: '#/system/kern/start' }, 'kumero'),
    h('div', { class: 'kopf-mandant' }, m ? h('span', {}, 'Mandant') : null, wo),
    // Meldungen stehen in der Statusleiste (F106): fester Platz, der Inhalt der Seite verschiebt sich nie
    h('div', { class: 'kopf-meldung' }, meldung),
    ich.verwalter ? h('span', { class: 'marke-verwalter' }, 'Verwalter') : null,
    nutzermenue(ich));
  wurzel.replaceChildren(kopf, h('div', { class: 'koerper' }, h('nav', { class: 'navigation', id: 'navigation', 'aria-label': 'Menü' }),
    h('main', { class: 'inhalt' }, h('div', { id: 'seite' }))));
  navZeichnen();
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
    await menueLaden();
  } catch (e) { wurzel.replaceChildren(meldung); meldung.fehler(e); return; }
  rahmenZeigen();
  await seiteZeigen();
}

window.addEventListener('hashchange', () => { if (zustand.ich) seiteZeigen(); });
start();
