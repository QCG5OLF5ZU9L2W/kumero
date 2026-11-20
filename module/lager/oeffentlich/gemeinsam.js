// SPDX-License-Identifier: AGPL-3.0-or-later
// Gemeinsames der Lagerseiten (LAGER.md): Dialog, Mengen, Zustände in Worten, Zerlegen des Codes einer Packung.
import { h, knopf } from '/kern/ui/bausteine.js';

export const ARTEN = [['medizinprodukt', 'Medizinprodukt'], ['arzneimittel', 'Arzneimittel'], ['verbrauch', 'Verbrauch'], ['sonstiges', 'sonstiges']];
export const FUEHRUNG = [['charge', 'mit Charge'], ['serie', 'mit Seriennummer (je Stück)'], ['ohne', 'ohne Charge']];
export const ZUSTAND = { abgelaufen: ['abgelaufen', 'fehler'], gesperrt: ['gesperrt', 'fehler'], laeuft_ab: ['läuft ab', 'warnung'], unter_mindest: ['unter Mindestbestand', 'warnung'],
  frei: ['frei', ''], in_ordnung: ['in Ordnung', ''] };
/** Codes für Einheiten aus E-Rechnungen (UN/ECE Rec. 20) in Worten; Unbekanntes bleibt, wie es ist. */
const EINHEITEN = { C62: 'Stück', H87: 'Stück', XPK: 'Packung', PK: 'Packung', XCT: 'Karton', CT: 'Karton', XBX: 'Schachtel', BX: 'Schachtel', LTR: 'Liter', MLT: 'ml', KGM: 'kg', GRM: 'g', MTR: 'm', SET: 'Satz', PR: 'Paar', XBO: 'Flasche' };
export const einheitText = (code) => EINHEITEN[code] || code || '';

const zahl = new Intl.NumberFormat('de-DE', { maximumFractionDigits: 3 });
export const mengeText = (m) => (m === null || m === undefined || m === '' ? '' : zahl.format(Number(m)));
/** „12“, „0,5“ oder „1.250,5“ → „12“, „0.5“, „1250.5“; null, wenn es keine Menge größer null ist. */
export function mengeLesen(text) {
  let s = String(text ?? '').replace(/\s/g, '');
  if (s === '') return null;
  if (s.includes(',')) s = s.replace(/\./g, '').replace(',', '.');
  if (!/^\d{1,12}(\.\d{1,3})?$/.test(s) || Number(s) <= 0) return null;
  return s.includes('.') ? s.replace(/0+$/, '').replace(/\.$/, '') : s;
}

/**
 * Zerlegt den Code einer Packung (GS1, WW16): Produktkennung (01), Verfall (17), Charge (10), Seriennummer (21).
 * Liest die Klammerschreibweise und die Rohfolge eines Scanners; im Browser, ohne Dienst außerhalb.
 * Liefert {gtin?, verfall?, charge?, serie?} oder null, wenn es kein solcher Code ist.
 */
export function codeLesen(text) {
  let s = String(text ?? '').trim().replace(/^\](d2|C1|Q3|e0)/, '');
  if (s === '') return null;
  const erg = {};
  const setzen = (ai, w) => {
    if (ai === '01' && /^\d{14}$/.test(w)) erg.gtin = w;
    if (ai === '17' && /^\d{6}$/.test(w)) {
      const j = 2000 + Number(w.slice(0, 2)); const m = Number(w.slice(2, 4)); let t = Number(w.slice(4, 6));
      if (m < 1 || m > 12) return;
      if (t === 0) t = new Date(j, m, 0).getDate();   // Tag 00: letzter Tag des Monats
      if (t > new Date(j, m, 0).getDate()) return;
      erg.verfall = `${j}-${String(m).padStart(2, '0')}-${String(t).padStart(2, '0')}`;
    }
    if (ai === '10' && w.length >= 1 && w.length <= 20) erg.charge = w;
    if (ai === '21' && w.length >= 1 && w.length <= 20) erg.serie = w;
  };
  if (s.includes('(')) {
    for (const m of s.matchAll(/\((\d{2,4})\)([^(]*)/g)) setzen(m[1], m[2].trim());
  } else {
    const FEST = { '00': 18, '01': 14, '02': 14, 11: 6, 13: 6, 15: 6, 17: 6 };
    let i = 0;
    while (i < s.length - 1) {
      if (s[i] === '\u001d') { i += 1; continue; }
      const ai = s.slice(i, i + 2);
      i += 2;
      if (FEST[ai] !== undefined) { setzen(ai, s.slice(i, i + FEST[ai])); i += FEST[ai]; continue; }
      if (ai !== '10' && ai !== '21') return Object.keys(erg).length ? erg : null;
      let ende = s.indexOf('\u001d', i);
      if (ende === -1) ende = s.length;
      setzen(ai, s.slice(i, ende));
      i = ende;
    }
  }
  return Object.keys(erg).length ? erg : null;
}

/** „3970-3989, 39*, 3990“ → Prüffunktion für Kontonummern (wie bei den Anlagekonten). */
export function kontenFilter(text) {
  const teile = String(text || '').split(/[,;\s]+/).filter(Boolean);
  if (!teile.length) return () => false;
  const num = (s) => (/^\d+$/.test(s) ? Number(s) : null);
  const regeln = teile.map((t) => {
    if (t.endsWith('*')) return (nr) => nr.startsWith(t.slice(0, -1));
    const m = t.split(/[-–]/);
    if (m.length === 2 && m[0] && m[1]) return (nr) => (num(m[0]) !== null && num(m[1]) !== null && num(nr) !== null ? num(nr) >= num(m[0]) && num(nr) <= num(m[1]) : nr >= m[0] && nr <= m[1]);
    return (nr) => nr === t;
  });
  return (nr) => regeln.some((r) => r(nr));
}

/** Dialog mit Formular: o = {id, titel, text?, felder, ja, senden(), breit?, zusatz?, ohneJa?}. Schließt nach senden(). */
export function dialog(o) {
  const ja = h('button', { type: 'submit', class: 'knopf primaer', id: `${o.id}-senden` }, o.ja || 'Speichern');
  const fehler = h('p', { class: 'hinweis fehlertext', id: `${o.id}-fehler`, role: 'alert' }, ' ');
  const dlg = h('dialog', { class: ['dialog', o.breit ? 'lg-breit' : ''], id: o.id },
    h('form', { onsubmit: async (ev) => {
      ev.preventDefault();
      if (o.ohneJa) return;
      ja.disabled = true;
      try { await o.senden(); dlg.close(); } catch (x) {
        fehler.textContent = (x.problem?.fehler || []).map((f) => f.meldung).join(' · ') || x.problem?.detail || x.message || 'Das hat nicht geklappt';
      } finally { ja.disabled = false; }
    } },
    h('h2', {}, o.titel), o.text ? h('p', { class: 'hinweis' }, o.text) : null, ...o.felder, fehler,
    h('div', { class: 'dialog-knoepfe' }, ...(o.zusatz || []), knopf(o.ohneJa ? 'Schließen' : 'Abbrechen', () => dlg.close(), '', { id: `${o.id}-abbrechen` }), o.ohneJa ? null : ja)));
  dlg.addEventListener('close', () => dlg.remove());
  document.body.append(dlg);
  dlg.showModal();
  return { dlg, ja, fehler };
}

export function stil(k) {
  const css = new URL('./lager.css', import.meta.url).href;
  if (!document.querySelector(`link[href="${css}"]`)) document.head.append(h('link', { rel: 'stylesheet', href: css }));
}
