# SETUP – Vorlage für ein neues Modul verwenden

Kurze Checkliste, wenn du dieses Template als Basis für ein neues Symcon-Modul nimmst.
Die ausführlichen Regeln stehen in [`AGENTS.md`](AGENTS.md).

## 1. Repository anlegen
- [ ] Dieses Template als GitHub-Template-Repo markieren (Settings → "Template repository")
      oder Inhalt in ein neues Repo kopieren.
- [ ] `DEIN-USER/DEIN-REPO` in `README.md`, `library.json`, `MyModule/module.json`
      und `MyModule/README.md` ersetzen.
- [ ] Bibliotheksname, Hersteller, Autor, Beschreibung und Lizenz prüfen.

## 2. GUIDs ersetzen (wichtig!)
- [ ] Neue GUID für `library.json` → `id`
- [ ] Neue GUID für jedes Modul in `*/module.json` → `id`
- Generator: https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/tools/guid-generator
- [ ] GUIDs in `library.json` und jedem `module.json` auf Eindeutigkeit prüfen.

## 3. Modul benennen
- [ ] Ordner `MyModule/` umbenennen → exakter Klassenname (keine Leerzeichen).
- [ ] In `module.json`: `name` (mit ggf. Leerzeichen), `type`, `prefix`, `vendor` setzen.
- [ ] In `module.php`, `form.json` und Tests: Klassennamen und alle Vorkommen des Prefix `MYM_` anpassen.
- [ ] Modulordner umbenennen und alle fest eingetragenen Testpfade aktualisieren.

## 4. Konfiguration & Logik
- [ ] Offizielle Markdown-Dokumentation über https://www.symcon.de/de/llms.txt aufrufen;
      Funktionsindex und benötigte SDK-/Formular-Detaildateien gemäß `AGENTS.md` prüfen.
- [ ] Versionsvoraussetzungen verwendeter Funktionen, Konstanten und Presentations
      mit der geplanten Mindestversion in `library.json` abgleichen.
- [ ] `form.json`: Properties als `elements`, Test-Buttons als `actions`, `status`-Codes.
- [ ] `locale.json`: deutsche Übersetzungen.
- [ ] `module.php`: Properties/Variablen/Timer in `Create()`/`ApplyChanges()`, Logik in
      eigenen Funktionen bzw. `RequestAction`.

## 5. Tests & Style
- [ ] Submodule initialisieren: `git submodule update --init --recursive`
- [ ] Lokale Werkzeuge installieren: `composer install`
- [ ] Lokale Prüfungen ausführen: `composer test` und `composer run style:check`
- [ ] Bei Änderungen an Datenfluss, Aktionen oder Variable Presentations passende
      Tests nach dem Muster von `SymconTest` ergänzen.

## 6. In IP-Symcon einbinden
- [ ] Module Control öffnen → Repository-URL hinzufügen.
- [ ] Lokale Entwicklung: nach Code-Änderung IP-Symcon-Dienst kurz neu starten.

## 7. Dokumentation
- [ ] `MyModule/README.md` ausfüllen (Funktionsumfang, Voraussetzungen, Variablen, PHP-Befehle).
- [ ] Wurzel-`README.md`: Modul in die Tabelle eintragen.
- [ ] `CHANGELOG.md`, Bibliotheksversion, Buildnummer und Datum abstimmen.
- [ ] `docs/module-store-versionsinfo.txt` aktualisieren; maximal 3000 Zeichen.
- [ ] Keine internen Projektunterlagen, lokalen Pfade, Zugangsdaten oder fremden Quelldateien einchecken.
