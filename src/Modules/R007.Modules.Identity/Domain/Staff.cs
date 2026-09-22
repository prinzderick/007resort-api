namespace R007.Modules.Identity.Domain;

public sealed class Staff
{
    public Guid Id { get; set; }

    public Guid OrganizationId { get; set; }

    public Guid SiteId { get; set; }

    public required string StaffNumber { get; set; }

    public required string FirstName { get; set; }

    public required string LastName { get; set; }

    public string? Email { get; set; }

    public string? Phone { get; set; }

    public bool IsActive { get; set; } = true;

    public DateTimeOffset? DeletedAt { get; set; }

    public int RowVersion { get; set; } = 1;

    public DateTimeOffset CreatedAt { get; set; }

    public DateTimeOffset UpdatedAt { get; set; }
}
