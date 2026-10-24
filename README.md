# IP-Symcon Module Library Template

[![Check Style](https://github.com/DEIN-USER/DEIN-REPO/actions/workflows/style.yml/badge.svg)](https://github.com/DEIN-USER/DEIN-REPO/actions/workflows/style.yml)
[![Run Tests](https://github.com/DEIN-USER/DEIN-REPO/actions/workflows/tests.yml/badge.svg)](https://github.com/DEIN-USER/DEIN-REPO/actions/workflows/tests.yml)

Vorlage zum Entwickeln und Testen eigener Custom-Module für IP-Symcon.

## Enthaltene Module

| Modul | Beschreibung | Doku |
|-------|--------------|------|
| MyModule | Demonstriert Variablen, Konfigurationsprüfung und eine HTML-Kachel ohne Geräteverbindung. | [README](MyModule/README.md) |

## Installation

Über das Module Control (Kerninstanz) die Repository-URL hinzufügen:

https://github.com/DEIN-USER/DEIN-REPO

## Voraussetzungen

* IP-Symcon ab Version 8.1

## Entwicklung

PHP 8.1 oder neuer und Composer werden für die lokalen Prüfungen benötigt:

```bash
git submodule update --init --recursive
composer install
composer test
composer run style:check
```

Neue Module verwenden den verbindlichen Visu-Stil aus
[docs/VISU_STYLE.md](docs/VISU_STYLE.md) und den wiederverwendbaren Baukasten
[libs/VisuStyle.php](libs/VisuStyle.php). Für Zustände, Diagnose und
fähigkeitsabhängige Bedienfelder stehen zusätzlich folgende Bausteine bereit:

- [libs/VisuState.php](libs/VisuState.php)
- [libs/VisuDiagnostic.php](libs/VisuDiagnostic.php)
- [libs/VisuCapability.php](libs/VisuCapability.php)

Das Beispielmodul enthält eine Kachel mit Status, Aktion, Selbsttest,
Capability-Prüfung und Live-Aktualisierung.

Alle verbindlichen Struktur- und Codiervorgaben für die Modulentwicklung stehen in
[AGENTS.md](AGENTS.md). Diese Datei dient zugleich als Kontext für Codex.

Für die API-Prüfung bevorzugt die offizielle [Markdown-Dokumentation](https://www.symcon.de/de/llms.txt)
verwenden: zuerst den [Funktionsindex](https://www.symcon.de/de/llms/function-index.md),
danach die benötigten Detaildateien. Für eigene Module sind die
[SDK-Modulmethoden](https://www.symcon.de/de/llms/developer/sdk-tools/sdk-php/module.md)
und [Konfigurationsformulare](https://www.symcon.de/de/llms/developer/sdk-tools/sdk-php/configuration-forms.md)
besonders relevant. Abrufweg, Versionsprüfung und Quellenregeln stehen in [AGENTS.md](AGENTS.md).

Optionale Ausbauideen für das Template (kein verbindliches Regelwerk) sammelt
[docs/template-backlog.md](docs/template-backlog.md).

## Lizenz

Die Vorlage wird unter der [MIT-Lizenz](LICENSE) veröffentlicht.
