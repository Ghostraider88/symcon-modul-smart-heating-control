# Tests

Das Template nutzt die offiziellen Hilfs-Repositories von Symcon als **Git-Submodule**.
Sie sind im Template bereits registriert; nach dem Klonen müssen sie nur initialisiert
werden:

```bash
git submodule update --init --recursive
```

`tests/stubs` stellt unter anderem `IPSModuleStrict`, die Kernel-/I/O-Stubs und den
offiziellen `validateLibrary()`-/`validateModule()`-Validator bereit. Die Beispiele in
[SymconTest](https://github.com/symcon/SymconTest) dient als Referenz für weitergehende Lebenszyklus-, Datenfluss-, Aktions-
und Presentationstests; das Repository selbst ist keine Laufzeitabhängigkeit des Moduls.

PHP 8.1 oder neuer und Composer werden benötigt. Lokal ausführen:

```bash
composer install
composer test
composer run style:check
```


Die Konfiguration `tests/phpunit.xml` wird lokal und in CI gemeinsam verwendet. Sie lädt die Symcon-Stubs über `.tools/phpunit-bootstrap.php` und schließt Tests aus dem Stub-Untermodul aus.

Der Style-Workflow prüft alle JSON-Dateien mit `.tools/json-check.php`. Der Checker liest
Dateien ausschließlich. `composer run style:fix` formatiert PHP-Dateien erst, nachdem
alle JSON-Dateien validiert sind und die Formatprüfung bestanden haben.

Die GitHub-Actions-Workflows (`.github/workflows/tests.yml`, `style.yml`) führen dieselben
Composer-Prüfungen bei jedem Push und Pull Request aus. Das Untermodul `tests/stubs` ist im
Git-Repository auf einen festen Commit gesetzt.
