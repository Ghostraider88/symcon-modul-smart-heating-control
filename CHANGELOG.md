# Changelog

## 1.0.0

- Completed manufacturer-independent temperature/socket bindings and the central two-point controller with immediate action handling, primary/secondary sensor diagnostics, safe temperature ceiling, switch feedback, and optional continuous-runtime lockout.
- Added Automatic, Manual, Off, persisted Boost, a seven-day temperature schedule with day/week SVG views, adaptive preheating, thermal learning, and residual-heat cutoff.
- Added PV thermal-potential evaluation, comfort-horizon planning, a configurable temperature lift, and an optional stale-permission timeout.
- Added cumulative-meter/power-estimated energy tracking, calendar period buckets, regular/PV-phase attribution, socket/electrical durations, meter reset and gap diagnostics.
- Added Archive Control logging for SHC-owned history variables, a bounded cached historical temperature/power chart, and expanded diagnostics.
- Added reusable deterministic logic classes and PHPUnit coverage for controller, schedule, model, PV, safety, and energy cases.
- Completed German translations, configuration/interface/architecture/testing documentation, and first-setup guidance.
