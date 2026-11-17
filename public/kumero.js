// SPDX-License-Identifier: AGPL-3.0-or-later
// Erste Oberfläche: nutzt ausschließlich die REST-API (F18).
'use strict';

const $ = (id) => document.getElementById(id);
const b64 = {
  zuBytes: (s) => Uint8Array.from(atob(s.replace(/-/g, '+').replace(/_/g, '/') + '==='.slice((s.length + 3) % 4)), (c) => c.charCodeAt(0)),
  vonBytes: (b) => btoa(String.fromCharCode(...new Uint8Array(b))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, ''),
};

function melden(text, fehler = false) {
  const m = $('meldung');
  m.textContent = text;
  m.className = fehler ? 'fehler' : '';
}

async function api(methode, pfad, koerper, idempotent = false) {
  const kopf = { 'Accept': 'application/json' };
  if (koerper !== undefined) kopf['Content-Type'] = 'application/json';
  if (idempotent) kopf['Idempotency-Key'] = crypto.randomUUID();
  const r = await fetch(pfad, { method: methode, headers: kopf, credentials: 'same-origin',
    body: koerper === undefined ? undefined : JSON.stringify(koerper) });
  const text = await r.text();
  const daten = text ? JSON.parse(text) : null;
  if (!r.ok) {
    const e = new Error(daten?.detail || `Fehler ${r.status}`);
    e.status = r.status; e.problem = daten;
    throw e;
  }
  return daten;
}

async function passkeyRegistrieren(token) {
  const o = await api('POST', '/api/v1/registrierung/optionen', { token });
  const cred = await navigator.credentials.create({ publicKey: {
    ...o,
    challenge: b64.zuBytes(o.challenge),
    user: { ...o.user, id: b64.zuBytes(o.user.id) },
    excludeCredentials: o.excludeCredentials.map((c) => ({ ...c, id: b64.zuBytes(c.id) })),
  } });
  await api('POST', '/api/v1/registrierung', {
    token, bezeichnung: $('bezeichnung').value || null, id: cred.id,
    response: { attestationObject: b64.vonBytes(cred.response.attestationObject), clientDataJSON: b64.vonBytes(cred.response.clientDataJSON) },
  });
}

async function anmelden() {
  const o = await api('POST', '/api/v1/anmeldung/optionen');
  const cred = await navigator.credentials.get({ publicKey: { ...o, challenge: b64.zuBytes(o.challenge), allowCredentials: [] } });
  return api('POST', '/api/v1/anmeldung', {
    id: cred.id,
    response: { authenticatorData: b64.vonBytes(cred.response.authenticatorData), clientDataJSON: b64.vonBytes(cred.response.clientDataJSON),
                signature: b64.vonBytes(cred.response.signature), userHandle: cred.response.userHandle ? b64.vonBytes(cred.response.userHandle) : null },
  });
}

let ich = null;
const mandant = () => $('mandant').value;

async function zeigen() {
  try {
    ich = await api('GET', '/api/v1/ich');
  } catch (e) {
    if (e.status !== 401) throw e;
    ich = null;
  }
  $('abgemeldet').hidden = !!ich;
  $('angemeldet').hidden = !ich;
  if (!ich) return;
  $('name').textContent = ich.name;
  $('mandant').replaceChildren(...ich.mandanten.map((m) => new Option(m.name, m.kennung)));
  if (ich.mandanten.length === 0) {
    melden('Noch kein Zugang zu einem Mandanten – auf dem Server: bin/kumero zugang:gewaehren <nutzer-id> <mandant>');
    return;
  }
  await kontenLaden();
}

async function kontenLaden() {
  const m = ich.mandanten.find((x) => x.kennung === mandant());
  $('betriebsart').textContent = m.betriebsart === 'test' ? 'Testbetrieb' : (m.betriebsart || '');
  const konten = await api('GET', `/m/${mandant()}/api/v1/konten`);
  $('konten').replaceChildren(...konten.map((k) => {
    const tr = document.createElement('tr');
    for (const w of [k.nr, k.bezeichnung, k.kontoart, k.bebucht ? 'ja' : '']) {
      const td = document.createElement('td'); td.textContent = w; tr.append(td);
    }
    return tr;
  }));
}

function fehlerZeigen(e) {
  if (e.name === 'NotAllowedError') return melden('Vom Browser oder Passkey abgebrochen.', true);
  const felder = e.problem?.fehler?.map((f) => `${f.feld}: ${f.meldung}`).join('; ');
  melden(e.message + (felder ? ` (${felder})` : ''), true);
}

window.addEventListener('DOMContentLoaded', async () => {
  if (!window.PublicKeyCredential) {
    melden('Dieser Browser unterstützt keine Passkeys (oder die Seite ist nicht über https bzw. http://localhost geöffnet).', true);
  }
  const token = new URLSearchParams(location.hash.slice(1)).get('registrierung');
  if (token) {
    $('registrierung').hidden = false;
    $('registrieren-form').addEventListener('submit', async (ev) => {
      ev.preventDefault();
      try {
        await passkeyRegistrieren(token);
        history.replaceState(null, '', location.pathname);
        $('registrierung').hidden = true;
        melden('Passkey registriert. Jetzt anmelden.');
      } catch (e) { fehlerZeigen(e); }
    });
  }
  $('anmelden').addEventListener('click', async () => {
    try { await anmelden(); melden(''); await zeigen(); } catch (e) { fehlerZeigen(e); }
  });
  $('abmelden').addEventListener('click', async () => {
    try { await api('POST', '/api/v1/abmeldung'); await zeigen(); melden('Abgemeldet.'); } catch (e) { fehlerZeigen(e); }
  });
  $('mandant').addEventListener('change', () => kontenLaden().catch(fehlerZeigen));
  $('konto-form').addEventListener('submit', async (ev) => {
    ev.preventDefault();
    try {
      const k = await api('POST', `/m/${mandant()}/api/v1/konten`,
        { nr: $('nr').value, bezeichnung: $('kbez').value, kontoart: $('kontoart').value }, true);
      melden(`Konto ${k.nr} angelegt.`);
      ev.target.reset();
      await kontenLaden();
    } catch (e) { fehlerZeigen(e); }
  });
  try { await zeigen(); } catch (e) { fehlerZeigen(e); }
});
