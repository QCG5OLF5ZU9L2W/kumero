// SPDX-License-Identifier: AGPL-3.0-or-later
// Erweiterung der Kontenliste des Kerns (F102): Spalte „Steuersatz“ und Übernahme der Vorschläge (F101).
// Der Kern kennt keine Steuer; der Satz liegt im Schema des Moduls buchen und wird hier gezeigt und gepflegt.
import { h, knopf } from '/kern/ui/bausteine.js';
import { satzAusBezeichnung } from './gemeinsam.js';

export async function kontenErweiterung(k, liste) {
  const basis = `${k.apiBasis}/buchen/steuerkonten`;
  const darf = k.darf('buchen.vorlage.pflegen');
  let saetze = new Map();
  const laden = async () => { saetze = new Map((await k.api('GET', basis)).steuerkonten.map((x) => [x.konto, Number(x.satz)])); };
  await laden();
  const text = (n) => String(n).replace('.', ',');
  const vorschlaege = () => liste.konten().filter((x) => !saetze.has(x.nr) && satzAusBezeichnung(x.bezeichnung) !== null);
  const alle = knopf('', async () => {
    const offen = vorschlaege();
    if (!offen.length) return;
    await setzen(Object.fromEntries(offen.map((x) => [x.nr, satzAusBezeichnung(x.bezeichnung).toFixed(2)])), `${offen.length} Steuersätze übernommen.`);
  }, '', { id: 'steuersaetze-uebernehmen', title: 'Die aus den Kontobezeichnungen vorgeschlagenen Steuersätze („… 19 %“) in einem Schritt übernehmen' });
  const knopfZeigen = () => {
    const n = darf ? vorschlaege().length : 0;
    alle.hidden = n === 0;
    alle.textContent = `Steuersätze übernehmen (${n} Vorschläge)`;
  };
  async function setzen(werte, meldung) {
    try {
      await k.api('PUT', basis, { json: { saetze: werte } });
      await laden();
      k.melden(meldung, 'erfolg');
    } catch (e) { k.fehler(e); }
    knopfZeigen();
    liste.neuZeichnen();
  }
  function zelle(x) {
    const satz = saetze.get(x.nr);
    const vorschlag = satz === undefined ? satzAusBezeichnung(x.bezeichnung) : null;
    if (!darf) return satz === undefined ? '' : `${text(satz)} %`;
    const bearbeiten = (ev) => {
      ev.stopPropagation();
      const ein = h('input', { size: '5', inputmode: 'decimal', 'data-steuersatz': x.nr, value: satz !== undefined ? text(satz) : (vorschlag !== null ? text(vorschlag) : ''), 'aria-label': `Steuersatz Konto ${x.nr}` });
      const fertig = async () => {
        const w = ein.value.trim().replace(',', '.');
        if (w === '') { if (satz !== undefined) await setzen({ [x.nr]: null }, `Steuersatz von Konto ${x.nr} gelöscht.`); else liste.neuZeichnen(); return; }
        if (!/^\d{1,2}(\.\d{1,2})?$/.test(w) || Number(w) <= 0) { k.melden('Steuersatz zwischen 0 und 100 erwartet, z. B. 19 oder 7.', 'fehler'); ein.focus(); return; }
        if (Number(w) === satz) { liste.neuZeichnen(); return; }
        await setzen({ [x.nr]: Number(w).toFixed(2) }, `Steuersatz von Konto ${x.nr}: ${text(Number(w))} %.`);
      };
      ein.addEventListener('keydown', (e2) => { if (e2.key === 'Enter') { e2.preventDefault(); ein.blur(); } else if (e2.key === 'Escape') { ein.removeEventListener('blur', fertig); liste.neuZeichnen(); } });
      ein.addEventListener('blur', fertig);
      ev.currentTarget.replaceWith(h('span', { class: 'zeile' }, ein, ' %'));
      ein.focus();
      ein.select();
    };
    const marke = satz !== undefined ? `${text(satz)} %` : (vorschlag !== null ? `${text(vorschlag)} % ?` : '＋');
    return h('button', { type: 'button', class: 'knopf klein', 'data-steuerkonto': x.nr,
      title: satz !== undefined ? 'Steuersatz ändern oder löschen (Feld leeren)' : (vorschlag !== null ? 'Vorschlag aus der Bezeichnung – anklicken und bestätigen' : 'Steuersatz eintragen: macht das Konto zum Steuerkonto'),
      'aria-label': `Steuersatz Konto ${x.nr}: ${satz !== undefined ? `${text(satz)} Prozent` : (vorschlag !== null ? `Vorschlag ${text(vorschlag)} Prozent` : 'keiner')}`, onclick: bearbeiten }, marke);
  }
  knopfZeigen();
  return { spalten: [{ titel: 'Steuersatz', klasse: 'eng', wert: zelle }], werkzeuge: [alle] };
}
