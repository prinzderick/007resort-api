namespace R007.Modules.Audit.Domain;

/// <summary>
/// One row of the hash-chained, append-only audit trail (architecture/04 §3.5,
/// architecture/17 §5). <see cref="Seq"/> is the authoritative ordering column (auto-increment);
/// <see cref="Id"/> is the externally addressable UUIDv7. The application DB user must be
/// granted INSERT only on this table (no UPDATE/DELETE) — see db/migrations/README.md.
/// </summary>
public sealed class AuditLogEntry
{
    public long Seq { get; set; }

    public Guid Id { get; set; }

    public DateTimeOffset OccurredAt { get; set; }

    public Guid OrganizationId { get; set; }

    public Guid SiteId { get; set; }

    public Guid? ActorStaffId { get; set; }

    public Guid? FacilityUnitId { get; set; }

    public Guid? OperatingPointId { get; set; }

    public Guid? DeviceId { get; set; }

    public required string Action { get; set; }

    public required string EntityType { get; set; }

    public Guid EntityId { get; set; }

    public string? OldValueJson { get; set; }

    public string? NewValueJson { get; set; }

    public Guid? ApprovalId { get; set; }

    public required string PrevHash { get; set; }

    public required string RowHash { get; set; }
}

public sealed class Approval
{
    public Guid Id { get; set; }

    public Guid RequestedBy { get; set; }

    public Guid? ApprovedBy { get; set; }

    public string Status { get; set; } = "PENDING";

    public string? Reason { get; set; }

    public DateTimeOffset CreatedAt { get; set; }

    public DateTimeOffset? DecidedAt { get; set; }
}

public sealed class SecurityEvent
{
    public Guid Id { get; set; }

    public DateTimeOffset OccurredAt { get; set; }

    public required string EventType { get; set; }

    public string Severity { get; set; } = "INFO";

    public Guid? ActorStaffId { get; set; }

    public Guid? DeviceId { get; set; }

    public string? IpAddress { get; set; }

    public string? DetailsJson { get; set; }

    public DateTimeOffset CreatedAt { get; set; }
}
