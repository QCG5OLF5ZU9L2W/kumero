// SPDX-License-Identifier: AGPL-3.0-or-later
// „Meine Zeiten“ (ZEIT.md Abschnitt 9, F145): Kommen und Gehen stempeln, das eigene Monatsblatt und Zeitkonto sehen,
// nachtragen und berichtigen lassen, den Monat bestätigen. Knöpfe stehen fest und nennen gesperrt den Grund (F106).
import { h, seitenkopf, knopf, feld, auswahl, sperren, bestaetigen } from '/kern/ui/bausteine.js';
import { stil, dialog, saldo, monatAuswahl, monatText, blattTabelle, antragFelder, antragText, MONATSSTAND, BRAUCHT, ORTE, ortText, tag } from './gemeinsam.js';

export async function zeigen(b, k) {
  stil();
  b.classList.add('zeit');
  const api = `${k.mandantApi}/zeit/meine`;
  let d = { person: null };
  let monat = null;
  // wo gearbeitet wird (F151): gilt für den Abschnitt, der mit „Kommen“ beginnt
  const ortWahl = auswahl(ORTE, 'betrieb', { id: 'zm-ort', class: 'ze-ort', 'aria-label': 'Wo gearbeitet wird' });
  const kn = {
    kommen: knopf('Kommen', () => stempeln('kommen'), 'primaer', { id: 'zm-kommen', title: 'Beginnt einen Abschnitt Arbeit – jetzt, auf die Minute' }),
    gehen: knopf('Gehen', () => stempeln('gehen'), 'primaer', { id: 'zm-gehen', title: 'Beendet den laufenden Abschnitt – auch für eine Pause: danach wieder „Kommen“' }),
    antrag: knopf('Nachtragen oder berichtigen …', antragDialog, '', { id: 'zm-antrag', title: 'Ein Abschnitt fehlt oder stimmt nicht – mit Begründung' }),
    monat: knopf('Monat bestätigen', bestaetigenMonat, '', { id: 'zm-monat', title: 'Bestätigt, dass das Monatsblatt stimmt – danach nimmt der Monat nichts mehr an' }),
  };
  const inhalt = h('div', { id: 'zm-inhalt' });

  const blattT = blattTabelle('zeit.meine.blatt', () => zeichnen(), { beiDoppelklick: (t) => tagDialog(t) });
  // Die angelaufene Arbeitszeit rechnet nur der Browser weiter (F150): aus dem Stand beim Laden und seiner eigenen Uhr – keine Abfrage am Server.
  let uhr = null; let geladenUm = 0;
  const minuten = (hhmm) => Number(hhmm.slice(0, 2)) * 60 + Number(hhmm.slice(3, 5));
  const laeuft = () => {
    const el = document.getElementById('zm-laeuft');
    if (!el || !d.eingestempelt) return;
    let m = minuten(d.jetzt.slice(11, 16)) - minuten(d.eingestempelt.von) + Math.floor((Date.now() - geladenUm) / 60000);
    if (d.eingestempelt.tag !== d.heute) m += 1440 * Math.round((Date.parse(d.heute) - Date.parse(d.eingestempelt.tag)) / 86400000);
    el.textContent = ` · läuft seit ${Math.floor(Math.max(0, m) / 60)}:${String(Math.max(0, m) % 60).padStart(2, '0')} Std.`;
  };
  // Einzelheiten eines Tages: die Abschnitte mit ihrer Anmerkung
  function tagDialog(t) {
    const zu = d.monatsstand.zustand !== 'offen' && d.einstellung.monatsabschluss !== 'aus' ? 'Der Monat ist abgeschlossen' : null;
    const felder = t.abschnitte.map((a) => ({ a, ein: h('input', { type: 'text', id: `zm-n-${a.id}`, maxlength: '200', size: '48', autocomplete: 'off', value: a.anmerkung || '', disabled: Boolean(zu), title: zu || '' }) }));
    dialog({ id: 'zm-dialog-tag', titel: `${tag(t.tag)} – Einzelheiten`, ja: 'Speichern', breit: true, ohneJa: felder.length === 0 || Boolean(zu),
      text: felder.length ? 'Zu jedem Abschnitt gehört eine Anmerkung – was getan wurde, wo, wofür. Keine Gesundheitsangaben.' : 'An diesem Tag ist nichts gestempelt. Ein fehlender Abschnitt kommt über „Nachtragen oder berichtigen …“.',
      felder: [...felder.map(({ a, ein }) => h('label', { class: 'ze-anm-zeile' }, h('span', { class: 'ze-anm-zeit' }, `Kommen ${a.von} · Gehen ${a.bis || '…'}${ortText(a.ort)}`), ein)),
        // was an dem Tag auf eine Genehmigung wartet – entscheiden kann das nur der Vorgesetzte oder das Personal (Seite „Zeiten“)
        t.braucht.length ? h('p', { 'data-zustand': 'ze-wartet', id: 'zm-tag-wartet' }, `▲ Wartet auf die Genehmigung durch deinen Vorgesetzten oder das Personal: ${t.braucht.map((x) => BRAUCHT[x]).join(' · ')}`) : null],
      senden: async () => {
        for (const { a, ein } of felder) {
          if ((ein.value.trim() || null) !== (a.anmerkung || null)) await k.api('PUT', `${api}/abschnitte/${a.id}/anmerkung`, { json: { anmerkung: ein.value.trim() || null } });
        }
        k.melden('Anmerkung gespeichert.', 'erfolg');
        await laden();
      } });
    felder[0]?.ein.focus();
  }
  function zeichnen() {
    const ohne = d.person ? null : 'Dein Zugang ist noch mit keiner Person verknüpft';
    const zu = d.person && d.monatsstand.zustand !== 'offen' && d.einstellung.monatsabschluss !== 'aus' ? 'Der Monat ist abgeschlossen' : null;
    sperren(kn.kommen, ohne || (d.eingestempelt ? `Du bist seit ${d.eingestempelt.von} Uhr eingestempelt` : null));
    sperren(kn.gehen, ohne || (d.eingestempelt ? null : 'Du bist nicht eingestempelt'));
    ortWahl.disabled = Boolean(ohne || d.eingestempelt);
    ortWahl.title = ohne || (d.eingestempelt ? 'Wo gearbeitet wird, steht mit dem Kommen fest – ändern über „Nachtragen oder berichtigen …“' : 'Wo du arbeitest: gilt ab dem nächsten „Kommen“');
    if (d.eingestempelt) ortWahl.value = d.eingestempelt.ort || 'betrieb';
    sperren(kn.antrag, ohne || zu);
    const laufend = d.person && d.monat >= d.heute.slice(0, 7);
    sperren(kn.monat, ohne || (d.einstellung.monatsabschluss === 'aus' ? 'Dein Betrieb nutzt keinen Monatsabschluss' : null) || zu
      || (laufend ? 'Bestätigen lässt sich ein Monat erst, wenn er vorbei ist' : null)
      || (d.antraege.some((x) => x.zustand === 'offen') ? 'Im Monat wartet noch ein Antrag auf seine Entscheidung' : null));
    if (!d.person) {
      inhalt.replaceChildren(h('p', { class: 'hinweis', id: 'zm-leer' }, d.hinweis || 'Keine Daten.'));
      return;
    }
    const p = d.profil;
    inhalt.replaceChildren(
      h('div', { class: 'ze-stand', id: 'zm-stand' },
        h('span', {}, d.eingestempelt ? h('strong', { id: 'zm-jetzt' }, `● Eingestempelt seit ${d.eingestempelt.von} Uhr${ortText(d.eingestempelt.ort)}${d.eingestempelt.tag !== d.heute ? ` (${tag(d.eingestempelt.tag)})` : ''}`, h('span', { id: 'zm-laeuft', class: 'ze-laeuft' }, '')) : h('strong', { id: 'zm-jetzt' }, '○ Nicht eingestempelt')),
        h('span', { id: 'zm-konto' }, 'Zeitkonto ', h('strong', {}, saldo(d.konto)), ` · Stand ${monatText(d.monat)}`),
        h('span', { class: 'ze-klein' }, [p.modell === 'gleitzeit' ? 'Gleitzeit' : 'feste Arbeitszeit', p.rahmen_von ? `Rahmen ${p.rahmen_von}–${p.rahmen_bis}` : null, p.kern_von ? `Kernzeit ${p.kern_von}–${p.kern_bis}` : null,
          p.korrektur === 'sofort' ? 'Korrekturen gelten sofort' : 'Korrekturen gibt dein Vorgesetzter frei'].filter(Boolean).join(' · ')),
        h('label', { class: 'ze-monat' }, 'Monat ', monatAuswahl('zm-monatwahl', d.monat, (m) => { monat = m; laden(); }))),
      h('section', { class: 'ze-karte', id: 'zm-blatt' }, h('h2', {}, `${monatText(d.monat)} – ${d.einstellung.monatsabschluss === 'aus' ? 'ohne Monatsabschluss' : MONATSSTAND[d.monatsstand.zustand]}`), blattT(d, d.heute)),
      h('section', { class: 'ze-karte', id: 'zm-antraege' }, h('h2', {}, 'Nachträge und Korrekturen im Monat'), d.antraege.length
        ? h('ul', {}, d.antraege.map((x) => h('li', { 'data-id': x.id }, antragText(x), ' – ',
          h('span', { 'data-zustand': x.zustand === 'offen' ? 'ze-wartet' : (x.zustand === 'abgelehnt' ? 'ze-fehlt' : 'ze-leise') },
            x.zustand === 'offen' ? '▲ wartet auf die Freigabe' : (x.zustand === 'angenommen' ? '✓ übernommen' : `✗ abgelehnt – ${x.grund || ''}`)))))
        : h('p', { class: 'hinweis' }, 'Keine.')));
    clearInterval(uhr);
    laeuft();
    if (d.eingestempelt) uhr = setInterval(() => { if (document.getElementById('zm-laeuft')) laeuft(); else clearInterval(uhr); }, 20000);
  }

  async function stempeln(art) {
    try { await k.api('POST', `${api}/stempel`, { json: art === 'kommen' ? { art, ort: ortWahl.value } : { art } }); k.melden(art === 'kommen' ? 'Gekommen.' : 'Gegangen.', 'erfolg'); } catch (x) { k.fehler(x); }
    await laden();
  }

  function antragDialog() {
    const f = antragFelder({ id: 'zm-a', tage: d.tage, heute: d.heute, feld, auswahl });
    dialog({ id: 'zm-dialog-antrag', titel: 'Nachtragen oder berichtigen', ja: 'Senden', breit: true,
      text: d.profil.korrektur === 'sofort' ? 'Bei dir gilt die Änderung sofort. Die Begründung bleibt sichtbar, der alte Wert nicht.' : 'Die Änderung gilt, sobald dein Vorgesetzter sie freigibt. Die Begründung bleibt sichtbar, der alte Wert nicht.',
      felder: f.felder,
      senden: async () => {
        const r = await k.api('POST', `${api}/antraege`, { json: f.json() });
        k.melden(r.zustand === 'offen' ? 'Gesendet – wartet auf die Freigabe.' : 'Übernommen.', 'erfolg');
        await laden();
      } });
  }

  async function bestaetigenMonat() {
    if (!await bestaetigen(`${monatText(d.monat)} bestätigen?`, 'Du bestätigst, dass dein Monatsblatt stimmt. Danach nimmt der Monat nichts mehr an; wieder öffnen kann ihn dein Vorgesetzter.', 'Bestätigen')) return;
    try { await k.api('POST', `${api}/monate/${d.monat}/bestaetigung`, { json: {} }); k.melden('Monat bestätigt.', 'erfolg'); } catch (x) { k.fehler(x); }
    await laden();
  }

  async function laden() {
    try { d = await k.api('GET', `${api}${monat ? `?monat=${monat}` : ''}`); } catch (x) { k.fehler(x); }
    geladenUm = Date.now();
    if (!d.einstellung) d.einstellung = { monatsabschluss: 'aus' };
    zeichnen();
  }

  b.append(seitenkopf('Meine Zeiten', ortWahl, kn.kommen, kn.gehen, kn.antrag, kn.monat), inhalt);
  await laden();
}
