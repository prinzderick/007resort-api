# Otueke.Contracts

Public API DTOs (request/response records) for the Otueke API. This project may be referenced by
first-party clients (e.g. the POS desktop app) and must stay free of domain entities, persistence
concerns and business logic. Entities are never exposed over the wire — map them to contracts.

Breaking changes to a contract require a new API version (`/api/v2/...`).
