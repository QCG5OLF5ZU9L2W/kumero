// SPDX-License-Identifier: AGPL-3.0-or-later
// Zugang der Weboberfläche zur REST-API (F18): einziger Weg zu Daten. Keine fremden Quellen.

export class ApiFehler extends Error {
  constructor(status, problem, text) {
    super(problem?.detail || text || `Fehler ${status}`);
    this.status = status;
    this.problem = problem;
  }

  /** Feldfehler als lesbarer Text */
  get felder() {
    return (this.problem?.fehler || []).map((f) => `${f.feld}: ${f.meldung}`).join('; ');
  }
}

// Abgelaufene Sitzung: Der Rahmen meldet hier an, was dann geschieht (neu anmelden, ohne die Seite zu verlieren).
let beiAbgemeldet = null;
export function abgemeldetMelden(fn) { beiAbgemeldet = fn; }

// Unter der eigenen Domain eines Mandanten steht er nicht im Pfad (VERWALTUNG.md 18, DM10): Dann sind auch `/api/v1/…`
// und `/b/<buch>/api/v1/…` Aufrufe im Mandanten – ausgenommen, was es für jede Adresse gibt (Anmeldung, „ich“).
let eigeneDomain = false;
export function eigeneDomainSetzen(an) { eigeneDomain = Boolean(an); }
const ALLGEMEIN = /^\/api\/v1\/(anmeldung|abmeldung|ich|registrierung)(\/|$)/;
const imMandanten = (pfad) => pfad.startsWith('/m/') || (eigeneDomain && (pfad.startsWith('/b/') || (pfad.startsWith('/api/v1/') && !ALLGEMEIN.test(pfad))));

/**
 * Ein Aufruf der API.
 * @param {string} methode
 * @param {string} pfad absolut, z. B. /m/praxis/api/v1/konten
 * @param {{json?: any, roh?: Blob|ArrayBuffer, typ?: string, idempotent?: boolean, kopf?: Record<string, string>, mitKopf?: boolean}} [o]
 *        kopf: weitere Kopfzeilen der Anfrage, z. B. {'If-Match': etag} (F19);
 *        mitKopf: liefert {daten, kopf} statt nur der Daten, z. B. für den ETag eines Entwurfs
 */
export async function api(methode, pfad, o = {}) {
  const kopf = { Accept: 'application/json', ...(o.kopf || {}) };
  let koerper;
  if (o.json !== undefined) {
    kopf['Content-Type'] = 'application/json';
    koerper = JSON.stringify(o.json);
  } else if (o.roh !== undefined) {
    kopf['Content-Type'] = o.typ || 'application/octet-stream';
    koerper = o.roh;
  }
  // Schreibende Aufrufe im Mandanten brauchen einen Idempotenzschlüssel (ANWENDUNG.md 3)
  const idempotent = o.idempotent ?? (methode !== 'GET' && imMandanten(pfad));
  if (idempotent) kopf['Idempotency-Key'] = crypto.randomUUID();
  const r = await fetch(pfad, { method: methode, headers: kopf, credentials: 'same-origin', body: koerper });
  const text = await r.text();
  let daten = null;
  try { daten = text ? JSON.parse(text) : null; } catch { daten = null; }
  if (r.status === 401 && daten?.code === 'anmeldung.erforderlich' && beiAbgemeldet) beiAbgemeldet();
  if (!r.ok) throw new ApiFehler(r.status, daten, r.statusText);
  return o.mitKopf ? { daten, kopf: r.headers } : daten;
}

const b64 = {
  zuBytes: (s) => Uint8Array.from(atob(s.replace(/-/g, '+').replace(/_/g, '/') + '==='.slice((s.length + 3) % 4)), (c) => c.charCodeAt(0)),
  vonBytes: (b) => btoa(String.fromCharCode(...new Uint8Array(b))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, ''),
};

/** Passkey registrieren mit dem Token aus dem Registrierungslink. */
export async function passkeyRegistrieren(token, bezeichnung, code = null) {
  const o = await api('POST', '/api/v1/registrierung/optionen', { json: { token, ...(code ? { code } : {}) } });
  const cred = await navigator.credentials.create({ publicKey: {
    ...o,
    challenge: b64.zuBytes(o.challenge),
    user: { ...o.user, id: b64.zuBytes(o.user.id) },
    excludeCredentials: o.excludeCredentials.map((c) => ({ ...c, id: b64.zuBytes(c.id) })),
  } });
  await api('POST', '/api/v1/registrierung', { json: {
    token, bezeichnung: bezeichnung || null, ...(code ? { code } : {}), id: cred.id,
    response: { attestationObject: b64.vonBytes(cred.response.attestationObject), clientDataJSON: b64.vonBytes(cred.response.clientDataJSON) },
  } });
}

/** Mit Passkey anmelden (auffindbare Zugangsdaten, keine Nutzername-Eingabe). */
export async function anmelden() {
  const o = await api('POST', '/api/v1/anmeldung/optionen');
  const cred = await navigator.credentials.get({ publicKey: { ...o, challenge: b64.zuBytes(o.challenge), allowCredentials: [] } });
  return api('POST', '/api/v1/anmeldung', { json: {
    id: cred.id,
    response: {
      authenticatorData: b64.vonBytes(cred.response.authenticatorData), clientDataJSON: b64.vonBytes(cred.response.clientDataJSON),
      signature: b64.vonBytes(cred.response.signature), userHandle: cred.response.userHandle ? b64.vonBytes(cred.response.userHandle) : null,
    },
  } });
}

/**
 * Freigabe mit dem Passkey bestätigen (F38, F68): optionen aus POST …/freigaben/challenge,
 * Ergebnis ist die Assertion für POST …/freigaben.
 */
export async function passkeyBestaetigen(optionen) {
  const cred = await navigator.credentials.get({ publicKey: {
    ...optionen,
    challenge: b64.zuBytes(optionen.challenge),
    allowCredentials: (optionen.allowCredentials || []).map((c) => ({ ...c, id: b64.zuBytes(c.id) })),
  } });
  return {
    id: cred.id,
    response: {
      authenticatorData: b64.vonBytes(cred.response.authenticatorData), clientDataJSON: b64.vonBytes(cred.response.clientDataJSON),
      signature: b64.vonBytes(cred.response.signature), userHandle: cred.response.userHandle ? b64.vonBytes(cred.response.userHandle) : null,
    },
  };
}
