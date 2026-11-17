# Referenz-Kontenrahmen (F75 Nr. 3)

`referenz-skr81.csv` ist ein **eigener, synthetischer** Kontenplan im Aufbau des SKR 81:
allgemeine Kontenbezeichnungen, keine DATEV-Beschriftungen. Er dient als Testgrundlage und
als Startpunkt ohne Fremddaten:

```bash
php /var/www/kumero/bin/kumero kontenrahmen:einlesen /var/www/kumero/module/kontenrahmen/referenz/referenz-skr81.csv --name "Referenz SKR 81"
```

Die Zuordnung zur Anlage EÜR folgt mit Modulversion 0.2.0 (kumero 0.7.0).
