// SPDX-License-Identifier: AGPL-3.0-or-later
// „Meine Daten“ (PERSONAL.md Abschnitt 4, Stufe 1, F136): der Blick einer Person auf sich selbst – Stamm, private
// Angaben, Vertrag, Fristen. Ändern kann sie nichts direkt: Kontaktdaten schlägt sie vor, das Personal übernimmt (PE17).
// Stufe 2 (F138): Urlaubskonto, eigene Abwesenheiten, Abwesenheit beantragen, krank melden, Eigenes zurücknehmen.
import { h, seitenkopf, knopf, feld, auswahl, sperren, datumFeld, bestaetigen, zeit } from '/kern/ui/bausteine.js';
import { stil, dialog, feldname, PRIVAT_FELDER, VERTRAGSARTEN, WOCHENTAGE, stundenText, fristZustand, tag, tageText, abwZustand, auText, abwesenheitFelder, verfallText } from './gemeinsam.js';

export async function zeigen(b, k) {
  stil();
  b.classList.add('personal');
  const api = `${k.mandantApi}/personal/meine`;
  let d = { person: null };
  const vorschlag = knopf('Änderung vorschlagen …', vorschlagDialog, 'primaer', { id: 'pm-vorschlag', title: 'Schlägt dem Personal neue Kontaktdaten vor; übernommen wird erst dort' });
  const beantragen = knopf('Abwesenheit beantragen …', () => abwesenheitDialog(false), '', { id: 'pm-beantragen', title: 'Urlaub, Freistellung, Fortbildung: Der Antrag geht an deinen Vorgesetzten bzw. das Personal' });
  const krank = knopf('Krank melden …', () => abwesenheitDialog(true), '', { id: 'pm-krank', title: 'Meldet eine Arbeitsunfähigkeit – ohne Angaben zur Erkrankung' });
  const inhalt = h('div', { class: 'pe-meine', id: 'pm-inhalt' });
  const angaben = (paare) => h('dl', { class: 'pe-angaben' }, paare.flatMap(([t, w]) => [h('dt', {}, t), h('dd', {}, w === null || w === undefined || w === '' ? '–' : w)]));
  const karte = (id, titel, ...kinder) => h('section', { class: 'pe-karte', id }, h('h2', {}, titel), ...kinder);

  function zeichnen() {
    sperren(vorschlag, d.person ? (k.darf('personal.eigenes.vorschlagen') ? null : 'Das erlaubt deine Rolle nicht') : 'Dein Zugang ist noch mit keiner Person verknüpft');
    const ohne = d.person ? (k.darf('personal.eigenes.beantragen') ? null : 'Das erlaubt deine Rolle nicht') : 'Dein Zugang ist noch mit keiner Person verknüpft';
    const arten = d.abwesenheitsarten || [];
    sperren(beantragen, ohne || (arten.some((a) => a.kennung !== 'au') ? null : 'Es gibt keine Art, die du selbst beantragen kannst'));
    sperren(krank, ohne || (arten.some((a) => a.kennung === 'au') ? null : 'Eine Arbeitsunfähigkeit trägt hier das Personal ein'));
    if (!d.person) {
      inhalt.replaceChildren(h('p', { class: 'hinweis', id: 'pm-leer' }, d.hinweis || 'Keine Daten.'));
      return;
    }
    const p = d.person; const pr = d.privat || {};
    const v = (d.vertraege || []).find((x) => x.gilt) || null;
    inhalt.replaceChildren(
      karte('pm-stamm', p.name, angaben([['Personalnummer', p.personalnummer], ['Vorgesetzter', p.vorgesetzter_name], ['Arbeitsort', p.arbeitsort_name], ['Zustand', p.zustand + (p.austritt ? ` · Austritt ${tag(p.austritt)}` : '')]])),
      karte('pm-privat', 'Private Angaben', angaben([['Geburtsdatum', tag(pr.geburtsdatum)], ['Anschrift', [pr.strasse, [pr.plz, pr.ort].filter(Boolean).join(' ')].filter(Boolean).join(', ')],
        ['Telefon', pr.telefon], ['E-Mail (privat)', pr.email], ['Notfallkontakt', [pr.notfall_name, pr.notfall_telefon].filter(Boolean).join(' · ')]])),
      karte('pm-vertrag', 'Vertrag', v ? angaben([['Art', VERTRAGSARTEN[v.art] || v.art], ['Funktion', v.funktion], ['Beginn', tag(v.beginn)], ['Probezeit bis', tag(v.probezeit_bis)],
        ['Befristet bis', tag(v.befristet_bis)], ['Arbeitszeit', `${stundenText(v.wochenminuten)} Std. je Woche (${v.verteilung.map((m, i) => (m ? `${WOCHENTAGE[i]} ${stundenText(m)}` : null)).filter(Boolean).join(' · ')})`],
        ['Urlaub', v.urlaubstage ? `${String(v.urlaubstage).replace('.', ',').replace(',0', '')} Tage im Jahr` : null], ['Kündigungsfrist', v.kuendigungsfrist], ['Fassung gültig ab', tag(v.gueltig_ab)]])
        : h('p', { class: 'hinweis', id: 'pm-kein-vertrag' }, 'Es gilt noch kein Vertrag.')),
      karte('pm-urlaub', 'Urlaubskonto', h('table', { class: 'pe-tabelle pa-konten' },
        h('thead', {}, h('tr', {}, ['Jahr', 'Anspruch', 'Übertrag', 'verfallen', 'genommen', 'genehmigt', 'Rest', 'beantragt'].map((t, i) => h('th', { class: i ? 'zahl' : '' }, t)))),
        h('tbody', {}, (d.urlaubskonto || []).map((z) => h('tr', { 'data-jahr': String(z.jahr) }, h('td', {}, String(z.jahr)),
          ...[z.anspruch, z.uebertrag, z.verfallen, z.genommen, z.genehmigt, z.rest, z.beantragt].map((w) => h('td', { class: 'zahl' }, tageText(w))))))),
        // Hinweis auf drohenden Verfall: fester Platz, auch wenn nichts ansteht (F106)
        h('p', { class: 'pe-klein pa-hinweis', id: 'pm-verfall', 'data-zustand': (d.urlaubskonto || []).some((z) => verfallText(z)) ? 'abw-beantragt' : '' },
          (d.urlaubskonto || []).map((z) => verfallText(z)).filter(Boolean).join(' · ') || 'Kein Urlaub aus dem Vorjahr, der zu verfallen droht.'),
        h('p', { class: 'pe-klein' }, 'In Arbeitstagen nach deinem Vertrag, ohne Feiertage an deinem Arbeitsort und ohne die freien Tage des Betriebs.')),
      karte('pm-abwesenheiten', 'Meine Abwesenheiten', (d.abwesenheiten || []).length
        ? h('table', { class: 'pe-tabelle' }, h('thead', {}, h('tr', {}, ['Von', 'Bis', 'Art', 'Arbeitstage', 'Zustand', 'Angaben', ''].map((t) => h('th', {}, t)))),
          h('tbody', {}, d.abwesenheiten.map((x) => {
            const laeuft = x.zustand === 'beantragt' || x.zustand === 'genehmigt';
            const kn = knopf('Zurücknehmen', () => zuruecknehmen(x), '', { class: ['knopf', 'klein', 'pm-zurueck'] });
            sperren(kn, ohne || (!laeuft ? 'Schon abgelehnt oder zurückgenommen' : (x.zustand === 'genehmigt' && x.von <= d.heute ? 'Hat schon begonnen – zurücknehmen kann das nur das Personal' : null)));
            return h('tr', { class: laeuft ? '' : 'pe-alt', 'data-id': x.id }, [tag(x.von), tag(x.bis), x.art_name, tageText(x.tage), abwZustand(x, d.heute), [x.bemerkung, auText(x.au)].filter(Boolean).join(' · '), kn].map((z) => h('td', {}, z)));
          })))
        : h('p', { class: 'hinweis', id: 'pm-keine-abwesenheit' }, 'Noch keine Abwesenheit.')),
      karte('pm-fristen', 'Fristen und Nachweise', d.fristen.filter((f) => f.zustand !== 'erledigt').length
        ? h('table', { class: 'pe-tabelle' }, h('thead', {}, h('tr', {}, ['Fällig', 'Art', 'Bemerkung', 'Zustand'].map((t) => h('th', {}, t)))),
          h('tbody', {}, d.fristen.filter((f) => f.zustand !== 'erledigt').map((f) => h('tr', {}, [tag(f.faellig), f.art_name, f.bemerkung || '', fristZustand(f)].map((z) => h('td', {}, z))))))
        : h('p', { class: 'hinweis' }, 'Keine offene Frist.')),
      karte('pm-vorschlaege', 'Meine Änderungsvorschläge', (d.vorschlaege || []).length
        ? h('table', { class: 'pe-tabelle' }, h('thead', {}, h('tr', {}, ['Vom', 'Vorgeschlagen', 'Zustand'].map((t) => h('th', {}, t)))),
          h('tbody', {}, d.vorschlaege.map((x) => h('tr', { class: x.zustand === 'offen' ? '' : 'pe-alt' }, [zeit(x.angelegt_am),
            Object.entries(x.felder).map(([f, w]) => `${feldname(f)}: ${w === null ? '(leeren)' : w}`).join(' · '),
            x.zustand === 'offen' ? h('span', { 'data-zustand': 'offen-vorschlag' }, '▲ offen – das Personal entscheidet') : (x.zustand === 'uebernommen' ? '✓ übernommen' : `✗ abgelehnt${x.grund ? ` – ${x.grund}` : ''}`)].map((z) => h('td', {}, z))))))
        : h('p', { class: 'hinweis' }, 'Noch kein Vorschlag.')));
  }

  function vorschlagDialog() {
    const pr = d.privat || {};
    const felder = Object.fromEntries(PRIVAT_FELDER.map(([n]) => [n, h('input', { id: `pm-f-${n}`, type: 'text', autocomplete: 'off', value: pr[n] || '', maxlength: n === 'plz' ? '10' : (n === 'email' ? '254' : '100') })]));
    dialog({ id: 'pm-dialog-vorschlag', titel: 'Änderung vorschlagen', ja: 'Vorschlagen', breit: true,
      text: 'Ändere, was nicht mehr stimmt. Vorgeschlagen wird nur, was du änderst; in der Akte steht es erst, wenn das Personal es übernimmt.',
      felder: [h('div', { class: 'pe-reihe' }, ...PRIVAT_FELDER.slice(0, 3).map(([n, t]) => feld(t, felder[n]))), h('div', { class: 'pe-reihe' }, ...PRIVAT_FELDER.slice(3).map(([n, t]) => feld(t, felder[n])))],
      senden: async () => {
        const json = Object.fromEntries(PRIVAT_FELDER.map(([n]) => [n, felder[n].value.trim() || null]).filter(([n, w]) => w !== (pr[n] || null)));
        if (Object.keys(json).length === 0) throw new Error('Du hast nichts geändert');
        await k.api('POST', `${api}/vorschlaege`, { json });
        k.melden('Vorschlag gesendet – das Personal entscheidet.', 'erfolg');
        await laden();
      } });
  }

  function abwesenheitDialog(au) {
    const arten = (d.abwesenheitsarten || []).filter((a) => (a.kennung === 'au') === au);
    const f = abwesenheitFelder({ id: 'pm-a', arten, datumFeld, auswahl, feld, von: au ? d.heute : '', bis: au ? d.heute : '' });
    dialog({ id: 'pm-dialog-abwesenheit', titel: au ? 'Krank melden' : 'Abwesenheit beantragen', ja: au ? 'Melden' : 'Beantragen', breit: true,
      text: au ? 'Die Meldung gilt sofort und ersetzt keine Bescheinigung. Angaben zur Erkrankung gehören nicht hierher – kumero führt keine Diagnose.'
        : 'Der Antrag geht an deinen Vorgesetzten; hast du keinen, entscheidet das Personal. Die Arbeitstage rechnet kumero aus deinem Vertrag und den freien Tagen des Betriebs.',
      felder: f.felder,
      senden: async () => {
        const r = await k.api('POST', `${api}/abwesenheiten`, { json: f.json() });
        k.melden(r.zustand === 'beantragt' ? 'Antrag gestellt – du siehst hier, wenn er entschieden ist.' : 'Gemeldet.', 'erfolg');
        await laden();
      } });
  }

  async function zuruecknehmen(x) {
    if (!await bestaetigen('Zurücknehmen?', `${x.art_name}, ${tag(x.von)} bis ${tag(x.bis)}. Der Eintrag bleibt als „zurückgenommen“ stehen.`, 'Zurücknehmen')) return;
    try { await k.api('POST', `${api}/abwesenheiten/${x.id}/ruecknahme`, { json: {} }); k.melden('Zurückgenommen.', 'erfolg'); } catch (e) { k.fehler(e); }
    await laden();
  }

  async function laden() {
    try { d = await k.api('GET', api); } catch (x) { k.fehler(x); }
    zeichnen();
  }

  b.append(seitenkopf('Meine Daten', vorschlag, beantragen, krank), inhalt);
  await laden();
}
