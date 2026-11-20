// SPDX-License-Identifier: AGPL-3.0-or-later
// „Beurteilungen und Ziele“ (PERSONAL.md Stufe 4, Abschnitt 10e, F141): zwei Listen in Reitern – Beurteilungen und
// Ziele –, darunter mit verschiebbarem Teiler (F137) das Gewählte. Der Beurteiler sieht, wofür er eingetragen ist, das
// Personal alles. Eine Einschätzung des anderen ist erst zu lesen, wenn die eigene abgegeben ist; was der Server nicht
// schickt, steht hier als „abgegeben – noch nicht zu lesen“. Knöpfe stehen fest und nennen gesperrt den Grund (F106).
import { h, seitenkopf, knopf, feld, auswahl, sperren, datumFeld, reiterleiste } from '/kern/ui/bausteine.js';
import { tabellenfilter } from '/kern/ui/tabellenfilter.js';
import { teiler } from '/kern/ui/teiler.js';
import { stil, dialog, tag, beurteilungZustand, beurteilungZustandText, beurteilungAnsicht, einschaetzungFelder, zielZustand, zielZustandText, standFelder, BEWERTUNGEN } from './gemeinsam.js';

export async function zeigen(b, k) {
  stil();
  b.classList.add('listenseite', 'personal');
  const api = `${k.mandantApi}/personal`;
  let d = { darf: {}, beurteilungen: [], ziele: [], vorlagen: [], personen: [], beurteiler: [], heute: '' };
  let ansicht = 'beurteilungen';
  const gewaehlt = { beurteilungen: null, ziele: null };

  const kn = {
    neu: knopf('Neue Beurteilung …', neuDialog, 'primaer', { id: 'be-neu', title: 'Legt für eine Person eine Beurteilung nach einer Vorlage an' }),
    ein: knopf('Einschätzung …', einschaetzen, '', { id: 'be-einschaetzen', title: 'Gibt die Einschätzung des Beurteilers ab; solange die Beurteilung offen ist, lässt sie sich neu abgeben' }),
    ab: knopf('Abschließen …', abschliessen, '', { id: 'be-abschliessen', title: 'Gespräch mit Datum und Ergebnis – danach nimmt die Person zur Kenntnis' }),
    auf: knopf('Aufheben …', () => aufheben('beurteilungen'), '', { id: 'be-aufheben', title: 'Hebt eine offene Beurteilung auf; sie bleibt als aufgehoben stehen' }),
    wer: knopf('Beurteiler …', beurteilerDialog, '', { id: 'be-beurteiler', title: 'Setzt, wer einschätzt' }),
    vorlagen: knopf('Vorlagen …', vorlagenDialog, '', { id: 'be-vorlagen', title: 'Vorlagen mit Kriterien und Skala pflegen' }),
    ziel: knopf('Ziel vorschlagen …', zielDialog, '', { id: 'be-ziel', title: 'Schlägt einer Person ein Ziel vor; vereinbart ist es, wenn sie es bestätigt' }),
    stand: knopf('Stand …', standDialog, '', { id: 'be-stand', title: 'Hält den Stand des gewählten Ziels fest' }),
    bewerten: knopf('Bewerten …', bewerten, '', { id: 'be-bewerten', title: 'Bewertet das gewählte Ziel: erreicht, teilweise, nicht' }),
    zielAuf: knopf('Ziel aufheben …', () => aufheben('ziele'), '', { id: 'be-ziel-aufheben', title: 'Hebt das gewählte Ziel auf; es bleibt als aufgehoben stehen' }),
  };
  const stand = h('div', { class: 'pe-stand', id: 'be-stand-text', role: 'status' });
  const reiter = h('div', { id: 'be-reiter' });
  const liste = h('div', { class: 'liste-rahmen pe-liste', id: 'be-liste' });
  const unten = h('div', { class: 'pa-ansicht', id: 'be-einzel' });

  const spalten = {
    beurteilungen: [
      { id: 'person', titel: 'Person', wert: (x) => x.name + (x.ich ? ' (du)' : '') },
      { id: 'von', titel: 'Zeitraum von', klasse: 'eng', wert: (x) => tag(x.zeitraum_von), sortwert: (x) => x.zeitraum_von },
      { id: 'bis', titel: 'bis', klasse: 'eng', wert: (x) => tag(x.zeitraum_bis), sortwert: (x) => x.zeitraum_bis },
      { id: 'vorlage', titel: 'Vorlage', wert: (x) => x.vorlage },
      { id: 'beurteiler', titel: 'Beurteiler', wert: (x) => x.beurteiler_name || 'das Personal' },
      { id: 'zustand', titel: 'Zustand', wert: (x) => beurteilungZustandText(x)[1], zelle: (x) => beurteilungZustand(x) },
    ],
    ziele: [
      { id: 'person', titel: 'Person', wert: (x) => x.name + (x.ich ? ' (du)' : '') },
      { id: 'titel', titel: 'Ziel', wert: (x) => x.titel },
      { id: 'frist', titel: 'Frist', klasse: 'eng', wert: (x) => tag(x.frist), sortwert: (x) => x.frist },
      { id: 'stand', titel: 'Stand', klasse: 'zahl', wert: (x) => `${x.stand} %`, sortwert: (x) => x.stand },
      { id: 'zustand', titel: 'Zustand', wert: (x) => zielZustandText(x)[1], zelle: (x) => zielZustand(x) },
    ],
  };
  const filter = { beurteilungen: tabellenfilter(spalten.beurteilungen, zeichnen), ziele: tabellenfilter(spalten.ziele, zeichnen) };
  const wahl = (art) => d[art].find((x) => x.id === gewaehlt[art]) || null;

  function knoepfe() {
    const g = wahl('beurteilungen');
    const z = wahl('ziele');
    const falsch = (art) => (ansicht === art ? null : `Dazu in den Reiter „${art === 'ziele' ? 'Ziele' : 'Beurteilungen'}“ wechseln`);
    const nurPflege = d.darf.pflegen ? null : 'Das darf nur, wer Beurteilungen führt (Rolle „Personal“)';
    const keineB = falsch('beurteilungen') || (g ? null : 'Erst eine Beurteilung in der Liste wählen');
    const nichtMeine = (x) => (x.zustaendig ? null : (x.ich ? 'Das ist deine eigene – über Eigenes entscheidest nicht du' : 'Dafür bist du nicht zuständig'));
    const offenB = keineB || nichtMeine(g) || (g.zustand === 'offen' ? null : 'Die Beurteilung ist abgeschlossen oder aufgehoben');
    sperren(kn.neu, falsch('beurteilungen') || (d.personen.length ? null : 'Es gibt niemanden, für den du eine Beurteilung anlegen kannst')
      || (d.vorlagen.some((v) => !v.stillgelegt) ? null : 'Es gibt noch keine Vorlage – das Personal legt sie unter „Vorlagen …“ an'));
    sperren(kn.ein, offenB);
    sperren(kn.ab, offenB || (g.fremd_abgegeben ? null : 'Erst die Einschätzung abgeben'));
    sperren(kn.auf, offenB);
    sperren(kn.wer, nurPflege || offenB);
    sperren(kn.vorlagen, nurPflege);
    const keinZ = falsch('ziele') || (z ? null : 'Erst ein Ziel in der Liste wählen');
    const meinZ = keinZ || nichtMeine(z);
    sperren(kn.ziel, falsch('ziele') || (d.personen.length ? null : 'Es gibt niemanden, mit dem du ein Ziel vereinbaren kannst'));
    const vereinbart = meinZ || (z.zustand === 'vereinbart' ? null : (z.zustand === 'vorgeschlagen' ? 'Die Person hat das Ziel noch nicht bestätigt' : 'Das Ziel ist bewertet oder aufgehoben'));
    sperren(kn.stand, vereinbart);
    sperren(kn.bewerten, vereinbart);
    sperren(kn.zielAuf, meinZ || (z.zustand === 'vorgeschlagen' || z.zustand === 'vereinbart' ? null : 'Das Ziel ist bewertet oder aufgehoben'));
  }

  function zielAnsicht(z) {
    const paare = [['Zustand', zielZustand(z)], ['Frist', tag(z.frist)], ['Beschreibung', z.beschreibung || '–'], ['Vereinbart am', z.vereinbart_am ? tag(z.vereinbart_am.slice(0, 10)) : '–'],
      ['Stand', `${z.stand} %${z.stand_text ? ` – ${z.stand_text}` : ''}${z.stand_am ? ` (${tag(z.stand_am.slice(0, 10))})` : ''}`],
      ['Bewertung', z.bewertung ? `${BEWERTUNGEN[z.bewertung]}${z.bewertung_text ? ` – ${z.bewertung_text}` : ''}` : '–']];
    return h('article', { class: 'be-ansicht', 'data-id': z.id }, h('h2', {}, `${z.titel} – ${z.name}`),
      h('dl', { class: 'pe-angaben be-texte' }, paare.flatMap(([t, w]) => [h('dt', {}, t), h('dd', {}, w)])));
  }

  function zeichnen() {
    const zeilenAlle = d[ansicht];
    const sp = spalten[ansicht];
    const f = filter[ansicht];
    f.setzeZeilen(zeilenAlle);
    const zeilen = f.anwenden(zeilenAlle);
    reiter.replaceChildren(reiterleiste([{ id: 'beurteilungen', text: `Beurteilungen (${d.beurteilungen.length})` }, { id: 'ziele', text: `Ziele (${d.ziele.length})` }], ansicht, (id) => { ansicht = id; zeichnen(); }));
    reiter.firstChild.querySelectorAll('button').forEach((el, i) => { el.id = `be-reiter-${['beurteilungen', 'ziele'][i]}`; });
    const aktiv = (x) => (ansicht === 'ziele' ? x.zustand === 'vorgeschlagen' || x.zustand === 'vereinbart' : x.zustand === 'offen' || x.zustand === 'abgeschlossen');
    const tbody = h('tbody', {}, zeilen.length ? zeilen.map((x) => {
      const tr = h('tr', { 'data-id': x.id, tabindex: '0', class: [x.id === gewaehlt[ansicht] ? 'gewaehlt' : '', aktiv(x) ? '' : 'pe-alt'], 'aria-selected': x.id === gewaehlt[ansicht] ? 'true' : 'false' },
        sp.map((s) => h('td', { class: s.klasse || '' }, s.zelle ? s.zelle(x) : s.wert(x))));
      const waehlen = () => { gewaehlt[ansicht] = x.id; zeichnen(); };
      tr.addEventListener('click', waehlen);
      tr.addEventListener('keydown', (ev) => { if (ev.target === tr && (ev.key === 'Enter' || ev.key === ' ')) { ev.preventDefault(); waehlen(); } });
      return tr;
    }) : h('tr', { class: 'leer' }, h('td', { colspan: sp.length, id: 'be-leer' },
      zeilenAlle.length ? 'Nichts passt zum Filter.' : (ansicht === 'ziele' ? 'Noch kein Ziel.' : 'Noch keine Beurteilung.'))));
    liste.replaceChildren(h('div', { class: 'liste-scroll' }, h('table', { class: 'liste', id: 'be-tabelle' }, h('thead', {}, f.kopf), tbody)),
      h('div', { class: 'liste-fuss' }, `${zeilen.length === zeilenAlle.length ? '' : `${zeilen.length} von `}${zeilenAlle.length} ${ansicht === 'ziele' ? (zeilenAlle.length === 1 ? 'Ziel' : 'Ziele') : (zeilenAlle.length === 1 ? 'Beurteilung' : 'Beurteilungen')}`
        + (f.aktiv() ? ' · gefiltert' : '')));
    stand.textContent = d.darf.sehen ? 'Du siehst die Beurteilungen und Ziele aller Personen.' : 'Du siehst die Beurteilungen, bei denen du Beurteiler bist, und die Ziele deines Teams.';
    const g = wahl(ansicht);
    unten.replaceChildren(g ? (ansicht === 'ziele' ? zielAnsicht(g) : beurteilungAnsicht(g))
      : h('p', { class: 'hinweis', id: 'be-keine-wahl' }, ansicht === 'ziele' ? 'Wähle oben ein Ziel.' : 'Wähle oben eine Beurteilung: Hier stehen dann beide Einschätzungen nebeneinander.'));
    knoepfe();
  }

  // --- Beurteilungen ------------------------------------------------------------------------------------------

  function neuDialog() {
    const vorlagen = d.vorlagen.filter((v) => !v.stillgelegt);
    const person = auswahl(d.personen.map((p) => [p.id, p.name]), d.personen[0]?.id, { id: 'be-n-person' });
    const vorlage = auswahl(vorlagen.map((v) => [v.id, `${v.name} (${v.kriterien.length} Kriterien)`]), vorlagen[0]?.id, { id: 'be-n-vorlage' });
    const jahr = Number(d.heute.slice(0, 4)) - 1;
    const von = datumFeld({ id: 'be-n-von', value: `${jahr}-01-01` });
    const bis = datumFeld({ id: 'be-n-bis', value: `${jahr}-12-31` });
    const wer = auswahl([['', '– das Personal –'], ...d.beurteiler.map((p) => [p.id, p.name])], '', { id: 'be-n-beurteiler' });
    const vorbelegen = () => {
      if (!d.darf.pflegen) return;
      const v = d.personen.find((p) => p.id === person.value)?.vorgesetzter || '';
      wer.value = d.beurteiler.some((p) => p.id === v) ? v : '';
    };
    person.addEventListener('change', vorbelegen);
    vorbelegen();
    if (!d.darf.pflegen) { wer.disabled = true; wer.title = 'Du beurteilst selbst'; wer.replaceChildren(h('option', { value: '' }, 'du selbst')); }
    dialog({ id: 'be-dialog-neu', titel: 'Neue Beurteilung', ja: 'Anlegen', breit: true,
      text: 'Kriterien und Skala werden aus der Vorlage in die Beurteilung kopiert; eine spätere Änderung der Vorlage ändert sie nicht. Die Person sieht die Beurteilung unter „Meine Daten“ und gibt dort ihre Selbsteinschätzung ab.',
      felder: [h('div', { class: 'pe-reihe' }, feld('Person', person), feld('Vorlage', vorlage)), h('div', { class: 'pe-reihe' }, feld('Zeitraum von', von.el), feld('bis', bis.el), feld('Beurteiler', wer))],
      senden: async () => {
        const v = von.wert(); const e = bis.wert();
        if (!v || !e) throw new Error('Der Zeitraum fehlt oder ist nicht lesbar');
        gewaehlt.beurteilungen = (await k.api('POST', `${api}/beurteilungen`, { json: { person: person.value, vorlage: vorlage.value, zeitraum_von: v, zeitraum_bis: e, ...(d.darf.pflegen ? { beurteiler: wer.value || null } : {}) } })).id;
        k.melden('Beurteilung angelegt.', 'erfolg');
        await laden();
      } });
  }

  function einschaetzen() {
    const g = wahl('beurteilungen');
    if (!g) return;
    const f = einschaetzungFelder(g, g.fremd, 'be-e');
    dialog({ id: 'be-dialog-einschaetzung', titel: `Einschätzung – ${g.name}`, ja: 'Abgeben', breit: true,
      text: g.selbst ? 'Die Selbsteinschätzung der Person steht unten in der Ansicht.' : 'Die Selbsteinschätzung der Person liest du, sobald du deine Einschätzung abgegeben hast.',
      felder: f.felder,
      senden: async () => { await k.api('PUT', `${api}/beurteilungen/${g.id}/einschaetzung`, { json: f.json() }); k.melden('Einschätzung abgegeben.', 'erfolg'); await laden(); } });
  }

  function abschliessen() {
    const g = wahl('beurteilungen');
    if (!g) return;
    const am = datumFeld({ id: 'be-a-am', value: d.heute });
    const ergebnis = h('textarea', { id: 'be-a-ergebnis', class: 'lo-text', maxlength: '2000', required: true });
    dialog({ id: 'be-dialog-abschluss', titel: `Beurteilung abschließen – ${g.name}`, ja: 'Abschließen', breit: true,
      text: (g.selbst_abgegeben ? '' : 'Achtung: Die Person hat keine Selbsteinschätzung abgegeben. ') + 'Nach dem Abschluss ändert sich nichts mehr; die Person nimmt das Ergebnis unter „Meine Daten“ zur Kenntnis und kann Stellung nehmen.',
      felder: [feld('Gespräch am', am.el), feld('Ergebnis des Gesprächs', ergebnis)],
      senden: async () => {
        const w = am.wert();
        if (!w) throw new Error('Das Datum des Gesprächs fehlt oder ist nicht lesbar');
        await k.api('POST', `${api}/beurteilungen/${g.id}/abschluss`, { json: { gespraech_am: w, ergebnis: ergebnis.value.trim() } });
        k.melden('Beurteilung abgeschlossen.', 'erfolg');
        await laden();
      } });
  }

  function aufheben(art) {
    const g = wahl(art);
    if (!g) return;
    const grund = h('input', { id: 'be-h-grund', type: 'text', required: true, maxlength: '200', size: '48', autocomplete: 'off' });
    const ziel = art === 'ziele';
    dialog({ id: 'be-dialog-aufheben', titel: ziel ? 'Ziel aufheben' : 'Beurteilung aufheben', ja: 'Aufheben', text: `${ziel ? g.titel : `Beurteilung ${tag(g.zeitraum_von)} bis ${tag(g.zeitraum_bis)}`} – ${g.name}. Der Eintrag bleibt als aufgehoben stehen; die Person sieht den Grund.`,
      felder: [feld('Grund', grund)],
      senden: async () => { await k.api('POST', `${api}/${ziel ? 'ziele' : 'beurteilungen'}/${g.id}/aufhebung`, { json: { grund: grund.value.trim() } }); k.melden('Aufgehoben.', 'erfolg'); await laden(); } });
  }

  function beurteilerDialog() {
    const g = wahl('beurteilungen');
    if (!g) return;
    const wer = auswahl([['', '– das Personal –'], ...d.beurteiler.filter((p) => p.id !== g.person).map((p) => [p.id, p.name])], g.beurteiler || '', { id: 'be-w-wer' });
    dialog({ id: 'be-dialog-beurteiler', titel: 'Beurteiler setzen', ja: 'Speichern', text: `Beurteilung von ${g.name}. Eine schon abgegebene Einschätzung bleibt stehen, bis der neue Beurteiler seine abgibt.`,
      felder: [feld('Beurteiler', wer)],
      senden: async () => { await k.api('PUT', `${api}/beurteilungen/${g.id}/beurteiler`, { json: { beurteiler: wer.value || null } }); k.melden('Gespeichert.', 'erfolg'); await laden(); } });
  }

  function vorlagenDialog() {
    const welche = auswahl([['', '– neue Vorlage –'], ...d.vorlagen.map((v) => [v.id, v.name + (v.stillgelegt ? ' (stillgelegt)' : '')])], '', { id: 'be-v-welche' });
    const name = h('input', { id: 'be-v-name', type: 'text', maxlength: '60', size: '30', required: true, autocomplete: 'off' });
    const von = auswahl([['1', '1'], ['0', '0']], '1', { id: 'be-v-von' });
    const bis = auswahl(Array.from({ length: 9 }, (_, i) => [String(i + 2), String(i + 2)]), '5', { id: 'be-v-bis' });
    const niedrig = h('input', { id: 'be-v-niedrig', type: 'text', maxlength: '60', autocomplete: 'off', placeholder: 'z. B. trifft nicht zu' });
    const hoch = h('input', { id: 'be-v-hoch', type: 'text', maxlength: '60', autocomplete: 'off', placeholder: 'z. B. trifft voll zu' });
    const kriterien = h('textarea', { id: 'be-v-kriterien', class: 'be-kriterien', required: true, placeholder: 'Fachkenntnis | Wissen und Können im eigenen Gebiet' });
    const still = h('input', { type: 'checkbox', id: 'be-v-still' });
    const fuellen = () => {
      const v = d.vorlagen.find((x) => x.id === welche.value) || null;
      name.value = v?.name || ''; von.value = String(v?.skala_von ?? 1); bis.value = String(v?.skala_bis ?? 5); niedrig.value = v?.skala_niedrig || ''; hoch.value = v?.skala_hoch || '';
      kriterien.value = (v?.kriterien || []).map((x) => x.name + (x.beschreibung ? ` | ${x.beschreibung}` : '')).join('\n');
      still.checked = Boolean(v?.stillgelegt); still.disabled = !v; still.title = v ? '' : 'Eine neue Vorlage ist nicht stillgelegt';
    };
    welche.addEventListener('change', fuellen);
    fuellen();
    dialog({ id: 'be-dialog-vorlagen', titel: 'Vorlagen für Beurteilungen', ja: 'Speichern', breit: true,
      text: 'Je Zeile ein Kriterium, nach einem senkrechten Strich die Beschreibung. Laufende und abgeschlossene Beurteilungen behalten die Kriterien, mit denen sie angelegt wurden.',
      felder: [h('div', { class: 'pe-reihe' }, feld('Vorlage', welche), feld('Name', name), h('label', { class: 'pe-haken' }, still, ' stillgelegt')),
        h('div', { class: 'pe-reihe' }, feld('Skala von', von), feld('bis', bis), feld('Bedeutung des unteren Endes', niedrig), feld('Bedeutung des oberen Endes', hoch)),
        feld('Kriterien', kriterien)],
      senden: async () => {
        const alt = d.vorlagen.find((x) => x.id === welche.value)?.kriterien || [];
        // die Kennung eines Kriteriums bleibt, solange sein Name bleibt
        const liste = kriterien.value.split('\n').map((z) => z.trim()).filter(Boolean).map((z) => { const [n, ...rest] = z.split('|'); const nm = n.trim(); const bs = rest.join('|').trim();
          const kennung = alt.find((x) => x.name === nm)?.kennung; return { ...(kennung ? { kennung } : {}), name: nm, ...(bs ? { beschreibung: bs } : {}) }; });
        if (!liste.length) throw new Error('Mindestens ein Kriterium');
        const json = { name: name.value.trim(), skala_von: Number(von.value), skala_bis: Number(bis.value), skala_niedrig: niedrig.value.trim() || null, skala_hoch: hoch.value.trim() || null, kriterien: liste };
        if (welche.value) await k.api('PUT', `${api}/beurteilungsvorlagen/${welche.value}`, { json: { ...json, stillgelegt: still.checked } });
        else await k.api('POST', `${api}/beurteilungsvorlagen`, { json });
        k.melden('Vorlage gespeichert.', 'erfolg');
        await laden();
      } });
  }

  // --- Ziele --------------------------------------------------------------------------------------------------

  function zielDialog() {
    const person = auswahl(d.personen.map((p) => [p.id, p.name]), d.personen[0]?.id, { id: 'be-z-person' });
    const titel = h('input', { id: 'be-z-titel', type: 'text', maxlength: '120', size: '48', required: true, autocomplete: 'off' });
    const text = h('textarea', { id: 'be-z-text', class: 'lo-text', maxlength: '1000' });
    const frist = datumFeld({ id: 'be-z-frist' });
    dialog({ id: 'be-dialog-ziel', titel: 'Ziel vorschlagen', ja: 'Vorschlagen', breit: true, text: 'Die Person sieht den Vorschlag unter „Meine Daten“. Vereinbart ist das Ziel, wenn sie es bestätigt; lehnt sie ab, nennt sie den Grund.',
      felder: [h('div', { class: 'pe-reihe' }, feld('Person', person), feld('Frist', frist.el)), feld('Ziel', titel), feld('Beschreibung – woran die Erreichung gemessen wird', text)],
      senden: async () => {
        const w = frist.wert();
        if (!w) throw new Error('Die Frist fehlt oder ist nicht lesbar');
        gewaehlt.ziele = (await k.api('POST', `${api}/ziele`, { json: { person: person.value, titel: titel.value.trim(), beschreibung: text.value.trim() || null, frist: w } })).id;
        k.melden('Ziel vorgeschlagen.', 'erfolg');
        await laden();
      } });
  }

  function standDialog() {
    const z = wahl('ziele');
    if (!z) return;
    const f = standFelder(z, 'be-s');
    dialog({ id: 'be-dialog-stand', titel: `Stand – ${z.titel}`, ja: 'Speichern', text: `${z.name}, Frist ${tag(z.frist)}.`, felder: f.felder,
      senden: async () => { await k.api('PUT', `${api}/ziele/${z.id}/stand`, { json: f.json() }); k.melden('Stand gespeichert.', 'erfolg'); await laden(); } });
  }

  function bewerten() {
    const z = wahl('ziele');
    if (!z) return;
    const urteil = auswahl(Object.entries(BEWERTUNGEN), 'erreicht', { id: 'be-b-urteil' });
    const text = h('textarea', { id: 'be-b-text', class: 'lo-text', maxlength: '1000' });
    dialog({ id: 'be-dialog-bewerten', titel: `Ziel bewerten – ${z.titel}`, ja: 'Bewerten', breit: true,
      text: `${z.name}, Frist ${tag(z.frist)}, Stand ${z.stand} %. Die Bewertung steht danach fest. Ob daraus ein Zuschlag wird, entscheidet das Personal unter „Lohnvorbereitung“ (Zuschlag von Hand).`,
      felder: [feld('Bewertung', urteil), feld('Anmerkung', text)],
      senden: async () => { await k.api('POST', `${api}/ziele/${z.id}/bewertung`, { json: { bewertung: urteil.value, text: text.value.trim() || null } }); k.melden('Ziel bewertet.', 'erfolg'); await laden(); } });
  }

  async function laden() {
    try { d = await k.api('GET', `${api}/beurteilungen`); } catch (x) { k.fehler(x); }
    for (const art of ['beurteilungen', 'ziele']) if (gewaehlt[art] && !d[art].some((x) => x.id === gewaehlt[art])) gewaehlt[art] = null;
    zeichnen();
  }

  b.append(seitenkopf(`Beurteilungen und Ziele – ${k.mandantName || k.mandant}`),
    // zwei feste Zeilen mit fester Bedeutung (F106): oben Beurteilungen, darunter Ziele
    h('div', { class: 'pe-leiste nicht-drucken', id: 'be-leiste-beurteilungen' }, kn.neu, kn.ein, kn.ab, kn.auf, kn.wer, kn.vorlagen),
    h('div', { class: 'pe-leiste nicht-drucken', id: 'be-leiste-ziele' }, kn.ziel, kn.stand, kn.bewerten, kn.zielAuf),
    stand, reiter, teiler(liste, unten, { id: 'be-teilung', hoehe: 360 }));
  await laden();
}
