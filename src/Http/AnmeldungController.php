<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Http;

use Doctrine\DBAL\Connection;
use Kumero\Api\V1\Problem;
use Kumero\Plattform\Anfrage;
use Kumero\Plattform\Einstellungen;
use Kumero\Plattform\Identitaet;
use Kumero\Plattform\Webauthn\Base64Url;
use Kumero\Plattform\Webauthn\Pruefer;
use Kumero\Plattform\Webauthn\WebauthnFehler;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Anmeldung und Registrierung per Passkey (ANWENDUNG.md Abschnitt 5, E2, E3; F114, VERWALTUNG.md Abschnitt 3 und 11).
 *
 * Es gibt eine Anmeldeseite für die Installation. Der vorgelegte Passkey trägt als Nutzerkennung (userHandle)
 * ein Pseudonym; die Verwaltungsdatenbank sagt dazu nur den Mandanten. Geprüft wird der Passkey dort, wo die
 * Identität liegt: im Mandanten (Schema mandant in der Mandanten-Datenbank) bzw. für System-Verwalter in der Verwaltung (Schema verwaltung).
 * Die Zuordnung Pseudonym → Mandant verschafft keinen Zugang: Kennt der Mandant den Passkey nicht, scheitert
 * die Anmeldung. Die Challenge bleibt systemweit und wird in der Transaktion des Kernels eingelöst.
 */
final class AnmeldungController
{
    private const GUELTIG_MS = 300000;
    private const PSEUDONYM = '/^[A-Za-z0-9_-]{22,86}$/';

    public function optionen(Anfrage $a): Response
    {
        return Antwort::json([
            'challenge' => $this->challenge($a),
            'rpId' => $a->konfig->rpId,
            'timeout' => self::GUELTIG_MS,
            'userVerification' => 'required',
            'allowCredentials' => [],          // Passkeys: der Authentifikator kennt den Nutzer
        ]);
    }

    public function anmelden(Anfrage $a): Response
    {
        $j = $a->json();
        $r = is_array($j['response'] ?? null) ? $j['response'] : [];
        $id = (string) ($j['id'] ?? '');
        try {
            [$cd, $challenge] = $this->challengeEinloesen($a, (string) ($r['clientDataJSON'] ?? ''));
            // Das Pseudonym aus dem Passkey bestimmt den Mandanten (U1)
            $pseudonym = is_string($r['userHandle'] ?? null) ? $r['userHandle'] : '';
            if (!preg_match(self::PSEUDONYM, $pseudonym)) {
                throw new WebauthnFehler('Nutzerkennung des Passkeys (userHandle) fehlt');
            }
            $z = $a->verwaltung()->fetchAssociative('SELECT mandant FROM verwaltung.pseudonym WHERE pseudonym = ?', [$pseudonym]);
            if ($z === false) {
                throw new WebauthnFehler('Pseudonym unbekannt');
            }
            $ort = $this->ort($a, $z['mandant']) ?? throw new WebauthnFehler('Mandant unbekannt oder stillgelegt');
            [$db, $schema, $mandant] = $ort;
            // Geprüft wird der Passkey beim Mandanten: Schlüssel und Identität liegen nur dort
            $c = $db->fetchAssociative(
                "SELECT c.public_key, c.zaehler FROM $schema.anmelde_credential c JOIN $schema.nutzer n ON n.id = c.nutzer
                  WHERE c.credential_id = ? AND c.widerrufen_am IS NULL AND n.pseudonym = ? AND n.aktiv", [$id, $pseudonym]);
            if ($c === false) {
                throw new WebauthnFehler('Passkey am Ziel des Pseudonyms unbekannt oder widerrufen');
            }
            $zaehler = $this->pruefer($a)->assertion($cd, Base64Url::dekodieren((string) ($r['authenticatorData'] ?? '')),
                Base64Url::dekodieren((string) ($r['signature'] ?? '')), $challenge,
                self::bytea($c['public_key']), (int) $c['zaehler']);
        } catch (WebauthnFehler $e) {
            error_log(sprintf('kumero[%s] anmeldung abgewiesen: %s', $a->anfrageId, $e->getMessage()));
            throw new Problem(401, 'webauthn.ungueltig', 'Anmeldung mit diesem Passkey nicht möglich');
        }
        $geheim = Base64Url::kodieren(random_bytes(32));
        $nutzer = $db->transactional(function (Connection $db) use ($schema, $id, $zaehler, $geheim, $a): string {
            $nutzer = (string) $db->fetchOne("SELECT $schema.anmeldung_vermerken(?, ?)", [$id, $zaehler]);
            $db->executeStatement("SELECT $schema.sitzung_anlegen(?, ?, make_interval(hours => ?))",
                [$schema === 'mandant' ? $nutzer : 'nutzer:' . $nutzer, hash('sha256', $geheim), $a->konfig->hoechstensStd]);
            return $nutzer;
        });
        $antwort = Antwort::json($this->profil($a, $ort, $nutzer));
        $antwort->headers->setCookie(Cookie::create(Identitaet::COOKIE, Identitaet::cookieWert($mandant, $geheim), 0, '/', null, true, true, false, Cookie::SAMESITE_STRICT));
        return $antwort;
    }

    public function abmelden(Anfrage $a): Response
    {
        $ident = $a->identitaet;
        if ($ident?->sitzungHash !== null && ($ort = $this->ort($a, $ident->mandant)) !== null) {
            [$db, $schema] = $ort;
            $db->executeStatement("SELECT $schema.sitzung_beenden(?)", [$ident->sitzungHash]);
        }
        $antwort = Antwort::leer();
        $antwort->headers->clearCookie(Identitaet::COOKIE, '/', null, true, true, Cookie::SAMESITE_STRICT);
        return $antwort;
    }

    public function ich(Anfrage $a): Response
    {
        if ($a->identitaet?->art !== 'sitzung') {
            // Dienstkonto: nur sein Mandant
            return Antwort::json(['subjekt' => $a->identitaet?->subjekt, 'mandant' => $a->identitaet?->mandant,
                                  'mandanten' => [['kennung' => $a->identitaet?->mandant]]]);
        }
        [$ort, $nutzer] = $this->eigenerNutzer($a);
        return Antwort::json($this->profil($a, $ort, $nutzer));
    }

    /**
     * Eigenes Profil ändern: die Sprache (PROFIL.md U4/U5; null = wie der Browser) – oder, solange die
     * E-Mail-Adresse fehlt (überführter Bestand, U6), einmalig Vorname(n), Familienname und E-Mail.
     * Sonst ändert der Nutzer seinen Namen nicht selbst (PROFIL.md U2).
     */
    public function ichAendern(Anfrage $a): Response
    {
        [$ort, $nutzer] = $this->eigenerNutzer($a);
        [$db, $schema] = $ort;
        $j = $a->json();
        $schluessel = array_keys($j);
        sort($schluessel);
        if ($schluessel === ['email', 'familienname', 'vornamen'] && $schema === 'mandant') {
            $fehler = [];
            foreach (['vornamen', 'familienname'] as $f) {
                if (!is_string($j[$f]) || trim($j[$f]) === '' || mb_strlen($j[$f]) > 200) {
                    $fehler[] = ['feld' => $f, 'meldung' => 'Pflichtangabe, höchstens 200 Zeichen'];
                }
            }
            if (!is_string($j['email']) || mb_strlen($j['email']) > 254 || !preg_match('/^[^@\s]+@[^@\s]+$/', trim($j['email']))) {
                $fehler[] = ['feld' => 'email', 'meldung' => 'E-Mail-Adresse angeben'];
            }
            if ($fehler !== []) {
                throw Problem::ungueltig('Eingabe ungültig', $fehler);
            }
            $db->executeStatement('SELECT mandant.nutzer_angaben_ergaenzen(?, ?::uuid, ?, ?, ?)',
                ['nutzer:' . $nutzer, $nutzer, $j['familienname'], $j['vornamen'], $j['email']]);
            return Antwort::json($this->profil($a, $ort, $nutzer));
        }
        if ($schluessel !== ['sprache'] || !($j['sprache'] === null || in_array($j['sprache'], Einstellungen::SPRACHEN, true))) {
            throw Problem::ungueltig('Eingabe ungültig', [['feld' => 'sprache', 'meldung' => 'Erlaubt: null (wie der Browser), ' . implode(', ', Einstellungen::SPRACHEN)]]);
        }
        $db->executeStatement("SELECT $schema.sprache_setzen(?, ?::uuid, ?)", ['nutzer:' . $nutzer, $nutzer, $j['sprache']]);
        return Antwort::json($this->profil($a, $ort, $nutzer));
    }

    public function einstellungenLesen(Anfrage $a): Response
    {
        [[$db, $schema], $nutzer] = $this->eigenerNutzer($a);
        return Antwort::json(Einstellungen::wirksam($db, $schema, $nutzer));
    }

    /** Einzelne Einstellungen setzen; null setzt auf den Standard zurück. */
    public function einstellungenSetzen(Anfrage $a): Response
    {
        [[$db, $schema], $nutzer] = $this->eigenerNutzer($a);
        $j = $a->json();
        $fehler = [];
        foreach ($j as $k => $w) {
            if (!isset(Einstellungen::KERN[$k])) {
                $fehler[] = ['feld' => (string) $k, 'meldung' => 'Unbekannte Einstellung'];
            } elseif ($w !== null && !in_array($w, Einstellungen::KERN[$k], true)) {
                $fehler[] = ['feld' => (string) $k, 'meldung' => 'Erlaubt: ' . implode(', ', Einstellungen::KERN[$k])];
            }
        }
        if ($j === [] || $fehler !== []) {
            throw Problem::ungueltig('Eingabe ungültig', $fehler);
        }
        $db->transactional(function (Connection $db) use ($schema, $nutzer, $j): void {
            foreach ($j as $k => $w) {
                $db->executeStatement("SELECT $schema.einstellung_setzen(?, ?::uuid, ?, ?::jsonb)",
                    ['nutzer:' . $nutzer, $nutzer, $k, $w === null ? null : json_encode($w)]);
            }
        });
        return Antwort::json(Einstellungen::wirksam($db, $schema, $nutzer));
    }

    /**
     * Was ein Link verlangt, bevor der Browser nach dem Passkey fragt: ob ein Code dazugehört und bis wann er gilt.
     * Zählt keine Fehlversuche und verrät nichts über die Person.
     */
    public function registrierungPruefen(Anfrage $a): Response
    {
        $j = $a->json();
        $n = $this->registrierenderNutzer($a, is_string($j['token'] ?? null) && $j['token'] !== '' ? $j['token'] : '-', null, false);
        return Antwort::json(['code_noetig' => $n['code_noetig'], 'gueltig_bis' => $n['gueltig_bis'],
            'mandant' => $n['ort'][2] === null ? null : ['kennung' => $n['ort'][2], 'name' => $n['wo']]]);
    }

    public function registrierungOptionen(Anfrage $a): Response
    {
        $j = $a->json(false);
        $n = $this->registrierenderNutzer($a, (string) ($j['token'] ?? ''), is_string($j['code'] ?? null) ? $j['code'] : null);
        [$db, $schema] = $n['ort'];
        $vorhanden = $db->fetchFirstColumn(
            "SELECT credential_id FROM $schema.anmelde_credential WHERE nutzer = ? AND widerrufen_am IS NULL", [$n['id']]);
        return Antwort::json([
            'challenge' => $this->challenge($a),
            'rp' => ['id' => $a->konfig->rpId, 'name' => 'kumero'],
            // Nutzerkennung im Passkey ist das Pseudonym; der Eintrag im Passkey-Speicher nennt den Mandanten (U2)
            'user' => ['id' => $n['pseudonym'], 'name' => $n['name'] . ' – ' . $n['wo'], 'displayName' => $n['name']],
            'pubKeyCredParams' => [['type' => 'public-key', 'alg' => Pruefer::EDDSA], ['type' => 'public-key', 'alg' => Pruefer::ES256]],
            'authenticatorSelection' => ['residentKey' => 'required', 'requireResidentKey' => true, 'userVerification' => 'required'],
            'attestation' => 'none',
            'timeout' => self::GUELTIG_MS,
            'excludeCredentials' => array_map(fn ($id) => ['type' => 'public-key', 'id' => $id], $vorhanden),
            'mandant' => $n['ort'][2] === null ? null : ['kennung' => $n['ort'][2], 'name' => $n['wo']],
        ]);
    }

    public function registrieren(Anfrage $a): Response
    {
        $j = $a->json();
        $token = (string) ($j['token'] ?? '');
        $n = $this->registrierenderNutzer($a, $token, is_string($j['code'] ?? null) ? $j['code'] : null);
        [$db, $schema] = $n['ort'];
        try {
            [$cd, $challenge] = $this->challengeEinloesen($a, (string) ($j['response']['clientDataJSON'] ?? ''));
            $reg = $this->pruefer($a)->registrierung($cd, Base64Url::dekodieren((string) ($j['response']['attestationObject'] ?? '')), $challenge);
            if (Base64Url::kodieren($reg['credential_id']) !== ($j['id'] ?? null)) {
                throw new WebauthnFehler('Credential-ID passt nicht zur Registrierung');
            }
        } catch (WebauthnFehler $e) {
            error_log(sprintf('kumero[%s] registrierung abgewiesen: %s', $a->anfrageId, $e->getMessage()));
            throw new Problem(422, 'webauthn.ungueltig', 'Registrierung des Passkeys nicht möglich');
        }
        $bezeichnung = isset($j['bezeichnung']) && is_string($j['bezeichnung']) ? mb_substr($j['bezeichnung'], 0, 100) : null;
        $funktion = $schema === 'mandant' ? 'mandant.anmelde_credential_registrieren' : 'verwaltung.credential_registrieren';
        $db->executeStatement(
            "SELECT $funktion(?, ?::uuid, ?, decode(?, 'hex'), ?, ?, ?, ?)",
            [$token !== '' ? 'system:registrierung' : 'nutzer:' . $n['id'], $n['id'], Base64Url::kodieren($reg['credential_id']),
             bin2hex($reg['public_key']), $reg['alg'], $reg['zaehler'], $bezeichnung, $token !== '' ? hash('sha256', $token) : null]);
        return Antwort::json(['credential' => Base64Url::kodieren($reg['credential_id']), 'algorithmus' => $reg['alg']], 201);
    }

    // ------------------------------------------------------------------------

    private function pruefer(Anfrage $a): Pruefer
    {
        return new Pruefer($a->konfig->rpId, [$a->konfig->adresse]);
    }

    /**
     * Wo eine Identität liegt: Datenbank, Schema und Kennung des Mandanten – bzw. die Verwaltung für
     * System-Verwalter (Mandant null). Null, wenn der Mandant unbekannt oder stillgelegt ist.
     *
     * @return array{0: Connection, 1: 'mandant'|'verwaltung', 2: ?string}|null
     */
    private function ort(Anfrage $a, ?string $mandant): ?array
    {
        if ($mandant === null) {
            return [$a->verwaltung(), 'verwaltung', null];
        }
        $db = Identitaet::mandantDb($a->datenbank, $mandant);
        return $db === null ? null : [$db, 'mandant', $mandant];
    }

    /** Neue Challenge (≥ 32 Byte), einmalig, 5 Minuten (F68 sinngemäß). */
    private function challenge(Anfrage $a): string
    {
        $c = Base64Url::kodieren(random_bytes(32));
        $a->verwaltung()->executeStatement('SELECT verwaltung.challenge_ausstellen(?)', [$c]);
        return $c;
    }

    /** @return array{0: string, 1: string} clientDataJSON roh und Challenge roh */
    private function challengeEinloesen(Anfrage $a, string $clientDataB64): array
    {
        $cd = Base64Url::dekodieren($clientDataB64);
        $j = json_decode($cd, true);
        $c = is_array($j) && is_string($j['challenge'] ?? null) ? $j['challenge'] : '';
        if (!preg_match('/^[A-Za-z0-9_-]{43,}$/', $c)) {
            throw new WebauthnFehler('Challenge fehlt');
        }
        $v = $a->verwaltung();
        $v->executeStatement('SAVEPOINT challenge');
        try {
            $v->executeStatement('SELECT verwaltung.challenge_einloesen(?)', [$c]);
        } catch (\Throwable) {
            $v->executeStatement('ROLLBACK TO SAVEPOINT challenge');
            throw new WebauthnFehler('Challenge unbekannt, abgelaufen oder verbraucht');
        }
        return [$cd, Base64Url::dekodieren($c)];
    }

    /**
     * Ein Profil hat nur ein Mensch mit Sitzung; Dienstkonten haben keines.
     *
     * @return array{0: array{0: Connection, 1: 'mandant'|'verwaltung', 2: ?string}, 1: string} Ort und Nutzer-ID
     */
    private function eigenerNutzer(Anfrage $a): array
    {
        $ident = $a->identitaet;
        $nutzer = $ident?->art === 'sitzung' ? $ident->nutzerId() : null;
        $ort = $nutzer === null ? null : $this->ort($a, $ident->mandant);
        if ($nutzer === null || $ort === null) {
            throw new Problem(403, 'profil.nur_nutzer', 'Ein Profil haben nur angemeldete Nutzer');
        }
        return [$ort, $nutzer];
    }

    /**
     * Für wen ein Passkey registriert wird: über einen Link (das Token nennt den Mandanten, damit die Seite ohne
     * Anmeldung weiß, wohin: „<kennung>.<zufall>“; ohne Kennung ist es der Link eines System-Verwalters) oder
     * für den angemeldeten Nutzer selbst.
     *
     * Gehört zum Link ein Code (VERWALTUNG.md Abschnitt 6), wird er hier geprüft: Ein falscher Code zählt als
     * Fehlversuch – das schreibt die Datenbank des Mandanten sofort fest, auch wenn die Anfrage scheitert –, nach
     * fünf ist der Zugang ungültig. Mit `$codePruefen = false` wird nur nachgesehen, ob ein Code nötig ist.
     *
     * @return array{ort: array{0: Connection, 1: 'mandant'|'verwaltung', 2: ?string}, id: string, name: string, pseudonym: string, wo: string, code_noetig: bool, gueltig_bis: ?string}
     */
    private function registrierenderNutzer(Anfrage $a, string $token, ?string $code = null, bool $codePruefen = true): array
    {
        $ungueltig = new Problem(403, 'registrierung.ungueltig', 'Registrierungslink ungültig, abgelaufen oder verbraucht');
        if ($token !== '') {
            if (!preg_match('/^(?:([a-z][a-z0-9-]{1,31})\.)?[A-Za-z0-9_-]{43,}$/', $token, $m)) {
                throw $ungueltig;
            }
            $ort = $this->ort($a, ($m[1] ?? '') === '' ? null : $m[1]) ?? throw $ungueltig;
            $bedingung = 'r.token_hash = ? AND r.verwendet_am IS NULL AND r.ablauf > now() AND n.aktiv'
                . ($ort[1] === 'mandant' ? ' AND r.ungueltig_am IS NULL' : ' AND n.verwalter');
            $parameter = [hash('sha256', $token)];
            $von = "{$ort[1]}.registrierung r JOIN {$ort[1]}.nutzer n ON n.id = r.nutzer";
        } else {
            $ident = $a->identitaet;
            $nutzer = $ident?->art === 'sitzung' ? $ident->nutzerId() : null;
            $ort = $nutzer === null ? null : $this->ort($a, $ident->mandant);
            if ($ort === null) {
                throw new Problem(401, 'anmeldung.erforderlich', 'Registrierungslink oder Anmeldung nötig');
            }
            $bedingung = 'n.id = ? AND n.aktiv';
            $parameter = [$nutzer];
            $von = "{$ort[1]}.nutzer n";
        }
        $name = $ort[1] === 'mandant' ? "trim(coalesce(n.vornamen, '') || ' ' || n.familienname)" : 'n.name';
        $zugang = $token !== '' && $ort[1] === 'mandant'
            ? ", r.code_hash IS NOT NULL AS code_noetig, to_char(r.ablauf AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"') AS gueltig_bis" : '';
        $z = $ort[0]->fetchAssociative("SELECT n.id, $name AS name, n.pseudonym$zugang FROM $von WHERE $bedingung", $parameter);
        if ($z === false || !is_string($z['pseudonym'])) {
            throw $token !== '' ? $ungueltig : new Problem(403, 'profil.nur_nutzer', 'Ein Profil haben nur angemeldete Nutzer');
        }
        $codeNoetig = in_array($z['code_noetig'] ?? false, [true, 't', 1, '1'], true);
        if ($codeNoetig && $codePruefen) {
            if ($code === null || NutzerController::codeNormal($code) === '') {
                throw new Problem(403, 'registrierung.code_fehlt', 'Zu diesem Link gehört ein Code');
            }
            $erg = (string) $ort[0]->fetchOne('SELECT mandant.registrierung_code_pruefen(?, ?)', [hash('sha256', $token), NutzerController::codeHash($token, $code)]);
            if ($erg === 'falsch') {
                throw new Problem(403, 'registrierung.code_falsch', 'Der Code stimmt nicht. Nach fünf falschen Eingaben ist der Zugang ungültig.');
            }
            if ($erg !== 'ok') {
                throw $ungueltig;
            }
        }
        $wo = $ort[2] === null ? 'Verwaltung'
            : (string) $a->verwaltung()->fetchOne('SELECT name FROM verwaltung.mandant WHERE kennung = ?', [$ort[2]]);
        return ['ort' => $ort, 'id' => (string) $z['id'], 'name' => (string) $z['name'], 'pseudonym' => $z['pseudonym'], 'wo' => $wo,
            'code_noetig' => $codeNoetig, 'gueltig_bis' => $z['gueltig_bis'] ?? null];
    }

    /**
     * Profil der angemeldeten Identität. Ein Nutzer sieht genau seinen einen Mandanten; ein System-Verwalter
     * keinen (U3, U4).
     *
     * @param array{0: Connection, 1: 'mandant'|'verwaltung', 2: ?string} $ort
     * @return array<string, mixed>
     */
    private function profil(Anfrage $a, array $ort, string $nutzer): array
    {
        [$db, $schema, $mandant] = $ort;
        $gemeinsam = ['subjekt' => 'nutzer:' . $nutzer, 'sprachen' => Einstellungen::SPRACHEN,
                      'einstellungen' => Einstellungen::wirksam($db, $schema, $nutzer)];
        if ($schema === 'verwaltung') {
            $n = $db->fetchAssociative('SELECT name, sprache FROM verwaltung.nutzer WHERE id = ?', [$nutzer]);
            return $gemeinsam + ['name' => $n['name'], 'vornamen' => null, 'familienname' => $n['name'], 'email' => null,
                                 'sprache' => $n['sprache'], 'verwalter' => true, 'angaben_fehlen' => false, 'mandant' => null, 'mandanten' => []];
        }
        $n = $db->fetchAssociative('SELECT vornamen, familienname, email, sprache FROM mandant.nutzer WHERE id = ?', [$nutzer]);
        $name = (string) $a->verwaltung()->fetchOne('SELECT name FROM verwaltung.mandant WHERE kennung = ?', [$mandant]);
        return $gemeinsam + ['name' => trim(($n['vornamen'] ?? '') . ' ' . $n['familienname']), 'vornamen' => $n['vornamen'],
                             'familienname' => $n['familienname'], 'email' => $n['email'], 'sprache' => $n['sprache'], 'verwalter' => false,
                             // überführter Bestand: Vorname(n) und E-Mail werden bei der nächsten Anmeldung abgefragt (U6)
                             'angaben_fehlen' => $n['email'] === null, 'mandant' => $mandant,
                             // Betriebsart und Lesemodus gehören zum Buch: `GET /m/{mandant}/api/v1/buecher` (BUECHER.md Abschnitt 8)
                             'mandanten' => [['kennung' => $mandant, 'name' => $name]]];
    }

    /** bytea aus PDO: Ressource oder Text. */
    public static function bytea(mixed $w): string
    {
        if (is_resource($w)) {
            $w = stream_get_contents($w);
        }
        $w = (string) $w;
        return str_starts_with($w, '\\x') ? (string) hex2bin(substr($w, 2)) : $w;
    }
}
