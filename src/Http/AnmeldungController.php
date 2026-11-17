<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Http;

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
 * Anmeldung und Registrierung per Passkey (ANWENDUNG.md Abschnitt 5, E2, E3).
 * Anmelde-Passkeys liegen in der Verwaltungsdatenbank; schreibende Aufrufe
 * laufen dort in einer Transaktion (Kernel).
 */
final class AnmeldungController
{
    private const GUELTIG_MS = 300000;

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
        $v = $a->verwaltung();
        try {
            [$cd, $challenge] = $this->challengeEinloesen($a, (string) ($a->json()['response']['clientDataJSON'] ?? ''));
            $id = (string) ($a->json()['id'] ?? '');
            $c = $v->fetchAssociative('SELECT public_key, zaehler FROM verwaltung.anmelde_credential
                                        WHERE credential_id = ? AND widerrufen_am IS NULL', [$id]);
            if ($c === false) {
                throw new WebauthnFehler('Passkey unbekannt oder widerrufen');
            }
            $r = $a->json()['response'];
            $zaehler = $this->pruefer($a)->assertion($cd, Base64Url::dekodieren((string) ($r['authenticatorData'] ?? '')),
                Base64Url::dekodieren((string) ($r['signature'] ?? '')), $challenge,
                self::bytea($c['public_key']), (int) $c['zaehler']);
        } catch (WebauthnFehler $e) {
            error_log(sprintf('kumero[%s] anmeldung abgewiesen: %s', $a->anfrageId, $e->getMessage()));
            throw new Problem(401, 'webauthn.ungueltig', 'Anmeldung mit diesem Passkey nicht möglich');
        }
        $nutzer = (string) $v->fetchOne('SELECT verwaltung.anmeldung_vermerken(?, ?)', [$id, $zaehler]);
        $geheim = Base64Url::kodieren(random_bytes(32));
        $v->executeStatement('SELECT verwaltung.sitzung_anlegen(?, ?, make_interval(hours => ?))',
            ['nutzer:' . $nutzer, hash('sha256', $geheim), $a->konfig->hoechstensStd]);
        $antwort = Antwort::json($this->profil($a, $nutzer));
        $antwort->headers->setCookie(Cookie::create(Identitaet::COOKIE, $geheim, 0, '/', null, true, true, false, Cookie::SAMESITE_STRICT));
        return $antwort;
    }

    public function abmelden(Anfrage $a): Response
    {
        if ($a->identitaet?->sitzungHash !== null) {
            $a->verwaltung()->executeStatement('SELECT verwaltung.sitzung_beenden(?)', [$a->identitaet->sitzungHash]);
        }
        $antwort = Antwort::leer();
        $antwort->headers->clearCookie(Identitaet::COOKIE, '/', null, true, true, Cookie::SAMESITE_STRICT);
        return $antwort;
    }

    public function ich(Anfrage $a): Response
    {
        $nutzer = $a->identitaet?->nutzerId();
        if ($nutzer === null) {
            // Dienstkonto: nur sein Mandant
            return Antwort::json(['subjekt' => $a->identitaet?->subjekt, 'mandanten' => [['kennung' => $a->identitaet?->mandant]]]);
        }
        return Antwort::json($this->profil($a, $nutzer));
    }

    /** Eigene Sprache ändern (PROFIL.md U4/U5); null = nicht gesetzt, also wie der Browser. */
    public function ichAendern(Anfrage $a): Response
    {
        $nutzer = self::eigenerNutzer($a);
        $j = $a->json();
        if (array_keys($j) !== ['sprache'] || !($j['sprache'] === null || in_array($j['sprache'], Einstellungen::SPRACHEN, true))) {
            throw Problem::ungueltig('Eingabe ungültig', [['feld' => 'sprache', 'meldung' => 'Erlaubt: null (wie der Browser), ' . implode(', ', Einstellungen::SPRACHEN)]]);
        }
        $a->verwaltung()->executeStatement('SELECT verwaltung.sprache_setzen(?, ?::uuid, ?)', ['nutzer:' . $nutzer, $nutzer, $j['sprache']]);
        return Antwort::json($this->profil($a, $nutzer));
    }

    public function einstellungenLesen(Anfrage $a): Response
    {
        return Antwort::json(Einstellungen::wirksam($a->verwaltung(), self::eigenerNutzer($a)));
    }

    /** Einzelne Einstellungen setzen; null setzt auf den Standard zurück. */
    public function einstellungenSetzen(Anfrage $a): Response
    {
        $nutzer = self::eigenerNutzer($a);
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
        foreach ($j as $k => $w) {
            $a->verwaltung()->executeStatement('SELECT verwaltung.einstellung_setzen(?, ?::uuid, ?, ?::jsonb)',
                ['nutzer:' . $nutzer, $nutzer, $k, $w === null ? null : json_encode($w)]);
        }
        return Antwort::json(Einstellungen::wirksam($a->verwaltung(), $nutzer));
    }

    /** Ein Profil hat nur ein Mensch mit Sitzung; Dienstkonten haben keines. */
    private static function eigenerNutzer(Anfrage $a): string
    {
        $nutzer = $a->identitaet?->art === 'sitzung' ? $a->identitaet->nutzerId() : null;
        return $nutzer ?? throw new Problem(403, 'profil.nur_nutzer', 'Ein Profil haben nur angemeldete Nutzer');
    }

    public function registrierungOptionen(Anfrage $a): Response
    {
        [$nutzer, $name] = $this->registrierenderNutzer($a, (string) ($a->json(false)['token'] ?? ''));
        $vorhanden = $a->verwaltung()->fetchFirstColumn(
            'SELECT credential_id FROM verwaltung.anmelde_credential WHERE nutzer = ? AND widerrufen_am IS NULL', [$nutzer]);
        return Antwort::json([
            'challenge' => $this->challenge($a),
            'rp' => ['id' => $a->konfig->rpId, 'name' => 'kumero'],
            'user' => ['id' => Base64Url::kodieren(hex2bin(str_replace('-', '', $nutzer))), 'name' => $name, 'displayName' => $name],
            'pubKeyCredParams' => [['type' => 'public-key', 'alg' => Pruefer::EDDSA], ['type' => 'public-key', 'alg' => Pruefer::ES256]],
            'authenticatorSelection' => ['residentKey' => 'required', 'requireResidentKey' => true, 'userVerification' => 'required'],
            'attestation' => 'none',
            'timeout' => self::GUELTIG_MS,
            'excludeCredentials' => array_map(fn ($id) => ['type' => 'public-key', 'id' => $id], $vorhanden),
        ]);
    }

    public function registrieren(Anfrage $a): Response
    {
        $j = $a->json();
        $token = (string) ($j['token'] ?? '');
        [$nutzer] = $this->registrierenderNutzer($a, $token);
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
        $a->verwaltung()->executeStatement(
            'SELECT verwaltung.credential_registrieren(?, ?::uuid, ?, decode(?, \'hex\'), ?, ?, ?, ?)',
            [$token !== '' ? 'system:registrierung' : 'nutzer:' . $nutzer, $nutzer, Base64Url::kodieren($reg['credential_id']),
             bin2hex($reg['public_key']), $reg['alg'], $reg['zaehler'], $bezeichnung, $token !== '' ? hash('sha256', $token) : null]);
        return Antwort::json(['credential' => Base64Url::kodieren($reg['credential_id']), 'algorithmus' => $reg['alg']], 201);
    }

    // ------------------------------------------------------------------------

    private function pruefer(Anfrage $a): Pruefer
    {
        return new Pruefer($a->konfig->rpId, [$a->konfig->adresse]);
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

    /** @return array{0: string, 1: string} Nutzer-ID und Name */
    private function registrierenderNutzer(Anfrage $a, string $token): array
    {
        if ($token !== '') {
            $z = $a->verwaltung()->fetchAssociative(
                'SELECT n.id, n.name FROM verwaltung.registrierung r JOIN verwaltung.nutzer n ON n.id = r.nutzer
                  WHERE r.token_hash = ? AND r.verwendet_am IS NULL AND r.ablauf > now() AND n.aktiv', [hash('sha256', $token)]);
            if ($z === false) {
                throw new Problem(403, 'registrierung.ungueltig', 'Registrierungslink ungültig, abgelaufen oder verbraucht');
            }
            return [$z['id'], $z['name']];
        }
        $nutzer = $a->identitaet?->nutzerId();
        if ($nutzer === null) {
            throw new Problem(401, 'anmeldung.erforderlich', 'Registrierungslink oder Anmeldung nötig');
        }
        $name = (string) $a->verwaltung()->fetchOne('SELECT name FROM verwaltung.nutzer WHERE id = ?', [$nutzer]);
        return [$nutzer, $name];
    }

    /** @return array<string, mixed> */
    private function profil(Anfrage $a, string $nutzer): array
    {
        $v = $a->verwaltung();
        $n = $v->fetchAssociative('SELECT id, name, sprache, verwalter FROM verwaltung.nutzer WHERE id = ?', [$nutzer]);
        $mandanten = [];
        foreach ($v->fetchAllAssociative(
            'SELECT m.kennung, m.name, m.datenbank FROM verwaltung.zugang z JOIN verwaltung.mandant m ON m.kennung = z.mandant
              WHERE z.subjekt = ? AND m.stillgelegt_am IS NULL ORDER BY m.name', ['nutzer:' . $nutzer]) as $m) {
            $buch = false;
            try {
                $buch = $a->datenbank->verbindung($m['datenbank'])->fetchAssociative('SELECT betriebsart::text AS b, lesemodus FROM kern.buch');
            } catch (\Throwable) {
                // nicht erreichbar oder nicht eingerichtet – trotzdem anzeigen
            }
            $mandanten[] = ['kennung' => $m['kennung'], 'name' => $m['name'],
                            'betriebsart' => $buch ? $buch['b'] : null, 'lesemodus' => $buch ? (bool) $buch['lesemodus'] : null];
        }
        return ['subjekt' => 'nutzer:' . $n['id'], 'name' => $n['name'], 'sprache' => $n['sprache'],
                'sprachen' => Einstellungen::SPRACHEN, 'einstellungen' => Einstellungen::wirksam($v, $nutzer),
                'verwalter' => (bool) $n['verwalter'], 'mandanten' => $mandanten];
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
