// SPDX-License-Identifier: AGPL-3.0-or-later
// Kontoblatt: Anfangssaldo, Bewegungen mit laufendem Saldo, Endsaldo – nur festgeschriebene Buchungen.
import { h, seitenkopf, knopf, datumFeld } from '/kern/ui/bausteine.js';
import { stilLaden, betragZeigen, cent, centZeigen, datumZeigen, heute, stammdatenLaden, geschaeftsjahrZu, kontoSuchen, kontoVorschau } from './gemeinsam.js';

export async function zeigen(b, k) {
  stilLaden();
  const t = k.t;
  const s = await stammdatenLaden(k);
  const g = geschaeftsjahrZu(s.geschaeftsjahre, heute());
  const konto = h('input', { id: 'kb-konto', class: 'konto-nr', maxlength: '20', value: k.param.konto || '', 'aria-label': t('feld.konto'), title: t('hilfe.konto'), autocomplete: 'off' });
  const kontoEingabe = h('span', { class: 'konto-eingabe' }, konto);
  kontoVorschau(konto, s.konten);
  const optionen = { kalenderTitel: t('hilfe.kalender'), ungueltig: t('hinweis.datum') };
  const von = datumFeld({ id: 'kb-von', value: k.param.von || g?.beginn || `${heute().slice(0, 4)}-01-01`, 'aria-label': t('feld.von'), title: t('hilfe.von') }, optionen);
  const bis = datumFeld({ id: 'kb-bis', value: k.param.bis || g?.ende || `${heute().slice(0, 4)}-12-31`, 'aria-label': t('feld.bis'), title: t('hilfe.bis') }, optionen);
  const inhalt = h('div', { id: 'kontoblatt' });
  const suchen = async () => {
    const nr = await kontoSuchen(s.konten, t, s.konto(konto.value.trim()) ? '' : konto.value.trim());
    if (nr) { konto.value = nr; laden(); }
  };
  konto.addEventListener('keydown', (ev) => { if (ev.key === 'F2') { ev.preventDefault(); suchen(); } });

  b.append(seitenkopf(t('kontoblatt.titel', { mandant: k.mandantName || k.mandant }),
    kontoEingabe, knopf('…', suchen, 'klein', { title: t('suche.kurz'), tabindex: '-1' }), von.el, h('span', {}, '–'), bis.el,
    knopf(t('aktion.zur_liste'), () => k.navigieren(`#/m/${k.mandant}/buchen/liste`))), inhalt);

  const saldo = (c) => `${centZeigen(Math.abs(c))} ${c === 0 ? '' : (c > 0 ? 'S' : 'H')}`.trim();
  const vorzeichen = (x) => (x.seite === 'S' ? 1 : -1) * cent(x.betrag);

  async function laden() {
    const nr = konto.value.trim();
    if (!nr) { inhalt.replaceChildren(h('p', { class: 'hinweis' }, t('kontoblatt.waehlen'))); return; }
    const vonTag = von.wert();
    const bisTag = bis.wert();
    if (!vonTag || !bisTag) { inhalt.replaceChildren(h('p', { class: 'hinweis' }, t('hinweis.datum'))); return; }
    try {
      let weiter = null;
      let blatt = null;
      const bewegungen = [];
      do {
        const r = await k.api('GET', `${k.apiBasis}/konten/${encodeURIComponent(nr)}/kontoblatt?von=${vonTag}&bis=${bisTag}&anzahl=500${weiter ? `&cursor=${encodeURIComponent(weiter)}` : ''}`);
        blatt = blatt || r;
        bewegungen.push(...r.bewegungen);
        weiter = r.weiter;
      } while (weiter && bewegungen.length < 5000);
      let lauf = vorzeichen(blatt.anfangssaldo);
      const zeilen = bewegungen.map((x) => {
        lauf += vorzeichen(x);
        return h('tr', { tabindex: '-1', ondblclick: () => k.navigieren(`#/m/${k.mandant}/buchen/erfassen?id=${x.buchung}`) },
          h('td', { class: 'eng' }, datumZeigen(x.buchungsdatum)), h('td', { class: 'nr breit' }, x.nr), h('td', {}, x.belegnummer || ''),
          h('td', {}, h('span', { class: 'einzeilig' }, x.text)), h('td', { class: 'nr' }, x.gegenkonten.length === 1 ? x.gegenkonten[0] : t('liste.splitt')),
          h('td', { class: 'zahl' }, x.seite === 'S' ? betragZeigen(x.betrag) : ''), h('td', { class: 'zahl' }, x.seite === 'H' ? betragZeigen(x.betrag) : ''),
          h('td', { class: 'zahl' }, saldo(lauf)));
      });
      const summe = (seite) => centZeigen(bewegungen.filter((x) => x.seite === seite).reduce((n, x) => n + cent(x.betrag), 0));
      const randzeile = (text, wert) => h('tr', { class: 'saldozeile' }, h('td', { colspan: '7' }, text), h('td', { class: 'zahl' }, wert));
      inhalt.replaceChildren(
        h('h2', {}, `${blatt.konto.nr} ${blatt.konto.bezeichnung}`),
        h('div', { class: 'liste-rahmen' }, h('div', { class: 'liste-scroll' }, h('table', { class: 'liste' },
          h('thead', {}, h('tr', {}, [t('spalte.datum'), t('spalte.nr'), t('spalte.belegnr'), t('spalte.text'), t('spalte.gegenkonto'), t('spalte.soll'), t('spalte.haben'), t('spalte.saldo')]
            .map((x, i) => h('th', { class: i >= 5 ? 'zahl' : '' }, x)))),
          h('tbody', {}, randzeile(t('kontoblatt.anfang', { datum: datumZeigen(blatt.von) }), saldo(vorzeichen(blatt.anfangssaldo))), zeilen,
            bewegungen.length ? null : h('tr', { class: 'leer' }, h('td', { colspan: '8' }, t('kontoblatt.leer'))),
            randzeile(t('kontoblatt.ende', { datum: datumZeigen(blatt.bis) }), saldo(vorzeichen(blatt.endsaldo)))))),
        h('div', { class: 'liste-fuss' }, h('span', {}, t('fuss.anzahl', { n: bewegungen.length })), h('span', {}, t('kontoblatt.summen', { soll: summe('S'), haben: summe('H') })),
          weiter ? h('span', { class: 'fuss-warnung' }, t('fuss.mehr')) : null)));
    } catch (e) {
      inhalt.replaceChildren();
      k.fehler(e);
    }
  }
  for (const e of [konto, von.ein, bis.ein]) e.addEventListener('change', laden);
  await laden();
}
