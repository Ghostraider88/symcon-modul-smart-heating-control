# Smart Heating Control module

The `SmartHeatingControl` IP-Symcon device module controls an electric heater through configurable variables. It has no fixed object IDs and no dependency on a socket brand, protocol, heater model, or heater volume.

## Controller behavior

The central cycle validates the primary sensor first. A missing, implausible, or stale primary value turns the socket off. The automatic or manual thermostat applies a per-side hysteresis; an absolute maximum temperature and an enabled runtime lockout override all requests. Normal minimum ON/OFF times apply only where a safety stop does not need to take precedence. Socket commands use `RequestAction` and are checked against the reported Boolean state.

Automatic mode follows a persisted seven-day schedule. The tile renders a week overview and a step curve for the selected day. The schedule can be edited in 15-minute and 0.5 K increments, copied between days, or reset to its default. Manual mode uses `ManualTargetTemperature`; Off disables normal heat, boost, adaptive preheating, and PV requests.

Boost temporarily raises the target for the configured duration. It leaves the selected main mode and its settings intact; when stopped or expired, that mode resumes. Boost is refused in Off mode and remains under the absolute room-temperature ceiling.

The persistent thermal model observes valid temperature, socket, and fresh power readings. It learns heating and cooling rates and residual heat. Adaptive preheating uses the next comfort phase and learned estimate; before enough samples exist, it uses the configurable initial rate. Missing learning data never blocks the ordinary thermostat.

PV heating is an additional optimization. `PVHeatingPossible` reflects available thermal room and expected comfort need within the configured horizon. An energy manager writes `PVHeatingEnable`. The controller caps the PV target by the selected target, configured maximum temperature lift, and absolute room limit. A stale true permission can expire. Regular heating remains independent of this interface.

## Energy and archive

SHC prefers a selected cumulative kWh meter. If no cumulative meter is configured, it estimates energy by integrating fresh power samples only across bounded intervals. If a configured meter becomes stale while the heater is drawing power, fresh power can temporarily provide an estimate; the tracker rebases to the next meter reading to avoid counting that interval twice. Counter resets do not reduce the module's lifetime total.

Day, previous-day, week, month, regular-phase, and PV-phase values are persisted. Meter deltas crossing a local calendar boundary are split by elapsed time; a gap longer than one day is assigned to the current period because its internal distribution is unknown. `EstimatedPVHeatingEnergy` is energy attributed to periods when SHC's additional PV request was active; it is not a measurement of solar self-consumption or grid savings.

When Archive Control is present and logging is enabled, SHC logs its own historical variables. A cached five-minute aggregate query supplies the tile's 24-hour chart. Source sensor and socket variables are not automatically re-archived.

## Diagnostics and public interface

`HeatingState` distinguishes Off, socket ON with little/no load, electrical heating, and a switch feedback fault. `FaultActive` combines primary sensor, sensor discrepancy, maximum temperature, runtime, and switching issues. A secondary sensor discrepancy is diagnostic; the primary sensor remains the control source.

See the repository's [configuration reference](../docs/configuration.md), [variable/action reference](../docs/interface.md), and [technical architecture](../docs/architecture.md).
