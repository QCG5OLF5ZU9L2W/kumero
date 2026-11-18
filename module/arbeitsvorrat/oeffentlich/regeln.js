// SPDX-License-Identifier: AGPL-3.0-or-later
// Regeln des Arbeitsvorrats (ARBEITSVORRAT.md Abschnitt 7): Art → Geschäftsvorfall (gepflegt) und
// Gegenpartei → Konto (beim Buchen gemerkt, hier einsehbar und löschbar).
import { h, seitenkopf, knopf, auswahl, zeit } from '/kern/ui/bausteine.js';

export async function zeigen(b, k) {
  const css = new URL('./vorrat.css', import.meta.url).href;
  if (!document.querySelector(`link[href="${css}"]`)) document.head.append(h('link', { rel: 'stylesheet', href: css }));
  const t = k.t;
  const basis = `${k.apiBasis}/arbeitsvorrat`;
  let vorlagen = [];
  try { vorlagen = await k.api('GET', `${k.apiBasis}/buchen/vorlagen`); } catch { /* ohne Geschäftsvorfälle keine Regel */ }
  const vorlageName = (id) => { const v = vorlagen.find((x) => x.id === id); return v ? `${v.gruppe} – ${v.name}` : t('regeln.vorlage_fehlt'); };
  const inhalt = h('div');
  b.append(seitenkopf(t('regeln.titel')), inhalt);

  async function laden() {
    let r;
    try { r = await k.api('GET', `${basis}/regeln`); } catch (e) { k.fehler(e); return; }
    const art = h('input', { id: 'regel-art', maxlength: '40', list: 'regel-arten', placeholder: 'kassenbon', title: t('hilfe.art') });
    const vorlage = auswahl(vorlagen.map((v) => [v.id, `${v.gruppe} – ${v.name}`]), vorlagen[0]?.id, { id: 'regel-vorlage', title: t('regeln.hilfe_vorlage') });
    const tun = async (f, text) => { try { await f(); k.melden(text, 'erfolg'); await laden(); } catch (e) { k.fehler(e); } };
    let einstellungen = { auto_zusammenfuehren: false };
    try { einstellungen = await k.api('GET', `${basis}/einstellungen`); } catch { /* dann ohne Schalter */ }
    const auto = h('input', { type: 'checkbox', id: 'auto-zusammenfuehren', checked: einstellungen.auto_zusammenfuehren, disabled: !k.darf('arbeitsvorrat.regel.pflegen') });
    auto.addEventListener('change', () => tun(() => k.api('PUT', `${basis}/einstellungen`, { json: { auto_zusammenfuehren: auto.checked } }), t('meldung.einstellung')));
    inhalt.replaceChildren(
      h('div', { class: 'karte', id: 'regeln-auto' }, h('h2', {}, t('regeln.auto')), h('p', { class: 'hinweis' }, t('regeln.auto_text')), h('label', { class: 'feld-wahl' }, auto, t('regeln.auto_schalter'))),
      h('div', { class: 'karte', id: 'regeln-arten' }, h('h2', {}, t('regeln.arten')), h('p', { class: 'hinweis' }, t('regeln.arten_hinweis')),
        h('table', { class: 'liste' }, h('thead', {}, h('tr', {}, h('th', {}, t('feld.art')), h('th', {}, t('regeln.vorlage')), h('th', {}))),
          h('tbody', {}, r.arten.length ? r.arten.map((x) => h('tr', {}, h('td', { class: 'nr' }, x.art), h('td', {}, vorlageName(x.vorlage)),
            h('td', { class: 'eng' }, knopf(t('aktion.loeschen'), () => tun(() => k.api('DELETE', `${basis}/regeln/arten/${encodeURIComponent(x.art)}`), t('meldung.regel_geloescht')), 'klein'))))
            : h('tr', { class: 'leer' }, h('td', { colspan: '3' }, t('regeln.keine'))))),
        vorlagen.length ? h('form', { class: 'regel-zeile', onsubmit: (ev) => {
          ev.preventDefault();
          const a = art.value.trim().toLowerCase();
          if (!/^[a-z][a-z0-9_-]{0,39}$/.test(a)) { k.melden(t('hinweis.art'), 'fehler'); return; }
          tun(() => k.api('PUT', `${basis}/regeln/arten/${encodeURIComponent(a)}`, { json: { vorlage: vorlage.value } }), t('meldung.regel_gespeichert'));
        } }, art, h('datalist', { id: 'regel-arten' }, r.bekannte_arten.map((x) => h('option', { value: x }))), h('span', {}, '→'), vorlage,
        h('button', { type: 'submit', class: 'knopf primaer', id: 'regel-speichern' }, t('aktion.speichern')))
          : h('p', { class: 'hinweis' }, t('regeln.keine_vorlagen'))),
      h('div', { class: 'karte', id: 'regeln-gegenparteien' }, h('h2', {}, t('regeln.gegenparteien')), h('p', { class: 'hinweis' }, t('regeln.gegenparteien_hinweis')),
        h('table', { class: 'liste' }, h('thead', {}, h('tr', {}, h('th', {}, t('feld.gegenpartei')), h('th', {}, t('regeln.konto')), h('th', {}, t('regeln.gemerkt_am')), h('th', {}))),
          h('tbody', {}, r.gegenparteien.length ? r.gegenparteien.map((x) => h('tr', {}, h('td', {}, x.gegenpartei), h('td', { class: 'nr' }, x.konto), h('td', { class: 'eng' }, zeit(x.geaendert_am)),
            h('td', { class: 'eng' }, knopf(t('aktion.loeschen'), () => tun(() => k.api('POST', `${basis}/regeln/gegenparteien/loeschen`, { json: { schluessel: x.schluessel } }), t('meldung.regel_geloescht')), 'klein'))))
            : h('tr', { class: 'leer' }, h('td', { colspan: '4' }, t('regeln.keine_gemerkt')))))));
  }
  await laden();
}
