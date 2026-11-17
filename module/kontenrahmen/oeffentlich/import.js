// SPDX-License-Identifier: AGPL-3.0-or-later
// Kontenrahmen importieren (F80): Datei hochladen → Entwurf prüfen und korrigieren → als Vorlage speichern.
import { h, seitenkopf, knopf, feld, auswahl, kachel, reiterleiste, tabelle, bestaetigen, zeit, KONTOARTEN } from '/kern/ui/bausteine.js';

const ART = Object.fromEntries(KONTOARTEN);

export async function zeigen(b, k) {
  const t = k.t;
  const api = (m, p, o) => k.api(m, `/api/v1/kontenrahmen${p}`, o);
  b.append(seitenkopf(t('import.titel')));
  if (k.param.entwurf) {
    let e;
    try { e = await api('GET', `/entwuerfe/${encodeURIComponent(k.param.entwurf)}`); } catch (f) {
      k.fehler(f);
      b.append(knopf(t('import.neu'), () => k.navigieren('#/system/kontenrahmen/import')));
      return;
    }
    return entwurfZeigen(b, k, api, e);
  }
  return hochladenZeigen(b, k, api);
}

function schritte(t, aktiv) {
  const s = [t('schritt.datei'), t('schritt.pruefen'), t('schritt.speichern')];
  return h('ol', { class: 'schritte' }, s.map((x, i) => h('li', { class: i < aktiv ? 'erledigt' : (i === aktiv ? 'aktiv' : '') }, x)));
}

// ── Schritt 1: Datei ───────────────────────────────────────────────────────
async function hochladenZeigen(b, k, api) {
  const t = k.t;
  const [regelwerke, entwuerfe] = await Promise.all([api('GET', '/regelwerke'), api('GET', '/entwuerfe')]);
  const regel = auswahl(regelwerke.map((r) => [r.name, `${r.rahmen} (Regelwerk ${r.name}, Fassung ${r.version})`]), 'skr81', { id: 'regelwerk' });
  const name = h('input', { id: 'vorlagenname', maxlength: '120', size: '40', placeholder: t('import.name_platzhalter') });
  const datei = h('input', { type: 'file', accept: '.pdf,.csv,.txt,application/pdf,text/csv', id: 'datei' });
  const status = h('p', { class: 'hinweis' });
  const ablage = h('div', { class: 'ablage', tabindex: '0', role: 'button', 'aria-label': t('import.ablage') },
    h('strong', {}, t('import.ablage')), h('span', { class: 'hinweis' }, t('import.ablage_hinweis')), datei);

  async function hochladen(f) {
    if (!f) return;
    const typ = /\.pdf$/i.test(f.name) || f.type === 'application/pdf' ? 'application/pdf' : 'text/csv';
    status.textContent = t('import.liest', { datei: f.name });
    ablage.classList.add('ueber');
    try {
      const q = new URLSearchParams({ regelwerk: regel.value });
      if (name.value.trim()) q.set('name', name.value.trim());
      const e = await api('POST', `/entwuerfe?${q}`, { roh: f, typ });
      k.navigieren(`#/system/kontenrahmen/import?entwurf=${e.id}`);
    } catch (f2) {
      status.textContent = '';
      ablage.classList.remove('ueber');
      k.fehler(f2);
    }
  }
  ablage.addEventListener('click', () => datei.click());
  ablage.addEventListener('keydown', (ev) => { if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); datei.click(); } });
  datei.addEventListener('change', () => hochladen(datei.files[0]));
  ablage.addEventListener('dragover', (ev) => { ev.preventDefault(); ablage.classList.add('ueber'); });
  ablage.addEventListener('dragleave', () => ablage.classList.remove('ueber'));
  ablage.addEventListener('drop', (ev) => { ev.preventDefault(); hochladen(ev.dataTransfer.files[0]); });

  b.append(schritte(t, 0),
    h('div', { class: 'karte' },
      h('h2', {}, t('import.quelle')),
      h('p', {}, t('import.erklaerung')),
      h('div', { class: 'zeile' }, feld(t('import.regelwerk'), regel), feld(t('import.name'), name, t('import.name_hinweis'))),
      ablage, status));

  if (entwuerfe.length) {
    b.append(h('h2', {}, t('import.offene_entwuerfe')), tabelle([
      { titel: t('spalte.name'), wert: (e) => h('a', { href: `#/system/kontenrahmen/import?entwurf=${e.id}` }, e.name) },
      { titel: t('spalte.konten'), klasse: 'zahl eng', wert: (e) => e.bericht.konten },
      { titel: t('spalte.offen'), klasse: 'zahl eng', wert: (e) => e.bericht.offen },
      { titel: t('spalte.pruefen'), klasse: 'zahl eng', wert: (e) => e.bericht.pruefen },
      { titel: t('spalte.angelegt'), klasse: 'eng', wert: (e) => zeit(e.angelegt_am) },
    ], { zeilen: entwuerfe, oeffnen: (e) => k.navigieren(`#/system/kontenrahmen/import?entwurf=${e.id}`) }));
  }
}

// ── Schritt 2 und 3: Entwurf prüfen, korrigieren, speichern ─────────────────
function entwurfZeigen(b, k, api, e) {
  const t = k.t;
  let reiter = e.bericht.offen > 0 ? 'offen' : (e.bericht.pruefen > 0 ? 'pruefen' : 'alle');
  let suche = '';
  let speichert = false;
  const kopf = h('div');
  const inhalt = h('div');
  const fuss = h('div', { class: 'leiste-unten' });
  const sucheFeld = h('input', { type: 'search', class: 'suche', placeholder: t('import.suche'),
    oninput: () => { suche = sucheFeld.value.trim().toLowerCase(); listeZeichnen(); } });
  b.append(schritte(t, 1), kopf, inhalt, fuss);

  const korr = () => ({ ...e.korrekturen });
  async function korrigieren(neu) {
    if (speichert) return;
    speichert = true;
    const scroll = inhalt.querySelector('.liste-scroll')?.scrollTop ?? 0;
    try {
      e = await api('PUT', `/entwuerfe/${e.id}/korrekturen`, { json: { korrekturen: neu } });
      alles();
      const s = inhalt.querySelector('.liste-scroll');
      if (s) s.scrollTop = scroll;
    } catch (f) { k.fehler(f); } finally { speichert = false; }
  }
  const setzen = (nr, aenderung) => {
    const n = korr();
    const alt = n[nr] && !n[nr].weglassen ? n[nr] : {};
    n[nr] = { ...alt, ...aenderung };
    return korrigieren(n);
  };
  const weglassen = (nr) => korrigieren({ ...korr(), [nr]: { weglassen: true } });
  const zuruecknehmen = (nr) => { const n = korr(); delete n[nr]; return korrigieren(n); };

  function kopfZeichnen() {
    const r = e.bericht;
    kopf.replaceChildren(
      h('div', { class: 'karte' },
        h('h2', {}, e.name),
        h('dl', { class: 'eigenschaften' },
          h('dt', {}, t('feld.rahmen')), h('dd', {}, `${e.rahmen}${e.fassung ? `, ${t('feld.fassung')} ${e.fassung}` : ''}`),
          h('dt', {}, t('feld.quelle')), h('dd', {}, e.quelle.art === 'datev-pdf' ? t('quelle.pdf') : t('quelle.csv'),
            ' · SHA-256 ', h('code', {}, e.quelle.datei_sha256.slice(0, 16) + '…')),
          h('dt', {}, t('feld.regelwerk')), h('dd', {}, `${e.regelwerk.name}, ${t('feld.fassung')} ${e.regelwerk.version}`),
          h('dt', {}, t('feld.eingelesen')), h('dd', {}, zeit(e.angelegt_am)))),
      h('div', { class: 'kacheln' },
        kachel(r.konten, t('bericht.konten')),
        kachel(r.offen, t('bericht.offen'), r.offen ? 'fehler' : 'erfolg'),
        kachel(r.pruefen, t('bericht.pruefen'), r.pruefen ? 'warnung' : ''),
        kachel(Object.keys(e.korrekturen).length, t('bericht.korrigiert')),
        kachel(r.ausgelassen, t('bericht.ausgelassen')),
        kachel(r.bereiche, t('bericht.bereiche')),
        kachel(r.hinweise, t('bericht.hinweise'), r.hinweise ? 'warnung' : '')),
      h('p', { class: 'hinweis' }, t('bericht.kontoarten'), ' ',
        Object.entries(r.kontoarten).map(([a, n]) => `${ART[a] || t('kontoart.ohne')} ${n}`).join(' · ')));
  }

  function zeilen() {
    const passt = (x) => !suche || String(x.nr).toLowerCase().includes(suche) || String(x.bezeichnung || x.text || '').toLowerCase().includes(suche);
    const k2 = e.korrekturen;
    const listen = {
      alle: e.konten,
      offen: e.konten.filter((x) => x.offen || !x.kontoart),
      pruefen: e.konten.filter((x) => x.pruefen),
      korrigiert: e.konten.filter((x) => k2[x.nr]),
      ausgelassen: e.ausgelassen,
      hinweise: e.hinweise,
    };
    return { listen, gefiltert: listen[reiter].filter(passt) };
  }

  function listeZeichnen() {
    const { listen, gefiltert } = zeilen();
    const reiterDef = ['offen', 'pruefen', 'korrigiert', 'alle', 'ausgelassen', 'hinweise']
      .map((id) => ({ id, text: t(`reiter.${id}`), anzahl: listen[id].length }));
    const werkzeuge = h('div', { class: 'zeile' }, sucheFeld,
      reiter === 'pruefen' && gefiltert.length
        ? knopf(t('aktion.alle_bestaetigen', { n: gefiltert.length }), () => {
          const n = korr();
          for (const x of gefiltert) n[x.nr] = { ...(n[x.nr] || {}), kontoart: x.kontoart };
          korrigieren(n);
        }) : null);
    let liste;
    if (reiter === 'hinweise') {
      liste = tabelle([
        { titel: t('spalte.art'), klasse: 'eng', wert: (x) => x.art },
        { titel: t('spalte.stelle'), klasse: 'eng', wert: (x) => [x.seite ? `S. ${x.seite}` : '', x.zeile ? `Z. ${x.zeile}` : ''].filter(Boolean).join(', ') },
        { titel: t('spalte.text'), wert: (x) => x.text },
      ], { zeilen: gefiltert, leer: t('leer.hinweise') });
    } else if (reiter === 'ausgelassen') {
      liste = tabelle([
        { titel: t('spalte.nr'), klasse: 'nr', wert: (x) => x.nr },
        { titel: t('spalte.bezeichnung'), wert: (x) => x.bezeichnung },
        { titel: t('spalte.grund'), wert: (x) => x.grund },
        { titel: '', klasse: 'eng', wert: (x) => (e.korrekturen[x.nr]?.weglassen ? knopf(t('aktion.aufnehmen'), () => zuruecknehmen(x.nr), 'klein') : '') },
      ], { zeilen: gefiltert, leer: t('leer.ausgelassen') });
    } else {
      liste = tabelle([
        { titel: t('spalte.nr'), klasse: 'nr', wert: (x) => x.nr },
        { titel: t('spalte.bezeichnung'), wert: (x) => bezeichnung(x) },
        { titel: t('spalte.kz'), klasse: 'eng', wert: (x) => x.kennzeichen || '' },
        { titel: t('spalte.kontoart'), klasse: 'eng', wert: (x) => {
          const s = auswahl([['', '–'], ...KONTOARTEN], x.kontoart || '', { 'aria-label': `${t('spalte.kontoart')} ${x.nr}` });
          s.addEventListener('change', () => { if (s.value) setzen(x.nr, { kontoart: s.value }); });
          return s;
        } },
        { titel: t('spalte.status'), wert: (x) => status(x) },
        { titel: '', klasse: 'eng', wert: (x) => h('span', {},
          x.pruefen && !e.korrekturen[x.nr] ? knopf(t('aktion.bestaetigen'), () => setzen(x.nr, { kontoart: x.kontoart }), 'klein') : null,
          e.korrekturen[x.nr] ? knopf(t('aktion.zuruecknehmen'), () => zuruecknehmen(x.nr), 'klein leise') : null,
          knopf(t('aktion.weglassen'), () => weglassen(x.nr), 'klein leise')) },
      ], { zeilen: gefiltert, leer: t(`leer.${reiter}`),
        zeilenklasse: (x) => (x.offen || !x.kontoart ? 'zeile-offen' : (x.pruefen ? 'zeile-pruefen' : (e.korrekturen[x.nr] ? 'zeile-korrigiert' : ''))),
        fuss: t('fuss.konten', { n: gefiltert.length, alle: listen[reiter].length }) });
    }
    inhalt.replaceChildren(reiterleiste(reiterDef, reiter, (id) => { reiter = id; listeZeichnen(); }), h('div', { class: 'werkzeugzeile' }, werkzeuge), liste);
  }

  function bezeichnung(x) {
    if (!(x.offen && !x.bezeichnung.trim()) && !e.korrekturen[x.nr]?.bezeichnung) return x.bezeichnung;
    const f = h('input', { type: 'text', value: x.bezeichnung, maxlength: '200', 'aria-label': `${t('spalte.bezeichnung')} ${x.nr}` });
    f.addEventListener('change', () => { if (f.value.trim()) setzen(x.nr, { bezeichnung: f.value.trim() }); });
    return f;
  }

  function status(x) {
    const teile = [];
    if (x.offen) teile.push(h('span', { class: 'marke-klein fehler' }, t('status.offen')), ' ', x.offen);
    else if (x.pruefen && !e.korrekturen[x.nr]) teile.push(h('span', { class: 'marke-klein warnung' }, t('status.pruefen')), ' ', x.pruefen);
    else if (e.korrekturen[x.nr]) teile.push(h('span', { class: 'marke-klein korrigiert' }, t('status.korrigiert')));
    else teile.push(h('span', { class: 'hinweis' }, x.kontoart_herkunft === 'quelle' ? t('status.quelle') : t('status.regel')));
    if (x.fussnote) teile.push(' ', h('span', { class: 'hinweis' }, `(${x.fussnote})`));
    return h('span', {}, teile);
  }

  function fussZeichnen() {
    const bereit = e.bericht.bereit;
    fuss.replaceChildren(
      h('span', { class: 'hinweis' }, bereit ? t('fuss.bereit') : t('fuss.nicht_bereit', { n: e.bericht.offen })),
      knopf(t('aktion.verwerfen'), async () => {
        if (!await bestaetigen(t('verwerfen.titel'), t('verwerfen.text'), t('aktion.verwerfen'))) return;
        try { await api('DELETE', `/entwuerfe/${e.id}`); k.navigieren('#/system/kontenrahmen/import'); } catch (f) { k.fehler(f); }
      }, 'gefahr'),
      knopf(t('aktion.speichern'), speichern, 'primaer', { disabled: !bereit, id: 'vorlage-speichern' }));
  }

  async function speichern() {
    try {
      const v = await api('POST', `/entwuerfe/${e.id}/vorlage`);
      await k.menueNeuLaden();
      b.replaceChildren(seitenkopf(t('import.titel')), schritte(t, 3),
        h('div', { class: 'karte' }, h('h2', {}, t('gespeichert.titel')),
          h('p', {}, t('gespeichert.text', { name: v.name, n: v.bericht.konten })),
          h('div', { class: 'leiste-unten' },
            knopf(t('aktion.vorlage_ansehen'), () => k.navigieren(`#/system/kontenrahmen/vorlage?kennung=${v.kennung}`)),
            k.mandant ? knopf(t('aktion.anwenden_in', { mandant: k.mandantName || k.mandant }),
              () => k.navigieren(`#/m/${k.mandant}/kontenrahmen/anwenden?vorlage=${v.kennung}`), 'primaer') : null)),
        k.mandant ? null : h('p', { class: 'hinweis' }, t('gespeichert.mandant_waehlen')));
      k.melden(t('gespeichert.meldung'), 'erfolg');
    } catch (f) { k.fehler(f); }
  }

  function alles() { kopfZeichnen(); listeZeichnen(); fussZeichnen(); }
  alles();
}
