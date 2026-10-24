# Technical architecture

## Components

- `SmartHeatingControl/module.php` owns the IP-Symcon lifecycle, property/reference registration, action handling, central control cycle, logging setup, and tile rendering.
- `WeeklySchedule` validates and evaluates the persisted local-time seven-day schedule and its editor commands.
- `TemperatureController` contains the stateless hysteresis and final switch-priority checks.
- `ThermalModel` incrementally learns heating/cooling rates and residual heat from fresh observations; it persists a bounded model rather than rescanning all history.
- `PVOptimizer` calculates useful thermal room, future comfort need, permission freshness, and the additional target ceiling.
- `EnergyTracker` stores monotonic energy totals and bounded day/week/month buckets, distinguishes meter and power-estimated energy, and tracks socket/electrical durations.
- `SafetyController` provides the reusable sensor, discrepancy, continuous-runtime, and switch-feedback checks.

## Central control order

Every observed input update, module action, configuration change, timer tick, and boost expiry requests a control cycle. A buffer prevents reentrant execution. The control cycle then:

1. Validates configuration, primary sensor age/plausibility, selected secondary sensor, and the reported socket state.
2. Updates energy accounting and thermal learning from the preceding observation interval.
3. Evaluates the local-time schedule, manual target, or active Boost target.
4. Applies hysteresis and predictive residual-heat cutoff to regular demand.
5. Independently evaluates PV potential and an optional fresh external permission. PV cannot override Off or suppress regular demand.
6. Applies the maximum room temperature and optional runtime lockout, then normal minimum switching times.
7. Sends at most the needed socket action, compares the returned state, and updates state/fault/status variables.

Safety stops for invalid primary temperature, the absolute maximum temperature, Off, and a latched runtime limit override normal minimum ON time. A switch command is checked against the Boolean state; a persistent mismatch becomes a feedback fault.

## Persistence and measurement quality

Symcon properties hold user configuration. Instance attributes persist schedule events, the thermal model, Boost expiry, PV permission update time, switch feedback/runtime state, energy buckets, and chart cache. Timers are restored from persisted expiries on `ApplyChanges`.

The cumulative kWh value is preferred. If it is unavailable or stale during active power draw, SHC can integrate fresh power samples up to the configured maximum gap. A cumulative meter reset does not reduce the module total. A power estimate never bridges a long gap. Period totals are split across local midnight/week/month boundaries; if an observed meter delta spans more than a day, its calendar distribution is unknown and the delta is assigned to the latest period. `EnergyLastGapSeconds` makes this visible.

`EstimatedPVHeatingEnergy` is classified by SHC's prior `PVHeatingActive` state. It does not prove that energy came from PV, nor does it measure self-consumption or avoided grid energy.

## Archive and visualization

The module optionally enables logging only for its own history variables in Archive Control. Temperature/power trends are queried only when the tile is rendered and cached for five minutes. Each query is limited to the previous 24 hours and 288 five-minute aggregates. Missing archive data leaves the controller operating and produces an empty-history message.

The weekly chart uses SVG: a seven-row daily heatmap plus a detailed selected-day step curve. The historical chart plots primary/secondary temperature, effective target, power, and PV heating intervals. The tile has no separate server or cloud dependency.

## Limits

IP-Symcon cannot guarantee a physical shutdown while its service or socket communications are down. SHC therefore requires the heater's independent hardware temperature limiter. The secondary sensor is currently diagnostic; it does not take over control when the primary sensor fails. A disconnected or incorrectly unit-configured input can only be corrected at the installation.
