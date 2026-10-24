# Smart Heating Control

Smart Heating Control (SHC) is a reusable IP-Symcon module for electrically powered room heaters. It controls a heater through user-selected IP-Symcon variables and combines a weekly temperature program, adaptive preheating, learned residual heat, PV surplus input, energy accounting, safety monitoring, and an HTML tile.

The module does not depend on a socket brand, communication protocol, heater model, or heater volume. It uses a Boolean socket action, a power value in watts, an optional cumulative energy value in kilowatt-hours, and primary plus optional secondary temperature variables.

## Features

- Automatic, Manual, Off, and time-limited Boost operation.
- Editable weekly temperature schedule with day and week charts.
- Hysteresis, minimum ON/OFF times, maximum room temperature, stale sensor handling, switch feedback, and configurable continuous-runtime monitoring.
- Persistent thermal learning for heating rate, cooling rate, and residual heat; adaptive preheating falls back to a configurable initial estimate.
- External `PVHeatingEnable` interface with thermal-potential checks, temperature lift limit, comfort horizon, and optional stale-permission timeout.
- Cumulative-meter energy accounting or bounded power integration, calendar-period buckets, PV-phase attribution, and socket/electrical run-time totals.
- Archive Control logging for SHC trend variables and a cached 24-hour temperature/power chart.
- German translations and diagnostic output variables.

## Requirements

- IP-Symcon 9.0 or newer
- PHP 8.1 or newer
- A Boolean socket-state variable with an action
- A numeric power variable in W
- A numeric primary temperature variable and, optionally, a secondary temperature variable
- An Archive Control instance for logged trends and the historical chart; the controller itself remains usable without archive history

## Install and configure

1. Add `https://github.com/Ghostraider88/symcon-modul-smart-heating-control` in IP-Symcon Module Control.
2. Create a **Smart Heating Control** instance.
3. Select the existing primary and optional secondary temperature variables, the Boolean socket action, and power in W. Select the optional cumulative energy variable in kWh when available.
4. Review the target, schedule, sensor timeouts, temperature ceiling, and PV settings.
5. Keep the operating mode **Off** until the socket is connected to the heater and the input values and action have been checked.

SHC verifies variable types and action availability. It cannot infer a numeric variable's engineering unit; the selected values must match the units shown in the form. See [installation](SETUP.md), [configuration](docs/configuration.md), [module variables and actions](docs/interface.md), and [architecture](docs/architecture.md).

## Testing

Pure controller, schedule, thermal model, PV, safety, and energy-tracker tests are under `tests/`. Run `composer install`, `composer test`, and `composer run style:check` with PHP 8.1 and Composer. Test coverage and remaining hardware checks are described in [test documentation](docs/testing.md).

Static tests and Symcon kernel checks do not substitute for verifying the actual socket, heater, temperature placement, or power meter. The heater must retain its own hardware temperature protection.
