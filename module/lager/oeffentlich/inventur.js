// SPDX-License-Identifier: AGPL-3.0-or-later
// „Inventur“ (LAGER.md Stufe 2, F132): Zählliste je Lagerort oder über alle Lagerorte, je Charge eine Zeile. Jede
// Eingabe wird sofort gespeichert – die Zählung lässt sich unterbrechen und fortsetzen. Gerechnet wird gegen den
// Bestand im Augenblick der Zählung der Zeile. Abschließen erzeugt je Abweichung eine Bewegung; nichts wird überschrieben.
import { h, seitenkopf, knopf, feld, auswahl, sperren, bestaetigen, datumText, zeit } from '/kern/ui/bausteine.js';
import { mengeText, mengeLesen, codeLesen, dialog, stil } from './gemeinsam.js';

const ZUSTAND = { offen: 'in Arbeit', abgeschlossen: 'abgeschlossen', verworfen: 'verworfen' };

export async function zeigen(b, k) {
  stil();
  b.classList.add('listenseite', 'lager');
  const basis = `${k.apiBasis}/lager`;
  let orte = [];
  let liste = [];
  let darf = false;
  let inv = null;

  const wahl = h('select', { id: 'iv-wahl', 'aria-label': 'Inventur', title: 'Offene und frühere Inventuren' });
  const scan = h('input', { id: 'iv-scan', class: 'lg-suche', autocomplete: 'off', placeholder: 'Packung scannen – springt zur Zeile der Charge', 'aria-label': 'Code scannen' });
  const kn = {
    neu: knopf('Neue Inventur …', neuDialog, '', { id: 'iv-neu', title: 'Zählliste für einen Lagerort oder alle Lagerorte anlegen' }),
    dazu: knopf('Zeile hinzufügen …', dazuDialog, '', { id: 'iv-dazu', title: 'Etwas liegt da, das nicht auf der Liste steht' }),
    abschluss: knopf('Inventur abschließen', abschliessen, 'primaer', { id: 'iv-abschluss', title: 'Erzeugt je Abweichung eine Bewegung „Inventurdifferenz“ und sperrt die Inventur' }),
    verwerfen: knopf('Verwerfen', verwerfen, '', { id: 'iv-verwerfen', title: 'Die Zählung nicht verwenden; der Bestand bleibt, wie er ist' }),
    drucken: knopf('Zählliste drucken', () => window.print(), '', { id: 'iv-drucken', title: 'Zählliste drucken; als PDF über den Druckdialog' }),
  };
  const grund = h('div', { class: 'lg-grund', id: 'iv-grund', role: 'status' });
  const kacheln = h('div', { class: 'lg-kacheln', id: 'iv-kacheln' });
  const tab = h('div', { class: 'liste-rahmen lg-liste', id: 'iv-liste' });
  b.append(seitenkopf(`Inventur – ${k.buchName || k.mandantName || k.mandant}`),
    h('div', { class: 'lg-leiste nicht-drucken' }, h('label', { for: 'iv-wahl' }, 'Inventur'), wahl, kn.neu, h('span', { class: 'lg-luecke' }), kn.dazu, kn.verwerfen, kn.drucken, kn.abschluss),
    grund, kacheln, h('div', { class: 'lg-leiste nicht-drucken' }, h('label', { for: 'iv-scan' }, h('strong', {}, 'Code scannen')), scan), tab);

  const titel = (i) => `Nr. ${i.nr} · ${i.ort_name || 'alle Lagerorte'} · Stichtag ${datumText(i.stichtag)} · ${ZUSTAND[i.zustand]}${i.zustand === 'offen' ? ` (${i.gezaehlt} von ${Array.isArray(i.zeilen) ? i.zeilen.length : i.zeilen} gezählt)` : ''}`;
  const diffText = (z) => (z.differenz === null ? '' : Number(z.differenz) === 0 ? '0' : `${Number(z.differenz) > 0 ? '+' : '−'}${mengeText(Math.abs(Number(z.differenz)))}`);

  function kopfZeichnen() {
    const offen = inv?.zustand === 'offen';
    const fehlt = inv ? inv.zeilen.length - inv.gezaehlt : 0;
    const k1 = (wert, text) => h('div', { class: 'kachel' }, h('strong', {}, String(wert)), h('span', {}, text));
    kacheln.replaceChildren(...(inv ? [k1(`${inv.gezaehlt} von ${inv.zeilen.length}`, 'Zeilen gezählt'), k1(inv.abweichungen, 'Abweichungen'), k1(ZUSTAND[inv.zustand], inv.ort_name || 'alle Lagerorte')] : []));
    if (inv && wahl.selectedOptions[0]) wahl.selectedOptions[0].textContent = titel(inv);
    const keinRecht = darf ? null : 'Dir fehlt das Recht, eine Inventur zu führen';
    sperren(kn.neu, keinRecht || (orte.length === 0 ? 'Erst einen Lagerort anlegen (unter „Bestand“)' : null));
    sperren(kn.dazu, keinRecht || (!offen ? 'Nur in einer offenen Inventur' : null));
    sperren(kn.verwerfen, keinRecht || (!offen ? 'Nur eine offene Inventur lässt sich verwerfen' : null));
    sperren(kn.abschluss, keinRecht || (!offen ? 'Keine offene Inventur gewählt' : inv.zeilen.length === 0 ? 'Die Zählliste ist leer' : fehlt > 0 ? (fehlt === 1 ? '1 Zeile ist noch nicht gezählt' : `${fehlt} Zeilen sind noch nicht gezählt`) : null));
    sperren(kn.drucken, inv ? null : 'Keine Inventur gewählt');
    grund.className = `lg-grund${offen && fehlt > 0 ? ' an' : ''}`;
    grund.textContent = !inv ? '' : offen ? (fehlt > 0 ? `„Inventur abschließen“ ist gesperrt: ${fehlt === 1 ? '1 Zeile ist' : `${fehlt} Zeilen sind`} noch nicht gezählt. Jede Eingabe ist gespeichert – die Zählung kann später fortgesetzt werden.`
      : 'Alle Zeilen sind gezählt. Abschließen erzeugt je Abweichung eine Bewegung; kein Bestand wird überschrieben.')
      : inv.zustand === 'abgeschlossen' ? `Abgeschlossen am ${zeit(inv.beendet_am)} mit ${inv.abweichungen} Abweichung(en).` : `Verworfen am ${zeit(inv.beendet_am)} – der Bestand ist unverändert.`;
  }

  function zeichnen() {
    kopfZeichnen();
    if (!inv) {
      tab.replaceChildren(h('p', { class: 'hinweis', id: 'iv-leer' }, 'Noch keine Inventur. „Neue Inventur …“ legt eine Zählliste an: je Charge mit Bestand eine Zeile.'));
      return;
    }
    const offen = inv.zustand === 'offen' && darf;
    tab.replaceChildren(h('div', { class: 'liste-scroll' }, h('table', { class: 'liste lg-tabelle', id: 'iv-tabelle' },
      h('thead', {}, h('tr', {}, [['Lagerort', ''], ['Artikel', ''], ['Charge', ''], ['Verfall', 'eng'], ['Bestand', 'zahl'], ['Gezählt', ''], ['Differenz', 'zahl'], ['Stand', '']].map(([t, kl]) => h('th', { class: kl,
        title: t === 'Bestand' ? 'Bestand im Augenblick der Zählung der Zeile – vor der Zählung der jetzige' : '' }, t)))),
      h('tbody', {}, inv.zeilen.map((z) => {
        const ein = h('input', { class: 'lg-kurz', inputmode: 'decimal', autocomplete: 'off', placeholder: 'zählen', value: z.ist === null ? '' : mengeText(z.ist).replace(/\./g, ''), disabled: !offen,
          'aria-label': `Gezählt: ${z.bezeichnung}, Charge ${z.charge_name || 'ohne'}, ${z.ort_name}`, 'data-charge': z.charge, 'data-ort': z.ort });
        const diff = h('td', { class: 'zahl' }, diffText(z));
        const marke = h('span', { class: ['marke-klein', z.ist === null ? '' : Number(z.differenz) === 0 ? 'korrigiert' : 'warnung'] }, z.ist === null ? 'offen' : Number(z.differenz) === 0 ? 'stimmt' : 'Abweichung');
        ein.addEventListener('change', async () => {
          const roh = ein.value.trim();
          const ist = roh === '' ? null : (/^0([.,]0+)?$/.test(roh) ? '0' : mengeLesen(roh));
          if (roh !== '' && ist === null) { k.melden('Gezählt: eine Zahl ab null mit höchstens drei Nachkommastellen.', 'warnung'); ein.focus(); return; }
          try {
            inv = await k.api('PUT', `${basis}/inventuren/${inv.id}/zeilen`, { json: { charge: z.charge, ort: z.ort, ist } });
            const neu = inv.zeilen.find((x) => x.charge === z.charge && x.ort === z.ort);
            Object.assign(z, neu);
            diff.textContent = diffText(z);
            best.textContent = `${mengeText(z.bestand)} ${z.einheit}`;
            marke.className = `marke-klein ${z.ist === null ? '' : Number(z.differenz) === 0 ? 'korrigiert' : 'warnung'}`;
            marke.textContent = z.ist === null ? 'offen' : Number(z.differenz) === 0 ? 'stimmt' : 'Abweichung';
            kopfZeichnen();
          } catch (x) { k.fehler(x); }
        });
        ein.addEventListener('keydown', (ev) => {
          if (ev.key !== 'Enter') return;
          ev.preventDefault();
          const alle = [...tab.querySelectorAll('input[data-charge]')];
          (alle[alle.indexOf(ein) + 1] || ein).focus();
          if (alle[alle.indexOf(ein) + 1] === undefined) ein.blur();
        });
        const best = h('td', { class: 'zahl' }, `${mengeText(z.bestand)} ${z.einheit}`);
        return h('tr', { 'data-charge': z.charge }, h('td', {}, z.ort_name), h('td', {}, z.bezeichnung), h('td', { class: 'lg-nr' }, z.fuehrung === 'ohne' ? '–' : z.charge_name),
          h('td', { class: 'eng' }, z.verfall ? datumText(z.verfall) : '–', z.abgelaufen ? h('span', { class: 'marke-klein fehler' }, 'abgelaufen') : ''), best, h('td', {}, ein), diff, h('td', {}, marke));
      })))),
    h('div', { class: 'liste-fuss' }, 'Was nach der Zählung einer Zeile entnommen wird, verfälscht ihre Differenz nicht.'));
  }

  async function laden(id = null) {
    try {
      const r = await k.api('GET', `${basis}/inventuren`);
      liste = r.inventuren; darf = r.darf;
      orte = (await k.api('GET', basis)).orte.filter((o) => !o.stillgelegt);
    } catch (x) { k.fehler(x); }
    wahl.replaceChildren(...(liste.length ? liste.map((i) => h('option', { value: i.id }, titel(i))) : [h('option', { value: '' }, '– noch keine Inventur –')]));
    const ziel = liste.some((i) => i.id === id) ? id : (liste.find((i) => i.zustand === 'offen') || liste[0])?.id || '';
    wahl.value = ziel;
    inv = null;
    if (ziel) { try { inv = await k.api('GET', `${basis}/inventuren/${ziel}`); } catch (x) { k.fehler(x); } }
    zeichnen();
  }

  function neuDialog() {
    const ort = auswahl([['', 'alle Lagerorte'], ...orte.map((o) => [o.id, o.name])], orte.length === 1 ? orte[0].id : '', { id: 'iv-n-ort' });
    dialog({ id: 'iv-dialog-neu', titel: 'Neue Inventur', ja: 'Zählliste anlegen', text: 'Die Zählliste nennt jede Charge mit Bestand am gewählten Lagerort. Während der Inventur kann weiter entnommen werden.',
      felder: [feld('Lagerort', ort)],
      senden: async () => { const r = await k.api('POST', `${basis}/inventuren`, { json: { ort: ort.value || null } }); await laden(r.id); k.melden('Zählliste angelegt.', 'erfolg'); } });
  }

  function dazuDialog() {
    const da = new Set(inv.zeilen.map((z) => `${z.charge}/${z.ort}`));
    const ort = auswahl((inv.ort ? orte.filter((o) => o.id === inv.ort) : orte).map((o) => [o.id, o.name]), inv.ort || orte[0]?.id, { id: 'iv-d-ort' });
    const charge = auswahl(inv.chargen.map((c) => [c.id, `${c.bezeichnung} · ${c.charge_name || 'ohne Charge'}${c.verfall ? ` · ${datumText(c.verfall)}` : ''}`]), inv.chargen[0]?.id, { id: 'iv-d-charge' });
    dialog({ id: 'iv-dialog-dazu', titel: 'Zeile hinzufügen', ja: 'Hinzufügen', text: 'Für eine bekannte Charge, die an diesem Lagerort liegt, aber nicht auf der Liste steht. Ist die Charge noch unbekannt, buche sie unter „Bestand“ als Zugang ohne Beleg.',
      felder: [feld('Charge', charge), feld('Lagerort', ort)],
      senden: async () => {
        if (!charge.value) throw new Error('Es gibt keine Charge zum Hinzufügen');
        if (da.has(`${charge.value}/${ort.value}`)) throw new Error('Diese Zeile steht schon auf der Liste');
        inv = await k.api('POST', `${basis}/inventuren/${inv.id}/zeilen`, { json: { charge: charge.value, ort: ort.value } });
        zeichnen();
      } });
  }

  async function abschliessen() {
    if (!await bestaetigen('Inventur abschließen', inv.abweichungen === 0 ? 'Keine Abweichung – der Bestand bleibt, wie er ist. Die Inventur wird gesperrt.'
      : `${inv.abweichungen} Abweichung(en) werden als Bewegung „Inventurdifferenz“ gebucht. Die Inventur wird gesperrt.`, 'Abschließen')) return;
    try { const r = await k.api('POST', `${basis}/inventuren/${inv.id}/abschluss`); await laden(r.id); k.melden(`Inventur abgeschlossen: ${r.abweichungen} Differenzbewegung(en).`, 'erfolg'); } catch (x) { k.fehler(x); }
  }

  async function verwerfen() {
    if (!await bestaetigen('Inventur verwerfen', 'Die Zählung wird nicht verwendet. Der Bestand bleibt, wie er ist; die Liste bleibt zum Nachlesen stehen.', 'Verwerfen')) return;
    try { const r = await k.api('POST', `${basis}/inventuren/${inv.id}/verwerfen`); await laden(r.id); k.melden('Inventur verworfen.', 'erfolg'); } catch (x) { k.fehler(x); }
  }

  scan.addEventListener('keydown', (ev) => {
    if (ev.key !== 'Enter') return;
    ev.preventDefault();
    const c = codeLesen(scan.value);
    const gleich = (g, w) => g && w && g.replace(/^0+/, '') === w.replace(/^0+/, '');
    const z = c && inv ? inv.zeilen.find((x) => (!c.gtin || gleich(x.gtin, c.gtin)) && (x.charge_name === c.charge || x.charge_name === c.serie)) : null;
    if (!z) { k.melden(c ? 'Diese Charge steht nicht auf der Zählliste – „Zeile hinzufügen …“.' : 'Das ist kein lesbarer Code einer Packung.', 'warnung'); return; }
    scan.value = '';
    const ein = tab.querySelector(`input[data-charge="${z.charge}"]`);
    ein?.focus(); ein?.select();
  });
  wahl.addEventListener('change', () => laden(wahl.value));
  await laden();
}
