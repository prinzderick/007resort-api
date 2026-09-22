namespace R007.Contracts.Audit;

/// <summary>
/// Everything needed to write one <c>audit_log</c> row. Passed by any module recording a
/// sensitive action (login, device registration, role assignment change, ...). Old/new values
/// are pre-serialized JSON strings (each module knows how to shape its own entity's snapshot) so
/// the Audit module never needs to reference another module's entity types.
/// </summary>
public sealed record AuditEntry(
    Guid OrganizationId,
    Guid SiteId,
    string Action,
    string EntityType,
    Guid EntityId,
    Guid? ActorStaffId = null,
    Guid? FacilityUnitId = null,
    Guid? OperatingPointId = null,
    Guid? DeviceId = null,
    string? OldValueJson = null,
    string? NewValueJson = null,
    Guid? ApprovalId = null);

/// <summary>Result of a hash-chain verification pass over a range of <c>audit_log</c> rows.</summary>
public sealed record AuditChainVerificationResult(bool IsIntact, long? FirstBrokenSeq);

public sealed record AuditLogEntryResponse(
    Guid Id,
    DateTimeOffset OccurredAt,
    Guid? ActorStaffId,
    string Action,
    string EntityType,
    Guid EntityId,
    string? OldValueJson,
    string? NewValueJson,
    string PrevHash,
    string RowHash);
