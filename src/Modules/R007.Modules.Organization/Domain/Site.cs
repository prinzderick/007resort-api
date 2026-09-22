namespace R007.Modules.Organization.Domain;

public sealed class Site
{
    public Guid Id { get; set; }

    public Guid OrganizationId { get; set; }

    public required string Name { get; set; }

    public string TimeZone { get; set; } = "Africa/Lagos";

    public DateTimeOffset CreatedAt { get; set; }

    public DateTimeOffset UpdatedAt { get; set; }
}
