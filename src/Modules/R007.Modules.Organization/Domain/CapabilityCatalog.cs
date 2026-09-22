namespace R007.Modules.Organization.Domain;

/// <summary>Fixed, seeded catalog row (architecture/05 §2). Keyed by a stable code, not an id.</summary>
public sealed class CapabilityType
{
    public required string Code { get; set; }

    public required string Description { get; set; }
}

/// <summary>Whether a capability is switched on for a given facility (architecture/05 §2).</summary>
public sealed class FacilityCapability
{
    public Guid Id { get; set; }

    public Guid FacilityUnitId { get; set; }

    public required string CapabilityCode { get; set; }

    public bool IsEnabled { get; set; } = true;

    public DateTimeOffset CreatedAt { get; set; }

    public DateTimeOffset UpdatedAt { get; set; }

    public List<OperatingRule> Rules { get; set; } = [];
}

/// <summary>A typed key/value parameter scoped to one <see cref="FacilityCapability"/> (architecture/05 §2).</summary>
public sealed class OperatingRule
{
    public Guid Id { get; set; }

    public Guid FacilityCapabilityId { get; set; }

    public required string RuleKey { get; set; }

    public required string RuleValue { get; set; }

    public DateTimeOffset CreatedAt { get; set; }

    public DateTimeOffset UpdatedAt { get; set; }
}
