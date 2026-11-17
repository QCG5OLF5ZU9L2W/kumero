// SPDX-License-Identifier: AGPL-3.0-or-later
// Profil und Einstellungen des Nutzers (PROFIL.md, erste Stufe): Sprache, Betragseingabe, Schriftgröße, Kontrast.
// Die Einstellungen liegen am Nutzer in der Verwaltungsdatenbank und gelten auf jedem Gerät.
import { h, seitenkopf, feld, auswahl } from '../ui/bausteine.js';

export async function zeigen(b, k) {
  const t = k.t;
  let ich = k.ich;
  const inhalt = h('div');
  b.append(seitenkopf(t('profil.titel')), inhalt);

  const speichern = async (f) => {
    try {
      await f();
      ich = await k.ichNeuLaden();
      k.melden(t('profil.gespeichert'), 'erfolg');
    } catch (e) { k.fehler(e); }
    zeichnen();
  };
  const einstellung = (schluessel, werte) => auswahl(werte.map((w) => [w, t(`profil.${schluessel}.${w}`)]), ich.einstellungen[schluessel],
    { id: schluessel.replace('.', '-'), onchange: (ev) => speichern(() => k.api('PUT', '/api/v1/ich/einstellungen', { json: { [schluessel]: ev.target.value } })) });

  function zeichnen() {
    const sprache = auswahl([['', t('profil.sprache.browser', { sprache: navigator.language })], ...ich.sprachen.map((s) => [s, t(`profil.sprache.${s}`)])],
      ich.sprache || '', { id: 'profil-sprache', onchange: (ev) => speichern(() => k.api('PATCH', '/api/v1/ich', { json: { sprache: ev.target.value || null } })) });
    const lokal = ich.sprache || navigator.language;
    inhalt.replaceChildren(
      h('div', { class: 'karte' }, h('h2', {}, t('profil.abschnitt.profil')),
        h('dl', { class: 'eigenschaften' },
          h('dt', {}, t('profil.name')), h('dd', {}, ich.name, ich.verwalter ? h('span', { class: 'marke-klein' }, t('profil.verwalter')) : null),
          h('dt', {}, t('profil.subjekt')), h('dd', {}, h('code', {}, ich.subjekt)),
          h('dt', {}, t('profil.mandanten')), h('dd', {}, ich.mandanten.length ? ich.mandanten.map((m) => m.name).join(', ') : '–')),
        h('p', { class: 'hinweis' }, t('profil.name_hinweis'))),
      h('div', { class: 'karte' }, h('h2', {}, t('profil.abschnitt.sprache')),
        feld(t('profil.sprache'), sprache, t('profil.sprache_hinweis')),
        h('p', { class: 'hinweis', id: 'profil-datumsbeispiel' }, t('profil.beispiel', { datum: new Intl.DateTimeFormat(lokal, { day: '2-digit', month: '2-digit', year: 'numeric' }).format(new Date()) }))),
      h('div', { class: 'karte' }, h('h2', {}, t('profil.abschnitt.darstellung')),
        feld(t('profil.kern.betragseingabe'), einstellung('kern.betragseingabe', ['dezimal', 'kaufmaennisch']), t('profil.betragseingabe_hinweis'))),
      h('div', { class: 'karte' }, h('h2', {}, t('profil.abschnitt.lesbarkeit')),
        feld(t('profil.kern.schriftgroesse'), einstellung('kern.schriftgroesse', ['normal', 'gross', 'sehr_gross'])),
        feld(t('profil.kern.kontrast'), einstellung('kern.kontrast', ['system', 'normal', 'hoch']), t('profil.kontrast_hinweis'))));
  }
  zeichnen();
}
