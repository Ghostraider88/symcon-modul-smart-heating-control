# MyModule

[![Symcon Version](https://img.shields.io/badge/Symcon%20Version-8.1%20%3E-green.svg)](https://www.symcon.de)

Dieses Beispiel zeigt Statusvariablen, Konfigurationsprüfung und die HTML-Visualisierung.
Es ist eine Vorlage und kommuniziert nicht mit einem Gerät.

### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Software-Installation](#3-software-installation)
4. [Einrichten der Instanzen in IP-Symcon](#4-einrichten-der-instanzen-in-ip-symcon)
5. [Statusvariablen und Presentations](#5-statusvariablen-und-presentations)
6. [PHP-Befehlsreferenz](#6-php-befehlsreferenz)
7. [Fehlersuche](#7-fehlersuche)

### 1. Funktionsumfang

* Prüft die Beispielkonfiguration auf einen gültigen Hostnamen und ein gültiges Intervall.
* Demonstriert Statusvariablen, eine Schaltaktion und eine HTML-Kachel.
* Sendet keine Gerätebefehle und fragt kein Gerät ab.

### 2. Voraussetzungen

* IP-Symcon ab Version 8.1
* Ein DNS-Name oder eine IP-Adresse für die Beispiel-Konfigurationsprüfung

### 3. Software-Installation

* Über das Module Control folgende URL hinzufügen:
  `https://github.com/DEIN-USER/DEIN-REPO`

### 4. Einrichten der Instanzen in IP-Symcon

* Unter "Instanz hinzufügen" ist 'MyModule' unter dem Hersteller '(Sonstige)' aufgeführt.

__Konfigurationsseite:__

| Name     | Beschreibung                         |
|----------|--------------------------------------|
| Hostname | DNS-Name oder IP für die Beispiel-Konfigurationsprüfung |
| Interval | Aktualisierungsintervall in Sekunden |

### 5. Statusvariablen und Presentations

`Switch` verwendet die moderne Variable Presentation `VARIABLE_PRESENTATION_SWITCH`.

Die Statusvariablen werden automatisch angelegt. Das Beispiel erzeugt keine Gerätebefehle.

| Name   | Typ     | Beschreibung                |
|--------|---------|-----------------------------|
| Status | String  | Zeitpunkt des letzten Demo-Aufrufs |
| Switch | Boolean | Schaltbarer Beispielzustand |

### 6. PHP-Befehlsreferenz

`MYM_Update(int $InstanzID): void;`
Führt eine sofortige Aktualisierung aus.

`MYM_HelloWorld(int $InstanzID): string;`
Gibt einen Beispieltext zurück.

### 7. Fehlersuche

Konkrete Symptom → Ursache → Lösung:

| Symptom | Mögliche Ursache | Lösung |
|---------|------------------|--------|
| Instanz bleibt auf "Nicht konfiguriert" | Pflichtfeld (z.B. Hostname) leer | Wert in der Konfiguration setzen und übernehmen |
| Status 202 (ungültige Konfiguration) | Hostname oder Intervall ist ungültig | Konfiguration prüfen und erneut übernehmen |
| Keine Demo-Aktualisierung | Timer/Intervall auf 0 | Aktualisierungsintervall setzen |

Die Beispiel-Diagnosen werden in der Kachel angezeigt. Das Modul protokolliert keine
Gerätekommunikation, da es keine Geräteabfragen ausführt.
