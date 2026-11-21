// SPDX-License-Identifier: AGPL-3.0-or-later
// Gemeinsames der Seiten der Zeiterfassung (ZEIT.md, F145): Zeiten als Text, Dialog, Monatsblatt.
import { h, knopf, datumText } from '/kern/ui/bausteine.js';
import { tabellenfilter } from '/kern/ui/tabellenfilter.js';

export function stil() {
  const css = new URL('./zeit.css', import.meta.url).href;
  if (!document.querySelector(`link[href="${css}"]`)) document.head.append(h('link', { rel: 'stylesheet', href: css }));
}

const FELDER = { art: 'Art', tag: 'Tag', von: 'Von', bis: 'Bis', begruendung: 'Begründung', grund: 'Grund', monat: 'Monat', modell: 'Modell', korrektur: 'Korrektur',
  anfangssaldo: 'Anfangssaldo', beginn: 'Beginn', pausen: 'Pausen', hoechst_minuten: 'Höchstzeit', rahmen_von: 'Rahmen von', rahmen_bis: 'Rahmen bis', kern_von: 'Kernzeit von', kern_bis: 'Kernzeit bis' };

/** Dialog mit Formular; schließt erst nach Erfolg, Fehler stehen an festem Platz. */
export function dialog(o) {
  const ja = h('button', { type: 'submit', class: 'knopf primaer', id: `${o.id}-senden` }, o.ja || 'Speichern');
  const fehler = h('p', { class: 'hinweis fehlertext', id: `${o.id}-fehler`, role: 'alert' }, ' ');
  const dlg = h('dialog', { class: ['dialog', o.breit ? 'ze-breit' : ''], id: o.id },
    h('form', { onsubmit: async (ev) => {
      ev.preventDefault();
      ja.disabled = true;
      fehler.textContent = ' ';
      try { await o.senden(); dlg.close(); } catch (x) {
        fehler.textContent = (x.problem?.fehler || []).map((f) => `${FELDER[f.feld] || f.feld}: ${f.meldung}`).join(' · ') || x.problem?.detail || x.message || 'Das hat nicht geklappt';
      } finally { ja.disabled = false; }
    } },
    h('h2', {}, o.titel), o.text ? h('p', { class: 'hinweis' }, o.text) : null, ...o.felder, fehler,
    h('div', { class: 'dialog-knoepfe' }, knopf('Abbrechen', () => dlg.close(), '', { id: `${o.id}-abbrechen` }), ja)));
  dlg.addEventListener('close', () => dlg.remove());
  document.body.append(dlg);
  dlg.showModal();
}

/** Minuten als Stunden und Minuten: 465 → „7:45“ */
export const dauer = (min) => `${Math.floor(Math.abs(min) / 60)}:${String(Math.abs(min) % 60).padStart(2, '0')}`;
/** Saldo mit Vorzeichen: 30 → „+0:30“, -120 → „−2:00“ */
export const saldoText = (min) => `${min < 0 ? '−' : '+'}${dauer(min)}`;
export const saldo = (min) => h('span', { 'data-zustand': min < 0 ? 'ze-minus' : '' }, saldoText(min));
/** „8:30“, „8,5“, „-2“ → Minuten; leer → 0; unlesbar → null */
export function minutenLesen(text) {
  const t = String(text || '').trim().replace('−', '-');
  if (t === '') return 0;
  let m = /^([+-]?)(\d{1,4}):([0-5]\d)$/.exec(t);
  if (m) return (m[1] === '-' ? -1 : 1) * (Number(m[2]) * 60 + Number(m[3]));
  m = /^([+-]?)(\d{1,4})(?:[.,](\d{1,2}))?$/.exec(t);
  return m ? (m[1] === '-' ? -1 : 1) * Math.round(Number(`${m[2]}.${m[3] || '0'}`) * 60) : null;
}

const MONATE = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
const TAGE = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];
export const monatText = (m) => (m ? `${MONATE[Number(m.slice(5, 7)) - 1]} ${m.slice(0, 4)}` : '');
export const tag = (iso) => (iso ? datumText(iso) : '');
export const wochentag = (iso) => TAGE[new Date(`${iso}T12:00:00`).getDay()];

/** Auswahl der letzten 24 Monate bis zum laufenden; der gewählte steht immer dabei. */
export function monatAuswahl(id, wert, wechsel) {
  const jetzt = new Date();
  const monate = Array.from({ length: 24 }, (_, i) => { const t = new Date(jetzt.getFullYear(), jetzt.getMonth() - i, 1); return `${t.getFullYear()}-${String(t.getMonth() + 1).padStart(2, '0')}`; });
  if (wert && !monate.includes(wert)) monate.push(wert);
  const s = h('select', { id, 'aria-label': 'Monat', onchange: () => wechsel(s.value) }, monate.map((m) => h('option', { value: m }, monatText(m))));
  if (wert) s.value = wert;
  return s;
}

export const MONATSSTAND = { offen: '○ offen', bestaetigt: '▲ von der Person bestätigt', freigegeben: '✓ freigegeben' };
const BRAUCHT = { ausserhalb: 'kein Regelarbeitstag – Genehmigung fehlt', ueber_hoechst: 'über der Höchstzeit – Sonderfreigabe fehlt' };
const ANTRAG = { nachtrag: 'Nachtrag', korrektur: 'Korrektur', entfernen: 'Entfernen' };

/** Text eines Antrags: Art, Tag, die beantragte Zeit (solange offen) und die Begründung – der alte Wert steht nirgends (ZE3). */
export const antragText = (x) => `${ANTRAG[x.art]} ${tag(x.tag)}${x.von ? `, ${x.von}–${x.bis}` : ''}: ${x.begruendung}`;

/**
 * Das Monatsblatt: je Tag die Abschnitte, Arbeit, Pause, was zählt, Soll und Saldo; darunter die Summen. Dieselbe
 * Darstellung unter „Meine Zeiten“ und für Vorgesetzte. b: Antwort der Schnittstelle, heute: ISO-Datum.
 */
/**
 * Das Monatsblatt ist eine Tabelle wie jede andere (F91, F146): Sortierung und Filter am Spaltenkopf, Spalten in Breite
 * und Reihenfolge verschiebbar, gemerkt je Browser. `id` ist der Merkname, `beiAenderung` zeichnet die Seite neu.
 * Liefert eine Funktion (blatt, heute) → Element. Die Summenzeile gilt immer für den ganzen Monat.
 */
export function blattTabelle(id, beiAenderung) {
  const vermerk = (t) => [t.feiertag ? `Feiertag: ${t.feiertag}` : null, t.frei ? `frei: ${t.frei}` : null, t.abwesenheit ? (t.abwesenheit < 1 ? 'halber Tag abwesend' : 'abwesend') : null].filter(Boolean);
  const zeiten = (t) => t.abschnitte.map((a) => `${a.von}–${a.bis || '…'}${a.korrigiert ? ' ✎' : ''}${a.herkunft === 'nachtrag' ? ' ⁺' : ''}`).join(' · ');
  const leer = (t) => !t.abschnitte.length;
  const zeigtSaldo = (t) => t.zaehlt && !(t.saldo === 0 && leer(t) && !t.soll);
  const hinweisText = (t) => [...vermerk(t), ...t.braucht.map((x) => BRAUCHT[x]), ...t.hinweise].join(' · ');
  const spalten = [
    { id: 'tag', titel: 'Tag', wert: (t) => `${wochentag(t.tag)} ${tag(t.tag)}`, sortwert: (t) => t.tag, zelle: (t) => h('td', { class: 'ze-tag' }, `${wochentag(t.tag)} ${tag(t.tag)}`) },
    { id: 'zeiten', titel: 'Zeiten', wert: zeiten, sortwert: (t) => t.abschnitte[0]?.von || '', zelle: (t) => h('td', {}, zeiten(t)) },
    { id: 'arbeit', titel: 'Arbeit', klasse: 'zahl', wert: (t) => (leer(t) ? '' : dauer(t.brutto)), sortwert: (t) => t.brutto, zelle: (t) => h('td', { class: 'zahl' }, leer(t) ? '' : dauer(t.brutto)) },
    { id: 'pause', titel: 'Pause', klasse: 'zahl', wert: (t) => (leer(t) ? '' : dauer(t.pause + t.abzug) + (t.abzug ? ' *' : '')), sortwert: (t) => t.pause + t.abzug,
      zelle: (t) => h('td', { class: 'zahl', title: t.abzug ? `gestempelt ${dauer(t.pause)}, nach der Regel abgezogen ${dauer(t.abzug)}` : '' }, leer(t) ? '' : dauer(t.pause + t.abzug) + (t.abzug ? ' *' : '')) },
    { id: 'zaehlt', titel: 'zählt', klasse: 'zahl', wert: (t) => (leer(t) && !t.gutschrift ? '' : dauer(t.gezaehlt + t.gutschrift)), sortwert: (t) => t.gezaehlt + t.gutschrift,
      zelle: (t) => h('td', { class: 'zahl' }, leer(t) && !t.gutschrift ? '' : dauer(t.gezaehlt + t.gutschrift)) },
    { id: 'soll', titel: 'Soll', klasse: 'zahl', wert: (t) => (t.soll ? dauer(t.soll) : ''), sortwert: (t) => t.soll, zelle: (t) => h('td', { class: 'zahl' }, t.soll ? dauer(t.soll) : '') },
    { id: 'saldo', titel: 'Saldo', klasse: 'zahl', wert: (t) => (zeigtSaldo(t) ? saldoText(t.saldo) : ''), sortwert: (t) => (zeigtSaldo(t) ? t.saldo : 0), zelle: (t) => h('td', { class: 'zahl' }, zeigtSaldo(t) ? saldo(t.saldo) : '') },
    { id: 'hinweise', titel: 'Hinweise', wert: hinweisText, zelle: (t) => h('td', {}, [...vermerk(t).map((x) => h('span', { 'data-zustand': 'ze-leise' }, x)),
      ...t.braucht.map((x) => h('span', { 'data-zustand': 'ze-wartet' }, `▲ ${BRAUCHT[x]}`)), ...t.hinweise.map((x) => h('span', { 'data-zustand': 'ze-fehlt' }, `! ${x}`))].flatMap((el, n) => (n ? [' · ', el] : [el]))) },
  ];
  const filter = tabellenfilter(spalten, beiAenderung, { id });
  return (b, heute) => {
    filter.setzeZeilen(b.tage);
    const zeilen = filter.anwenden(b.tage);
    const sp = filter.spalten();
    const s = b.summe;
    const summe = { tag: filter.aktiv() ? 'Summe des Monats (Liste gefiltert)' : 'Summe', zeiten: '', arbeit: dauer(s.brutto), pause: dauer(s.pause + s.abzug), zaehlt: dauer(s.gezaehlt + s.gutschrift), soll: dauer(s.soll), saldo: saldo(s.saldo),
      hinweise: '✎ korrigiert · ⁺ nachgetragen · * Pause nach der Regel abgezogen' };
    return h('div', { class: 'liste-scroll ze-blatt-rahmen' }, h('table', { class: 'liste ze-blatt', 'data-monat': b.monat },
      h('thead', {}, filter.kopf),
      h('tbody', {}, zeilen.length ? zeilen.map((t) => h('tr', { 'data-tag': t.tag, class: [!t.arbeitstag ? 'ze-frei' : '', t.tag === heute ? 'ze-heute' : ''] }, sp.map((x) => x.zelle(t))))
        : h('tr', { class: 'leer' }, h('td', { colspan: sp.length }, 'Kein Tag passt zum Filter.'))),
      h('tfoot', {}, h('tr', { class: 'ze-summe' }, sp.map((x) => h('td', { class: x.id === 'hinweise' ? 'ze-klein' : (x.klasse || '') }, summe[x.id]))))));
  };
}

/** Felder für Nachtrag, Korrektur oder Entfernen. o: {id, tage (des Monats), heute, feld, auswahl}; liefert {felder, json()}. */
export function antragFelder(o) {
  const abschnitte = o.tage.flatMap((t) => t.abschnitte.map((a) => [a.id, `${wochentag(t.tag)} ${tag(t.tag)}, ${a.von}–${a.bis || '…'}`]));
  const art = o.auswahl([['nachtrag', 'Nachtragen – ein Abschnitt fehlt'], ['korrektur', 'Korrigieren – ein Abschnitt stimmt nicht'], ['entfernen', 'Entfernen – ein Abschnitt ist falsch']], 'nachtrag', { id: `${o.id}-art` });
  const tagFeld = h('input', { id: `${o.id}-tag`, type: 'date', max: o.heute, value: o.heute });
  const abschnitt = o.auswahl(abschnitte.length ? abschnitte : [['', '– in diesem Monat ist nichts gebucht –']], abschnitte[0]?.[0] || '', { id: `${o.id}-abschnitt` });
  const von = h('input', { id: `${o.id}-von`, class: 'ze-uhr', type: 'time' });
  const bis = h('input', { id: `${o.id}-bis`, class: 'ze-uhr', type: 'time' });
  const grund = h('input', { id: `${o.id}-grund`, type: 'text', required: true, maxlength: '200', size: '48', autocomplete: 'off' });
  const zu = (el, warum) => { el.disabled = Boolean(warum); el.title = warum || ''; };
  const anpassen = () => {
    zu(tagFeld, art.value === 'nachtrag' ? null : 'Der Tag ergibt sich aus dem gewählten Abschnitt');
    zu(abschnitt, art.value === 'nachtrag' ? 'Ein Nachtrag ist ein neuer Abschnitt' : null);
    for (const el of [von, bis]) zu(el, art.value === 'entfernen' ? 'Beim Entfernen gibt es keine Uhrzeit' : null);
  };
  art.addEventListener('change', anpassen);
  anpassen();
  return {
    felder: [o.feld('Was', art), h('div', { class: 'ze-reihe' }, o.feld('Tag', tagFeld), o.feld('Abschnitt', abschnitt)),
      h('div', { class: 'ze-reihe' }, o.feld('Von', von), o.feld('Bis', bis, 'vor „Von“: bis in den nächsten Tag')), o.feld('Begründung', grund, 'bleibt sichtbar; keine Gesundheitsangaben')],
    json: () => {
      if (art.value !== 'nachtrag' && !abschnitt.value) throw new Error('Es gibt keinen Abschnitt, den du wählen könntest');
      if (art.value !== 'entfernen' && (!von.value || !bis.value)) throw new Error('Von und Bis gehören dazu');
      return { art: art.value, ...(art.value === 'nachtrag' ? { tag: tagFeld.value } : { abschnitt: abschnitt.value }),
        ...(art.value === 'entfernen' ? {} : { von: von.value, bis: bis.value }), begruendung: grund.value.trim() };
    },
  };
}
