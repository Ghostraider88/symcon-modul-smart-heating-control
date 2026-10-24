# Automated tests

This directory contains the Symcon validation tests plus deterministic unit tests for schedule semantics, thermostat priorities, thermal learning, PV decisions, sensor/switch safety, and energy accounting.

The `stubs` submodule provides `IPSModuleStrict`, kernel stubs, and library/module validators. Initialize it after cloning:

```text
git submodule update --init --recursive
```

Run the suite with PHP 8.1+ and Composer:

```text
composer install
composer test
composer run style:check
```

`tests/phpunit.xml` is shared with CI and loads `tests/stubs` through `.tools/phpunit-bootstrap.php`. The PHP runtime and development dependencies are not required by an installed IP-Symcon module. See [test plan and hardware checks](../docs/testing.md).
