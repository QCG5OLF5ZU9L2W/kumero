// SPDX-License-Identifier: AGPL-3.0-or-later
// „Meine Zeiten“ (ZEIT.md Abschnitt 9, F145): Kommen und Gehen stempeln, das eigene Monatsblatt und Zeitkonto sehen,
// nachtragen und berichtigen lassen, den Monat bestätigen. Knöpfe stehen fest und nennen gesperrt den Grund (F106).
import { h, seitenkopf, knopf, feld, auswahl, sperren, bestaetigen } from '/kern/ui/bausteine.js';
import { stil, dialog, saldo, monatAuswahl, monatText, monatsblatt, antragFelder, antragText, MONATSSTAND, tag } from './gemeinsam.js';

export async function zeigen(b, k) {
  stil();
  b.classList.add('zeit');
  const api = `${k.mandantApi}/zeit/meine`;
  let d = { person: null };
  let monat = null;
  const kn = {
    kommen: knopf('Kommen', () => stempeln('kommen'), 'primaer', { id: 'zm-kommen', title: 'Beginnt einen Abschnitt Arbeit – jetzt, auf die Minute' }),
    gehen: knopf('Gehen', () => stempeln('gehen'), 'primaer', { id: 'zm-gehen', title: 'Beendet den laufenden Abschnitt – auch für eine Pause: danach wieder „Kommen“' }),
    antrag: knopf('Nachtragen oder berichtigen …', antragDialog, '', { id: 'zm-antrag', title: 'Ein Abschnitt fehlt oder stimmt nicht – mit Begründung' }),
    monat: knopf('Monat bestätigen', bestaetigenMonat, '', { id: 'zm-monat', title: 'Bestätigt, dass das Monatsblatt stimmt – danach nimmt der Monat nichts mehr an' }),
  };
  const inhalt = h('div', { id: 'zm-inhalt' });

  function zeichnen() {
    const ohne = d.person ? null : 'Dein Zugang ist noch mit keiner Person verknüpft';
    const zu = d.person && d.monatsstand.zustand !== 'offen' && d.einstellung.monatsabschluss !== 'aus' ? 'Der Monat ist abgeschlossen' : null;
    sperren(kn.kommen, ohne || (d.eingestempelt ? `Du bist seit ${d.eingestempelt.von} Uhr eingestempelt` : null));
    sperren(kn.gehen, ohne || (d.eingestempelt ? null : 'Du bist nicht eingestempelt'));
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
        h('span', {}, d.eingestempelt ? h('strong', { id: 'zm-jetzt' }, `● Eingestempelt seit ${d.eingestempelt.von} Uhr${d.eingestempelt.tag !== d.heute ? ` (${tag(d.eingestempelt.tag)})` : ''}`) : h('strong', { id: 'zm-jetzt' }, '○ Nicht eingestempelt')),
        h('span', { id: 'zm-konto' }, 'Zeitkonto ', h('strong', {}, saldo(d.konto)), ` · Stand ${monatText(d.monat)}`),
        h('span', { class: 'ze-klein' }, [p.modell === 'gleitzeit' ? 'Gleitzeit' : 'feste Arbeitszeit', p.rahmen_von ? `Rahmen ${p.rahmen_von}–${p.rahmen_bis}` : null, p.kern_von ? `Kernzeit ${p.kern_von}–${p.kern_bis}` : null,
          p.korrektur === 'sofort' ? 'Korrekturen gelten sofort' : 'Korrekturen gibt dein Vorgesetzter frei'].filter(Boolean).join(' · ')),
        h('label', { class: 'ze-monat' }, 'Monat ', monatAuswahl('zm-monatwahl', d.monat, (m) => { monat = m; laden(); }))),
      h('section', { class: 'ze-karte', id: 'zm-blatt' }, h('h2', {}, `${monatText(d.monat)} – ${d.einstellung.monatsabschluss === 'aus' ? 'ohne Monatsabschluss' : MONATSSTAND[d.monatsstand.zustand]}`), monatsblatt(d, d.heute)),
      h('section', { class: 'ze-karte', id: 'zm-antraege' }, h('h2', {}, 'Nachträge und Korrekturen im Monat'), d.antraege.length
        ? h('ul', {}, d.antraege.map((x) => h('li', { 'data-id': x.id }, antragText(x), ' – ',
          h('span', { 'data-zustand': x.zustand === 'offen' ? 'ze-wartet' : (x.zustand === 'abgelehnt' ? 'ze-fehlt' : 'ze-leise') },
            x.zustand === 'offen' ? '▲ wartet auf die Freigabe' : (x.zustand === 'angenommen' ? '✓ übernommen' : `✗ abgelehnt – ${x.grund || ''}`)))))
        : h('p', { class: 'hinweis' }, 'Keine.')));
  }

  async function stempeln(art) {
    try { await k.api('POST', `${api}/stempel`, { json: { art } }); k.melden(art === 'kommen' ? 'Gekommen.' : 'Gegangen.', 'erfolg'); } catch (x) { k.fehler(x); }
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
    if (!d.einstellung) d.einstellung = { monatsabschluss: 'aus' };
    zeichnen();
  }

  b.append(seitenkopf('Meine Zeiten', kn.kommen, kn.gehen, kn.antrag, kn.monat), inhalt);
  await laden();
}
