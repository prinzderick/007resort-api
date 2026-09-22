namespace R007.Modules.Organization.Domain;

/// <summary>
/// Owning entity of the whole platform (architecture/03-domain-model.md §1). Phase 1 has a single
/// row. Internal to the Organization module — other modules reach this data only through
/// <see cref="R007.Modules.Organization.Application.IFacilityDirectory"/>.
/// </summary>
public sealed class OrganizationEntity
{
    public Guid Id { get; set; }

    public required string Name { get; set; }

    public DateTimeOffset CreatedAt { get; set; }

    public DateTimeOffset UpdatedAt { get; set; }
}
