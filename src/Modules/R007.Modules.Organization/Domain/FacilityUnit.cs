namespace R007.Modules.Organization.Domain;

/// <summary>
/// A node in the facility tree (architecture/03 §1). Behaviour comes entirely from its
/// <see cref="FacilityCapability"/> rows, never from <see cref="Code"/>/<see cref="Name"/>
/// (architecture/05-facility-capability-model.md §1).
/// </summary>
public sealed class FacilityUnit
{
    public Guid Id { get; set; }

    public Guid OrganizationId { get; set; }

    public Guid SiteId { get; set; }

    public Guid? ParentId { get; set; }

    public required string Code { get; set; }

    public required string Name { get; set; }

    public bool IsActive { get; set; } = true;

    public DateTimeOffset? DeletedAt { get; set; }

    public int RowVersion { get; set; } = 1;

    public DateTimeOffset CreatedAt { get; set; }

    public DateTimeOffset UpdatedAt { get; set; }

    public List<FacilityCapability> Capabilities { get; set; } = [];
}
