# R007.SharedKernel

Deliberately tiny set of primitives shared by every module:

- `Time/IClock` — UTC clock abstraction (never call `DateTime.Now`).
- `Results/Result`, `Results/Error` — explicit success/failure without exceptions for expected outcomes.

## Modules

Business modules (Organization, Identity, Devices, Catalog, Orders, Payments, Inventory,
Hospitality/KDS, Booking, Ticketing, Membership, Attendance, Audit, Sync, Reporting) will live
under `src/Modules/` — see `src/Modules/README.md`. Modules may depend on the SharedKernel, but
the SharedKernel must never depend on a module. Keep it small: if something is only used by one
module, it belongs in that module.
