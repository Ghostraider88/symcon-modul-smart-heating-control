# Tests and commissioning

## Automated tests

The PHPUnit suite uses the repository's Symcon stubs for module/library validation and instance-variable checks. Pure deterministic tests cover:

- hysteresis, priority of Off/sensor/temperature/runtime safety, and independent PV demand;
- weekly transitions, editing, week rollover, and daylight-saving wall-clock behavior;
- heating/cooling learning, cold-start estimates, residual heat, and implausible jumps;
- PV comfort horizon, temperature lift, stale permission, and the Off mode;
- sensor freshness/discrepancy, maximum continuous runtime, and switch-feedback timeout;
- cumulative meter increments/resets, power integration limits, period boundary allocation, and PV-energy classification.

Run with PHP 8.1+ and Composer:

```text
composer install
composer test
composer run style:check
```

The Symcon MCP `module_write` and `module_lint` checks compile PHP files in the installed library; `module_validate` checks the manifests and `module_reload` checks kernel registration. These compile/registration checks do not run PHPUnit tests.

## Safe IP-Symcon integration checks

1. Create the instance while unconfigured and confirm it remains inactive without requesting heat.
2. Configure the selected variables and keep the mode Off. Confirm fresh primary/secondary readings, difference, power freshness, meter source, archive status, and tile rendering.
3. In Off mode, verify the socket is requested Off and remains Off; verify PV permission cannot re-enable it.
4. In an isolated test setup with no heater connected, test Manual target actions, Automatic mode, schedule edits, Boost start/expiry/cancel, and the safety reset guard. The attached room sensors and socket must be real or mocked so no heat request reaches a heater.
5. Check after a Symcon restart that the schedule, thermal model, active Boost expiry, energy tracker, and runtime lockout are restored consistently.
6. Review Archive Control logging and wait for enough samples before checking the 24-hour trend chart.

## Hardware tests still required

Automated tests and the kernel can validate software behavior but cannot prove the electrical installation. Before normal heating operation, verify with the actual heater connected:

- the selected Boolean variable controls the intended outlet and its reported state follows the command;
- the power measurement is in watts and the cumulative counter is in kWh;
- the power threshold distinguishes idle socket electronics from the heater element;
- the primary sensor placement and maximum temperature are suitable for the room and heater;
- the secondary discrepancy threshold is appropriate for the physical sensor separation;
- socket behavior after network/IP-Symcon loss and the heater's independent thermal limiter.

The intended Symcon instance in the current task is configured in Off mode because its selected socket has not been connected to the heater. No energized heater test is represented as completed.
