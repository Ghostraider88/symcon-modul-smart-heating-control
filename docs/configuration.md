# Configuration reference

All hardware object IDs are selected in the instance form. SHC contains no fixed sensor, power, meter, or socket IDs. It cannot infer the unit of a numeric variable, so the selected source must use the unit in the table.

## Hardware

| Property | Default | Range / type | Effect |
|---|---:|---|---|
| `RoomTemperatureID` | none | Integer or Float variable | Required primary room temperature; controls the thermostat. |
| `SecondaryTemperatureID` | none | Integer or Float, optional | Used for temperature difference and discrepancy diagnosis. Does not replace the primary sensor. |
| `HeaterSwitchID` | none | Boolean with an action | Required reported socket state and switch command target. |
| `PowerVariableID` | none | Integer or Float in W | Required; distinguishes socket ON from actual electrical load and supports learning and energy estimation. |
| `EnergyVariableID` | none | Integer or Float in kWh, optional | Cumulative counter preferred for exact interval energy. A decreasing meter is treated as a reset. |
| `NominalHeatingPower` | 900 W | 1–10000 W | Reference power for cold-start learning and chart scale. |

## Sensors and thermostat

| Property | Default | Range | Effect |
|---|---:|---:|---|
| `TemperatureOffset` | 0 °C | −10 to +10 °C | Corrects the primary reading. |
| `SecondaryTemperatureOffset` | 0 °C | −10 to +10 °C | Corrects the secondary reading. |
| `TargetTemperature` | 24 °C | 5–35 °C | Automatic fallback target when the weekly schedule is disabled; schedule comparison reference. |
| `Hysteresis` | 0.3 K | 0.1–3.0 K | Per-side thermostat switching band. |
| `MaximumRoomTemperature` | 28 °C | Above target, at most 40 °C | Absolute upper temperature limit for Automatic, Manual, Boost, preheating, and PV. Heating is forced off at or above this value. |
| `SensorTimeoutSeconds` | 900 s | 30–86400 s | Primary or secondary values older than this are stale. A stale primary stops heating; a stale secondary disables discrepancy analysis. |
| `MaximumSensorDifference` | 5 K | 0.5–20 K | Larger absolute primary/secondary difference sets a diagnostic fault. The primary remains authoritative. |
| `MinimumOnSeconds` | 300 s | 0–3600 s | Minimum normal ON period. Safety shutdown overrides it. |
| `MinimumOffSeconds` | 180 s | 0–3600 s | Minimum normal OFF period. Safety shutdown/activation policy takes precedence where necessary. |
| `ControlIntervalSeconds` | 30 s | 5–300 s | Periodic recovery check; observed variable changes and module actions also trigger an immediate cycle. |

The schedule defaults to 20 °C setback and 24 °C comfort periods. It uses the IP-Symcon server's local time zone, including daylight-saving transitions. Its seven-day event list is persisted as an instance attribute.

## Learning and Boost

| Property | Default | Range | Effect |
|---|---:|---:|---|
| `AdaptivePreheatingEnabled` | true | Boolean | Enables scheduled preheating from the learned estimate. |
| `MaximumPreheatMinutes` | 180 min | 0–360 min | Maximum early-start lead. |
| `PreheatSafetyBufferMinutes` | 15 min | 0–120 min | Added lead to compensate for estimation error. |
| `MinimumHeatingSamples` | 3 | 1–100 | Samples required before learned heating rates replace the initial estimate. |
| `InitialHeatingRateKPerHour` | 0.3 K/h | 0.05–3.0 K/h | Conservative fallback until sufficient measurements exist. |
| `BoostTemperature` | 25 °C | 5–35 °C and ≤ maximum room temperature | Temporary Boost target. |
| `BoostDurationMinutes` | 45 min | 1–240 min | Duration from Boost start; expiry is persisted across restarts. |

Thermal learning ignores stale/invalid primary readings and uses power only while it is fresh and above `PowerThreshold`. Learned state is persistent and can be cleared with **Reset thermal learning**.

## Power, energy, and archive

| Property | Default | Range | Effect |
|---|---:|---:|---|
| `PowerThreshold` | 10 W | 0–1000 W | Load above this value counts as electrically active. A socket ON below the threshold is a separate state, not automatically a fault. |
| `PowerTimeoutSeconds` | 600 s | 30–86400 s | Freshness limit for power; long gaps are not integrated as estimated energy. |
| `ArchiveLoggingEnabled` | true | Boolean | Enables Archive Control logging for SHC's own temperature, target, state, power, energy, prediction, PV, and fault variables. External source variables are not enabled. |

Energy period totals are maintained in a bounded persistent ledger. Cumulative meter differences are preferred; without a meter, SHC uses the average of two fresh power samples over an interval no longer than `PowerTimeoutSeconds`. Meter resets are detected, long outages are flagged in `EnergyLastGapSeconds`, and energy after a gap longer than one day cannot be distributed accurately between calendar periods. The total remains monotonic.

## PV optimization

| Property | Default | Range | Effect |
|---|---:|---:|---|
| `MaximumPVTemperatureOffset` | 1.0 K | 0–5 K | Maximum PV lift above the next comfort target within the planning horizon, otherwise above the current regular target. |
| `PVPlanningHorizonHours` | 24 h | 1–168 h | Future comfort demand must be within this horizon for scheduled PV potential. |
| `PVPermissionTimeoutSeconds` | 0 s | 0–86400 s | Maximum age of a true permission. Zero disables expiry. The energy manager may refresh permission through the action. |

The effective PV ceiling is the minimum of the editable `PVTargetTemperature`, comfort reference plus allowed lift, and `MaximumRoomTemperature`. A future comfort target and learned cooling/residual heat determine whether additional stored warmth is useful. `PVHeatingPossible` is independent of permission; `PVHeatingActive` indicates only a supplemental PV request. Neither variable measures solar generation or grid savings.

## Runtime safety

| Property | Default | Range | Effect |
|---|---:|---:|---|
| `MaximumContinuousOnMinutes` | 0 min | 0–1440 min | Continuous socket-ON monitoring limit; zero disables it. |
| `StopAfterMaximumContinuousOnTime` | false | Boolean | If enabled, exceeding the limit switches off and latches a runtime lockout. Otherwise it raises a diagnostic without interrupting heat. |

Reset a latched lockout only after the socket reports Off. SHC cannot ensure shutdown during an IP-Symcon or socket communication outage; the heater's hardware limiter remains necessary.
