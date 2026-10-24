# Public variables and actions

All variables are created below the module instance with stable idents. Status variables are read-only; writable controls use the module's `RequestAction` handler. Values such as `RoomTemperature` and `CurrentHeatingPower` are corrected/read snapshots of the selected inputs.

## Temperature and targets

| Ident | Type | Direction | Meaning |
|---|---|---|---|
| `RoomTemperature` | Float, °C | Output | Corrected primary temperature. |
| `SecondaryTemperature` | Float, °C | Output | Corrected secondary value; 0 is shown only as an internal placeholder when unavailable. Check `SecondarySensorAvailable`. |
| `TemperatureDifference` | Float, K | Output | Secondary minus primary temperature. |
| `SecondarySensorAvailable` | Boolean | Output | Secondary reading exists, is plausible, and is fresh. |
| `SensorDiscrepancy` | Boolean | Output | Absolute difference exceeds `MaximumSensorDifference`. The primary still controls. |
| `ScheduledTargetTemperature` | Float, °C | Output | Current schedule or configured fallback target. |
| `EffectiveTargetTemperature` | Float, °C | Output | Target currently used by the thermostat, including Boost/PV when active. |
| `ManualTargetTemperature` | Float, °C, action | Input | Desired temperature while `OperatingMode` is Manual. In the tile, enter it in the “Manual desired temperature” field and press “Apply”. Automatic mode uses the weekly schedule instead. |
| `PVTargetTemperature` | Float, °C, action | Input | Requested PV target ceiling, further limited by the PV lift and room limit. |
| `PVTargetCeiling` | Float, °C | Output | Current computed PV ceiling. |
| `ExpectedComfortTemperature` | Float, °C | Output | Cooling/residual-heat estimate at the upcoming comfort phase. |

## Operating and safety states

| Ident | Type | Direction | Meaning |
|---|---|---|---|
| `OperatingMode` | Integer, action | Input | 0 Automatic, 1 Manual, 2 Off. A change immediately recalculates. |
| `HeatingDemand` | Boolean | Output | Regular thermostat demand, including adaptive preheating. It excludes supplemental PV demand. |
| `HeatingActive` | Boolean | Output | Reported Boolean socket state. |
| `HeatingState` | Integer | Output | 0 Off, 1 socket on with little/no load, 2 electrical load above threshold, 3 switch feedback fault. |
| `BoostActive` | Boolean | Output | Temporary Boost currently active. |
| `BoostRemainingSeconds` | Integer, seconds | Output | Remaining Boost time. |
| `BoostCommand` | Integer, action | Input | 1 starts/restarts Boost; 0 stops it. Boost is unavailable in Off mode. |
| `FaultActive` | Boolean | Output | Sensor discrepancy/failure, maximum temperature, runtime, or switch command issue. |
| `SwitchFeedbackFault` | Boolean | Output | Requested state has not been confirmed within the feedback timeout. |
| `MaximumRuntimeExceeded` | Boolean | Output | Configured continuous ON time was exceeded. |
| `RuntimeLockoutActive` | Boolean | Output | The optional shutdown lockout currently prevents heating. |
| `ControllerStatus` | String | Output | Human-readable current decision/status. |
| `LastError` | String | Output | Most recent configuration, sensor, or command issue. |

## PV interface

| Ident | Type | Direction | Meaning |
|---|---|---|---|
| `PVHeatingPossible` | Boolean | Output | Thermal storage is useful within configured limits/horizon, independent of PV availability. |
| `PVHeatingEnable` | Boolean, switch action | Input | External energy-manager permission. False/expired permission only removes supplemental PV demand. |
| `PVPermissionFresh` | Boolean | Output | Current permission is not stale. A false permission is not an error. |
| `PVHeatingActive` | Boolean | Output | Supplemental PV heating request only; regular heating is reported separately. |

## Energy and learning

| Ident | Type | Direction | Meaning |
|---|---|---|---|
| `CurrentHeatingPower` | Float, W | Output | Fresh selected power reading; 0 when unavailable, with `PowerAvailable` indicating freshness. |
| `EnergyTotal` | Float, kWh | Output | Monotonic total initialized from the selected meter or from estimated energy. |
| `EnergyToday`, `EnergyYesterday` | Float, kWh | Output | Calendar-day use from persisted interval buckets. |
| `EnergyWeek`, `EnergyMonth` | Float, kWh | Output | Current local calendar week and month. |
| `RegularHeatingEnergy` | Float, kWh | Output | Tracked energy outside a PV supplemental phase. |
| `EstimatedPVHeatingEnergy` | Float, kWh | Output | Tracked energy during a PV request; not PV generation or savings. |
| `ElectricalHeatingDurationHours` | Float, h | Output | Time the socket was on and fresh measured power exceeded `PowerThreshold`. |
| `SocketOnDurationHours` | Float, h | Output | Time the reported socket state was on, capped across data gaps. |
| `EnergyReadingEstimated` | Boolean | Output | Current energy source is estimated from power. |
| `EnergySource` | String | Output | Cumulative meter, power estimate, or unavailable. |
| `EnergyMeterResetAt` | Integer timestamp | Output | Latest detected cumulative meter reset, or 0. |
| `EnergyLastGapSeconds` | Integer, s | Output | Most recent gap longer than the integration interval. |
| `EstimatedHeatingDuration` | Float, min | Output | Predicted time to the next target. |
| `EstimatedHeatingRate`, `EstimatedCoolingRate` | Float, K/h | Output | Learned rates. |
| `EstimatedResidualHeating` | Float, K | Output | Learned post-switch-off temperature rise. |
| `PredictionConfidence` | Float, % | Output | Sample-based model progress. |
| `NextComfortTime`, `PredictedHeatingStart` | Integer timestamp | Output | Next comfort transition and calculated early start. |
| `LearningStatus` | String | Output | Learning/preheating progress. |

## Schedule and safety actions

- `ScheduleCommand` (String action) is used by the tile's schedule editor. Commands are JSON strings generated by the editor; direct manual writes are not needed.
- `SafetyCommand` (Integer action): 1 requests a runtime-lockout reset. It is accepted only when the configured socket reports Off.
- Configuration-form actions call `SHC_Update`, `SHC_ResetLearning`, `SHC_StartBoost`, `SHC_StopBoost`, and `SHC_ResetSafetyLockout`.
- `SHC_RebaseEnergyToMeter` corrects the persisted energy ledger after a cumulative meter reset or repaired reading. It requires a recent meter reading and a recorded reset, subtracts the excess from the total and current day/week/month buckets, and preserves socket/heating durations.
- The tile's controls send actions for mode, manual/PV targets, PV permission, Boost, and schedule edits.

`ArchiveStatus` reports logging setup. Trend lines use SHC variables and a bounded cached archive query; they may be empty until new history is collected.
