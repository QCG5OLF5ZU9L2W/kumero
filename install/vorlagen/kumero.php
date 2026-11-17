<?php
// kumero – Anwendungskonfiguration (ANWENDUNG.md Abschnitt 8, E6)
// Vom Installer einmal angelegt, danach vom Betreiber gepflegt. Keine
// Geheimnisse: Die Datenbank wird per Peer-Authentisierung erreicht.
return [
    // Origin, unter dem kumero im Browser aufgerufen wird. Passkeys sind an
    // diesen Hostnamen gebunden (RP-ID); ändert er sich, müssen alle Passkeys
    // neu registriert werden. https ist Pflicht, außer http://localhost (Tunnel).
    'adresse'       => '@ADRESSE@',
    'sprache'       => 'de',
    'verwaltung_db' => 'kumero_verwaltung',
    'db_host'       => '/var/run/postgresql',
    'sitzung'       => ['leerlauf_min' => 30, 'hoechstens_std' => 12],
    // 'debug' schreibt Fehlermeldungen vollständig ins Server-Log – nur zur Fehlersuche
    'protokoll'     => ['stufe' => 'info'],
];
