# Installation and first setup

## Install the library

1. In IP-Symcon Module Control, add `https://github.com/Ghostraider88/symcon-modul-smart-heating-control`.
2. Create a **Smart Heating Control** instance in the object tree.
3. Place it in the desired category. The category is organizational only and does not change how the module reads or switches hardware variables.

## Select hardware variables

| Form field | Type | Expected unit | Required |
|---|---|---|---|
| Primary room temperature | Integer or Float | °C | Yes |
| Secondary room temperature | Integer or Float | °C | No |
| Heater socket ON/OFF variable | Boolean with an action | — | Yes |
| Power variable | Integer or Float | W | Yes |
| Cumulative energy variable | Integer or Float | kWh | No |

The socket state must be the variable whose action actually controls the outlet. Select a power and energy value belonging to the same socket or a compatible meter. SHC does not create hardware instances and does not rely on a specific brand or protocol.

Configure sensor offsets only to correct a measured installation bias. The secondary sensor is used for temperature distribution and discrepancy diagnostics; the selected primary sensor remains the control sensor. If the sensors differ by more than the configured threshold, SHC flags the discrepancy and reports that the primary sensor still controls heating.

## Safe commissioning

1. Leave **Operating mode** at **Off** while wiring the configuration.
2. Confirm that both temperatures are plausible and update within the configured sensor timeout.
3. Confirm that the power value is in watts. A stale power value is marked unavailable; temperature control remains active, while the energy tracker uses a valid cumulative counter or a bounded power estimate where appropriate.
4. Confirm the Boolean variable and its action with the socket before connecting a heater.
5. Set the absolute maximum room temperature, target, and schedule for the room.
6. Connect the heater only after the outlet and heater are ready for automatic operation; then select Automatic or Manual.

Keep the heater's independent hardware temperature limiter in service. IP-Symcon cannot guarantee switching when its service or the socket communication is unavailable.

## Main defaults

- Automatic target: 24 °C; weekly schedule enabled with 20 °C setback and 24 °C comfort periods.
- Hysteresis: 0.3 K per side.
- Maximum room temperature: 28 °C.
- Nominal heater power: 900 W.
- Sensor timeout: 900 s; power timeout: 600 s.
- Adaptive preheating: enabled; initial heating estimate: 0.3 K/h; maximum lead: 180 min; safety buffer: 15 min.
- Boost: 25 °C for 45 min.
- Maximum PV temperature lift: 1 K above the next comfort target, capped by the configured PV target and absolute room limit.
- PV permission timeout: disabled (0 s); maximum continuous socket ON time: disabled (0 min).
- SHC trend logging in Archive Control: enabled.

Every setting, default, range, and behavioral effect is listed in [configuration reference](docs/configuration.md).

## Connect an energy manager

Use the module's writable `PVHeatingEnable` Boolean through its normal IP-Symcon action. `PVHeatingPossible` reports thermal room for useful extra heat; it does not assert that PV energy is available. `PVHeatingActive` reports only the additional PV heating request. A false or expired permission never blocks regular comfort heating. See the [interface reference](docs/interface.md).

## Archive and history

With Archive Control available and **Archive SHC history variables** enabled, SHC logs its own temperature, target, switch, power, energy, PV, prediction, and fault variables. It does not enable logging on the selected external hardware variables. The tile queries a bounded 24-hour, five-minute aggregate and caches it for five minutes. Historical lines appear after enough data has been archived.

## Reset or inspect

- Use **Reset thermal learning** to clear heating, cooling, and residual-heat observations.
- The safety lockout reset is only accepted after the socket reports Off.
- `ControllerStatus`, `LastError`, `FaultActive`, `HeatingState`, `ArchiveStatus`, and the energy source/measurement diagnostics explain the current state.

Automated and kernel checks are described in [test documentation](docs/testing.md). Hardware commissioning still has to confirm the selected variables, actual switching, meter units, and heater behavior.
