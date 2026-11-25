// SPDX-License-Identifier: AGPL-3.0-or-later
// „Meine Daten“ (PERSONAL.md Abschnitt 4, Stufe 1, F136): der Blick einer Person auf sich selbst – Stamm, private
// Angaben, Vertrag, Fristen. Ändern kann sie nichts direkt: Kontaktdaten schlägt sie vor, das Personal übernimmt (PE17).
// Stufe 2 (F138): Urlaubskonto, eigene Abwesenheiten, Abwesenheit beantragen, krank melden, Eigenes zurücknehmen.
// Stufe 3 (F140): die eigenen übergebenen Lohnvorbereitungen.
// Jahreskalender mit Feiertagen, freien Tagen und Brückentagen (F145).
// Stufe 4 (F141): eigene Beurteilungen (Selbsteinschätzung, Kenntnisnahme) und Ziele (bestätigen, Stand melden).
import { h, seitenkopf, knopf, feld, auswahl, sperren, datumFeld, bestaetigen, zeit } from '/kern/ui/bausteine.js';
import { stil, dialog, feldname, PRIVAT_FELDER, VERTRAGSARTEN, WOCHENTAGE, stundenText, fristZustand, tag, tageText, abwZustand, auText, abwesenheitFelder, schritteText, verfallText, lohnUnterlage, monatText,
  beurteilungZustand, beurteilungAnsicht, einschaetzungFelder, zielZustand, standFelder, jahreskalender, verguetungText, verguetungTeile, sonderzahlungText } from './gemeinsam.js';

export async function zeigen(b, k) {
  stil();
  b.classList.add('personal');
  const api = `${k.mandantApi}/personal/meine`;
  let d = { person: null };
  let kalenderJahr = null;
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
    const g = (d.verguetung || []).find((x) => x.gilt) || null;   // die Zeile des Vergütungsrasters, die heute gilt (F157)
    inhalt.replaceChildren(
      karte('pm-stamm', p.name, angaben([['Personalnummer', p.personalnummer], ['Vorgesetzter', p.vorgesetzter_name], ['Arbeitsort', p.arbeitsort_name], ['Zustand', p.zustand + (p.austritt ? ` · Austritt ${tag(p.austritt)}` : '')]])),
      karte('pm-privat', 'Private Angaben', angaben([['Geburtsdatum', tag(pr.geburtsdatum)], ['Anschrift', [pr.strasse, [pr.plz, pr.ort].filter(Boolean).join(' ')].filter(Boolean).join(', ')],
        ['Telefon', pr.telefon], ['E-Mail (privat)', pr.email], ['Notfallkontakt', [pr.notfall_name, pr.notfall_telefon].filter(Boolean).join(' · ')]])),
      karte('pm-vertrag', 'Vertrag', v ? angaben([['Art', VERTRAGSARTEN[v.art] || v.art], ['Funktion', v.funktion], ['Beginn', tag(v.beginn)], ['Probezeit bis', tag(v.probezeit_bis)],
        ['Befristet bis', tag(v.befristet_bis)], ['Arbeitszeit', `${stundenText(v.wochenminuten)} Std. je Woche (${v.verteilung.map((m, i) => (m ? `${WOCHENTAGE[i]} ${stundenText(m)}` : null)).filter(Boolean).join(' · ')})`],
        ['Urlaub', v.urlaubstage ? `${String(v.urlaubstage).replace('.', ',').replace(',0', '')} Tage im Jahr` : null], ['Sondervereinbarungen zur Arbeitszeit', v.arbeitszeit_vereinbarung], ['Vergütung', verguetungText(g)], ['Darin', verguetungTeile(g)], ['Vergütung gültig ab', g ? tag(g.gueltig_ab) : null], ['Urlaubsgeld', sonderzahlungText(g, 'urlaubsgeld')],
        ['Weihnachtsgeld', sonderzahlungText(g, 'weihnachtsgeld')], ['Vereinbarung zu den Sonderzahlungen', g?.vereinbarung], ['Aufgaben', v.taetigkeit], ['Kündigungsfrist', v.kuendigung_anzeige], ['Fassung gültig ab', tag(v.gueltig_ab)]])
        : h('p', { class: 'hinweis', id: 'pm-kein-vertrag' }, 'Es gilt noch kein Vertrag.')),
      karte('pm-urlaub', 'Urlaubskonto', h('table', { class: 'pe-tabelle pa-konten' },
        h('thead', {}, h('tr', {}, ['Jahr', 'Anspruch', 'Übertrag', 'verfallen', 'genommen', 'genehmigt', 'Rest', 'beantragt'].map((t, i) => h('th', { class: i ? 'zahl' : '' }, t)))),
        h('tbody', {}, (d.urlaubskonto || []).map((z) => h('tr', { 'data-jahr': String(z.jahr) }, h('td', {}, String(z.jahr)),
          ...[z.anspruch, z.uebertrag, z.verfallen, z.genommen, z.genehmigt, z.rest, z.beantragt].map((w) => h('td', { class: 'zahl' }, tageText(w))))))),
        // Hinweis auf drohenden Verfall: fester Platz, auch wenn nichts ansteht (F106)
        h('p', { class: 'pe-klein pa-hinweis', id: 'pm-verfall', 'data-zustand': (d.urlaubskonto || []).some((z) => verfallText(z)) ? 'abw-beantragt' : '' },
          (d.urlaubskonto || []).map((z) => verfallText(z)).filter(Boolean).join(' · ') || 'Kein Urlaub aus dem Vorjahr, der zu verfallen droht.'),
        h('p', { class: 'pe-klein' }, 'In Arbeitstagen nach deinem Vertrag, ohne Feiertage an deinem Arbeitsort und ohne die freien Tage des Betriebs.')),
      // Jahreskalender (ZE8): Feiertage, freie Tage des Betriebs und Brückentage – zum Planen von Urlaub und Freistellung
      karte('pm-kalender', 'Jahreskalender', h('div', { class: 'pe-leiste' },
        ...(d.kalender || []).map((k2) => { const kn = knopf(String(k2.jahr), () => { kalenderJahr = k2.jahr; zeichnen(); }, '', { class: ['knopf', 'klein'], id: `pm-kalender-${k2.jahr}` });
          sperren(kn, k2.jahr === (kalenderJahr || d.kalender[0].jahr) ? 'Dieses Jahr wird gezeigt' : null); return kn; })),
        (d.kalender || []).length ? h('div', { class: 'pa-rollbar-x' }, jahreskalender(d.kalender.find((k2) => k2.jahr === kalenderJahr) || d.kalender[0], d.abwesenheiten, d.heute)) : h('p', { class: 'hinweis' }, 'Kein Kalender.')),
      karte('pm-abwesenheiten', 'Meine Abwesenheiten', (d.abwesenheiten || []).length
        ? h('table', { class: 'pe-tabelle' }, h('thead', {}, h('tr', {}, ['Von', 'Bis', 'Art', 'Arbeitstage', 'Zustand', 'Angaben', ''].map((t) => h('th', {}, t)))),
          h('tbody', {}, d.abwesenheiten.map((x) => {
            const laeuft = x.zustand === 'beantragt' || x.zustand === 'genehmigt';
            const kn = knopf('Zurücknehmen', () => zuruecknehmen(x), '', { class: ['knopf', 'klein', 'pm-zurueck'] });
            sperren(kn, ohne || (!laeuft ? 'Schon abgelehnt oder zurückgenommen' : (x.zustand === 'genehmigt' && x.von <= d.heute ? 'Hat schon begonnen – zurücknehmen kann das nur das Personal' : null)));
            return h('tr', { class: laeuft ? '' : 'pe-alt', 'data-id': x.id }, [tag(x.von), tag(x.bis), x.art_name, tageText(x.tage), abwZustand(x, d.heute), [x.bemerkung, auText(x.au), x.vertretung_name ? `Vertretung: ${x.vertretung_name}` : null, schritteText(x)].filter(Boolean).join(' · '), kn].map((z) => h('td', {}, z)));
          })))
        : h('p', { class: 'hinweis', id: 'pm-keine-abwesenheit' }, 'Noch keine Abwesenheit.')),
      // Vertretung und Mitzeichnung (PERSONAL.md 10n): Anträge von Kollegen, die mich nennen – mitzeichnen, bevor genehmigt wird
      karte('pm-fuer-mich', 'Vertretung und Mitzeichnung', (d.fuer_mich || []).length
        ? h('table', { class: 'pe-tabelle' }, h('thead', {}, h('tr', {}, ['Wer', 'Von', 'Bis', 'Art', 'Worum es geht', 'Stand', '', ''].map((t) => h('th', {}, t)))),
          h('tbody', {}, d.fuer_mich.map((x) => {
            const nicht = ohne || (k.darf('personal.eigenes.mitzeichnen') ? null : 'Das erlaubt deine Rolle nicht') || (x.offen ? null : (x.was === 'mitzeichnen' ? 'Darüber ist schon entschieden' : 'Hier ist nichts zu entscheiden'));
            const ja = knopf('Mitzeichnen', () => mitzeichnen(x, true), '', { class: ['knopf', 'klein', 'pm-mitzeichnen'], title: 'Du bist einverstanden, in dieser Zeit zu vertreten – danach geht der Antrag zum Genehmiger' });
            const nein = knopf('Ablehnen …', () => mitzeichnen(x, false), '', { class: ['knopf', 'klein', 'pm-nicht-mitzeichnen'], title: 'Mit Grund, den die Person sieht – der Antrag ist damit abgelehnt' });
            sperren(ja, nicht); sperren(nein, nicht);
            const worum = x.was === 'kenntnis' ? 'zur Kenntnis' : (x.vertrete ? 'du vertrittst' : 'du zeichnest mit');
            const stand = x.offen ? '▲ wartet auf dich' : (x.zustand === 'genehmigt' ? '✓ genehmigt' : (x.schritt === 'erledigt' ? '✓ von dir mitgezeichnet – wartet auf die Genehmigung' : '○ noch nicht bei dir'));
            return h('tr', { 'data-id': x.id, 'data-was': x.was }, [x.name, tag(x.von), x.halber_tag ? `${tag(x.bis)} (halber Tag)` : tag(x.bis), x.art_name,
              [worum, x.bemerkung, x.selbst_abwesend ? '! du bist in dieser Zeit selbst abwesend' : null].filter(Boolean).join(' · '),
              h('span', { 'data-zustand': x.offen ? 'abw-beantragt' : '' }, stand), ja, nein].map((z) => h('td', {}, z)));
          })))
        : h('p', { class: 'hinweis', id: 'pm-nichts-fuer-mich' }, 'Kein Antrag nennt dich als Vertretung.')),
      // was das Lohnbüro bekommen hat – erst nach der Übergabe, mit dem Nachweis der Zuschläge (PE11)
      karte('pm-lohn', 'Lohnvorbereitung', (d.lohn || []).length
        ? h('div', {}, h('p', { class: 'pe-klein' }, 'Was an das Lohnbüro übergeben wurde. Die Abrechnung selbst macht das Lohnbüro, nicht kumero.'),
          ...d.lohn.map((l) => h('details', { class: 'lo-fruehere', 'data-monat': l.monat }, h('summary', {}, `${monatText(l.monat)}${l.fassung > 1 ? ` · Fassung ${l.fassung}` : ''} · übergeben ${tag(l.uebergeben_am.slice(0, 10))}`),
            lohnUnterlage(l, { ohneBemerkung: true }))))
        : h('p', { class: 'hinweis', id: 'pm-kein-lohn' }, 'Noch keine Lohnvorbereitung übergeben.')),
      // Beurteilungen und Ziele: Karten stehen immer da; Knöpfe je Zeile, gesperrt mit Grund (F106)
      karte('pm-beurteilungen', 'Meine Beurteilungen', (d.beurteilungen || []).length
        ? h('div', {}, ...d.beurteilungen.map((x) => {
          const selbst = knopf('Selbsteinschätzung …', () => selbstDialog(x), '', { class: ['knopf', 'klein', 'pm-selbst'] });
          const kenntnis = knopf('Zur Kenntnis nehmen …', () => kenntnisDialog(x), '', { class: ['knopf', 'klein', 'pm-kenntnis'] });
          sperren(selbst, x.zustand === 'offen' ? null : 'Die Beurteilung ist abgeschlossen oder aufgehoben');
          sperren(kenntnis, x.zustand === 'abgeschlossen' ? null : (x.zustand === 'offen' ? 'Erst nach dem Gespräch, wenn die Beurteilung abgeschlossen ist' : (x.zustand === 'zur_kenntnis' ? 'Schon zur Kenntnis genommen' : 'Die Beurteilung ist aufgehoben')));
          return h('details', { class: 'lo-fruehere', 'data-id': x.id, open: x.zustand === 'offen' || x.zustand === 'abgeschlossen' },
            h('summary', {}, `${tag(x.zeitraum_von)} bis ${tag(x.zeitraum_bis)} · `, beurteilungZustand(x)), h('div', { class: 'pm-knoepfe' }, selbst, kenntnis), beurteilungAnsicht(x));
        }))
        : h('p', { class: 'hinweis', id: 'pm-keine-beurteilung' }, d.beurteilungen === null ? 'Beurteilungen sind mit deiner Rolle nicht einsehbar.' : 'Noch keine Beurteilung.')),
      karte('pm-ziele', 'Meine Ziele', (d.ziele || []).length
        ? h('table', { class: 'pe-tabelle' }, h('thead', {}, h('tr', {}, ['Ziel', 'Frist', 'Zustand', 'Stand', ''].map((t) => h('th', {}, t)))),
          h('tbody', {}, d.ziele.map((z) => {
            const ja = knopf('Bestätigen', () => zielBestaetigen(z, true), '', { class: ['knopf', 'klein', 'pm-ziel-ja'] });
            const nein = knopf('Ablehnen …', () => zielBestaetigen(z, false), '', { class: ['knopf', 'klein', 'pm-ziel-nein'] });
            const stand = knopf('Stand melden …', () => zielStand(z), '', { class: ['knopf', 'klein', 'pm-ziel-stand'] });
            const entschieden = z.zustand === 'vorgeschlagen' ? null : 'Über das Ziel ist schon entschieden';
            sperren(ja, entschieden); sperren(nein, entschieden);
            sperren(stand, z.zustand === 'vereinbart' ? null : (z.zustand === 'vorgeschlagen' ? 'Erst bestätigen' : 'Das Ziel ist bewertet oder aufgehoben'));
            return h('tr', { class: z.zustand === 'vorgeschlagen' || z.zustand === 'vereinbart' ? '' : 'pe-alt', 'data-id': z.id },
              h('td', {}, z.titel, z.beschreibung ? h('div', { class: 'pe-klein' }, z.beschreibung) : null), h('td', {}, tag(z.frist)), h('td', {}, zielZustand(z), z.bewertung_text ? h('div', { class: 'pe-klein' }, z.bewertung_text) : null),
              h('td', {}, `${z.stand} %`, z.stand_text ? h('div', { class: 'pe-klein' }, z.stand_text) : null), h('td', {}, h('div', { class: 'pm-knoepfe' }, ja, nein, stand)));
          })))
        : h('p', { class: 'hinweis', id: 'pm-kein-ziel' }, d.ziele === null ? 'Ziele sind mit deiner Rolle nicht einsehbar.' : 'Noch kein Ziel.')),
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
    // der akademische Titel gehört zum Namen, lässt sich aber vorschlagen (PN6, F149)
    const titel = h('input', { id: 'pm-f-akademischer_titel', type: 'text', autocomplete: 'off', value: d.person.akademischer_titel || '', maxlength: '40', class: 'pe-kurz', placeholder: 'z. B. Dr.' });
    const felder = Object.fromEntries(PRIVAT_FELDER.map(([n]) => [n, h('input', { id: `pm-f-${n}`, type: 'text', autocomplete: 'off', value: pr[n] || '', maxlength: n === 'plz' ? '10' : (n === 'email' ? '254' : '100') })]));
    dialog({ id: 'pm-dialog-vorschlag', titel: 'Änderung vorschlagen', ja: 'Vorschlagen', breit: true,
      text: 'Ändere, was nicht mehr stimmt. Vorgeschlagen wird nur, was du änderst; in der Akte steht es erst, wenn das Personal es übernimmt.',
      felder: [h('div', { class: 'pe-reihe' }, feld('Akademischer Titel', titel), ...PRIVAT_FELDER.slice(0, 3).map(([n, t]) => feld(t, felder[n]))), h('div', { class: 'pe-reihe' }, ...PRIVAT_FELDER.slice(3).map(([n, t]) => feld(t, felder[n])))],
      senden: async () => {
        const json = Object.fromEntries(PRIVAT_FELDER.map(([n]) => [n, felder[n].value.trim() || null]).filter(([n, w]) => w !== (pr[n] || null)));
        if ((titel.value.trim() || null) !== (d.person.akademischer_titel || null)) json.akademischer_titel = titel.value.trim() || null;
        if (Object.keys(json).length === 0) throw new Error('Du hast nichts geändert');
        await k.api('POST', `${api}/vorschlaege`, { json });
        k.melden('Vorschlag gesendet – das Personal entscheidet.', 'erfolg');
        await laden();
      } });
  }

  function abwesenheitDialog(au) {
    const arten = (d.abwesenheitsarten || []).filter((a) => (a.kennung === 'au') === au);
    const f = abwesenheitFelder({ id: 'pm-a', arten, vertretungen: d.vertretungen, datumFeld, auswahl, feld, von: au ? d.heute : '', bis: au ? d.heute : '' });
    dialog({ id: 'pm-dialog-abwesenheit', titel: au ? 'Krank melden' : 'Abwesenheit beantragen', ja: au ? 'Melden' : 'Beantragen', breit: true,
      text: au ? 'Die Meldung gilt sofort und ersetzt keine Bescheinigung. Angaben zur Erkrankung gehören nicht hierher – kumero führt keine Diagnose.'
        : 'Wählst du eine Vertretung, zeichnet sie zuerst mit. Dann geht der Antrag an deinen Vorgesetzten; hast du keinen, entscheidet das Personal. Die Arbeitstage rechnet kumero aus deinem Vertrag und den freien Tagen des Betriebs.',
      felder: f.felder,
      senden: async () => {
        const r = await k.api('POST', `${api}/abwesenheiten`, { json: f.json() });
        k.melden(r.zustand === 'beantragt' ? 'Antrag gestellt – du siehst hier, wenn er entschieden ist.' : 'Gemeldet.', 'erfolg');
        await laden();
      } });
  }

  function selbstDialog(x) {
    const f = einschaetzungFelder(x, x.selbst, 'pm-e');
    dialog({ id: 'pm-dialog-selbst', titel: 'Selbsteinschätzung', ja: 'Abgeben', breit: true,
      text: 'Wie schätzt du dich im Zeitraum selbst ein? Solange die Beurteilung offen ist, kannst du neu abgeben. Die Einschätzung deines Beurteilers liest du, sobald ihr beide abgegeben habt.',
      felder: f.felder,
      senden: async () => { await k.api('PUT', `${api}/beurteilungen/${x.id}/einschaetzung`, { json: f.json() }); k.melden('Selbsteinschätzung abgegeben.', 'erfolg'); await laden(); } });
  }

  function kenntnisDialog(x) {
    const text = h('textarea', { id: 'pm-k-text', class: 'lo-text', maxlength: '2000' });
    dialog({ id: 'pm-dialog-kenntnis', titel: 'Ergebnis zur Kenntnis nehmen', ja: 'Zur Kenntnis genommen', breit: true,
      text: 'Du bestätigst, dass du das Ergebnis gesehen hast – nicht, dass du ihm zustimmst. Deine Stellungnahme steht danach bei der Beurteilung.',
      felder: [feld('Stellungnahme (wenn du willst)', text)],
      senden: async () => { await k.api('POST', `${api}/beurteilungen/${x.id}/kenntnis`, { json: { stellungnahme: text.value.trim() || null } }); k.melden('Zur Kenntnis genommen.', 'erfolg'); await laden(); } });
  }

  async function zielBestaetigen(z, ja) {
    if (ja) {
      if (!await bestaetigen('Ziel bestätigen?', `„${z.titel}“ bis ${tag(z.frist)}. Mit deiner Bestätigung ist das Ziel vereinbart.`, 'Bestätigen')) return;
      try { await k.api('POST', `${api}/ziele/${z.id}/bestaetigung`, { json: { ja: true } }); k.melden('Ziel vereinbart.', 'erfolg'); } catch (e) { k.fehler(e); }
      await laden();
      return;
    }
    const grund = h('input', { id: 'pm-z-grund', type: 'text', required: true, maxlength: '200', size: '48', autocomplete: 'off' });
    dialog({ id: 'pm-dialog-ziel-nein', titel: 'Ziel ablehnen', ja: 'Ablehnen', text: `„${z.titel}“. Wer das Ziel vorgeschlagen hat, sieht deinen Grund.`, felder: [feld('Grund', grund)],
      senden: async () => { await k.api('POST', `${api}/ziele/${z.id}/bestaetigung`, { json: { ja: false, grund: grund.value.trim() } }); k.melden('Ziel abgelehnt.', 'erfolg'); await laden(); } });
  }

  function zielStand(z) {
    const f = standFelder(z, 'pm-s');
    dialog({ id: 'pm-dialog-stand', titel: `Stand melden – ${z.titel}`, ja: 'Speichern', felder: f.felder,
      senden: async () => { await k.api('PUT', `${api}/ziele/${z.id}/stand`, { json: f.json() }); k.melden('Stand gemeldet.', 'erfolg'); await laden(); } });
  }

  // Mitzeichnen oder ablehnen (PERSONAL.md 10n): die Ablehnung braucht einen Grund, den die Person sieht
  async function mitzeichnen(x, ja) {
    const pfad = `${api}/mitzeichnungen/${x.id}`;
    if (ja) {
      if (!await bestaetigen('Mitzeichnen?', `${x.art_name} von ${x.name}, ${tag(x.von)} bis ${tag(x.bis)}. Danach geht der Antrag zum Genehmiger.`, 'Mitzeichnen')) return;
      try { await k.api('POST', pfad, { json: { mitzeichnen: true } }); k.melden('Mitgezeichnet.', 'erfolg'); } catch (e) { k.fehler(e); }
      await laden();
      return;
    }
    const grund = h('input', { id: 'pm-m-grund', type: 'text', required: true, maxlength: '200', size: '48', autocomplete: 'off' });
    dialog({ id: 'pm-dialog-mitzeichnung', titel: 'Nicht mitzeichnen', ja: 'Ablehnen',
      text: `${x.art_name} von ${x.name}, ${tag(x.von)} bis ${tag(x.bis)}. Der Antrag ist damit abgelehnt; die Person sieht den Grund und kann neu beantragen.`,
      felder: [feld('Grund', grund, 'keine Gesundheitsangaben')],
      senden: async () => { await k.api('POST', pfad, { json: { mitzeichnen: false, grund: grund.value.trim() } }); k.melden('Abgelehnt.', 'erfolg'); await laden(); } });
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
