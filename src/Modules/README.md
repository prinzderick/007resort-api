# Modules

The API is a **modular monolith**: one deployable, many internally isolated business modules.
No module projects exist yet — this file records the planned boundaries while the architecture is
under review.

| Module | Responsibility (summary) |
|---|---|
| Organization | Company, facilities, operating points, capabilities, configuration |
| Identity | Staff accounts, roles, permissions, PIN/NFC credentials, sessions |
| Devices | Terminal/device registration, trust, heartbeat, per-device configuration |
| Catalog | Products, services, menus, prices, taxes, modifiers |
| Orders | Carts, orders, order lines, voids (with reason + authorization) |
| Payments | Tenders, cash-up, refunds/reversals, shifts, drawer events |
| Inventory | Stock items, locations, movements, counts, transfers, recipes |
| Hospitality/KDS | Kitchen/bar tickets, routing, preparation status |
| Booking | Rooms/resources, reservations, check-in/out |
| Ticketing | Events, tickets, admission scanning |
| Membership | Members, plans, entitlements, NFC cards, visits |
| Attendance | Staff clock-in/out, shifts, rosters |
| Audit | Immutable audit trail of sensitive actions |
| Sync | Site ⇄ Cloud replication, outbox/inbox, conflict policy |
| Reporting | Read models, operational and financial reports |

## Planned layout (per module)

```
src/Modules/<Name>/
  Otueke.Modules.<Name>/            # domain + application + endpoints (internal by default)
  Otueke.Modules.<Name>.Contracts/  # integration events / public module API (optional)
```

## Rules

- Modules talk to each other only through public contracts / integration events — never by
  reaching into another module's tables or internal types.
- Each module owns its tables (prefix or schema-per-module, decided in ADR).
- All schema changes still go through the single migration pipeline in `/db/migrations`.
- Business rules live here, in the API. Clients must not reimplement them.
