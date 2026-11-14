# SPDX-License-Identifier: AGPL-3.0-or-later
# Schritt 70: Signierdienst / Sicherheitsmodul (F15, F22, F23, F56)
#
# Vorbereitet wird nur die Umgebung – der Dienst selbst ist noch nicht gebaut.
#   - eigener Systembenutzer kumero-ssm, getrennt von der Anwendung
#   - GnuPG-Verzeichnis /var/lib/kumero-ssm/gnupg (0700)
#   - Socket-Verzeichnis /run/kumero-ssm, Zugriff nur für Gruppe kumero-ssm-client
#   - systemd-Unit mit strenger Härtung, zunächst NICHT aktiviert
#
# Der Stammschlüssel (F23) wird NIE auf diesem Server erzeugt oder abgelegt.

pakete gnupg gpg-agent

systemuser kumero-ssm /var/lib/kumero-ssm "kumero Signierdienst"
getent group kumero-ssm-client >/dev/null || addgroup --system kumero-ssm-client
# Nur die Anwendung darf mit dem Signierdienst sprechen.
adduser -q kumero kumero-ssm-client >/dev/null

install -d -m 0700 -o kumero-ssm -g kumero-ssm /var/lib/kumero-ssm
install -d -m 0700 -o kumero-ssm -g kumero-ssm /var/lib/kumero-ssm/gnupg
install -d -m 0750 -o root      -g kumero-ssm /etc/kumero-ssm
install -d -m 0755 -o root      -g root      /usr/lib/kumero-ssm

datei_schreiben /etc/systemd/system/kumero-ssm.service 0644 root:root < <(vorlage kumero-ssm.service) \
    && systemctl daemon-reload || true

# systemd-creds (F23, optional TPM-Bindung der laufenden Kopie) ist Teil von
# systemd in Debian 13. Nur Hinweis, keine Aktion.
if command -v systemd-creds >/dev/null && systemd-creds has-tpm2 >/dev/null 2>&1; then
    melde "TPM2 vorhanden: Passphrase des Signierschlüssels kann mit systemd-creds an diesen Server gebunden werden."
else
    melde "Kein TPM2 gefunden: systemd-creds nutzt den hostgebundenen Schlüssel (/var/lib/systemd/credential.secret)."
fi

if [[ ! -x /usr/lib/kumero-ssm/kumero-ssm ]]; then
    melde "Signierdienst noch nicht ausgeliefert – Unit kumero-ssm.service bleibt deaktiviert."
fi
