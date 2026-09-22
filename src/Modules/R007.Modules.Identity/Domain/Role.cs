namespace R007.Modules.Identity.Domain;

/// <summary>A named bundle of permissions. Grants nothing by itself — see
/// <c>PermissionAuthorizationHandler</c> (architecture/06 §1).</summary>
public sealed class Role
{
    public long Id { get; set; }

    public required string Code { get; set; }

    public required string Name { get; set; }

    public string? Description { get; set; }

    public DateTimeOffset CreatedAt { get; set; }

    public DateTimeOffset UpdatedAt { get; set; }
}

public sealed class Permission
{
    public long Id { get; set; }

    public required string Code { get; set; }

    public string? Description { get; set; }

    public DateTimeOffset CreatedAt { get; set; }
}

public sealed class RolePermission
{
    public long RoleId { get; set; }

    public long PermissionId { get; set; }

    public bool RequiresApproval { get; set; }
}

/// <summary>Scopes a <see cref="Role"/> to a <see cref="Staff"/> at organization, site or
/// facility-unit level (architecture/06 §1).</summary>
public enum ScopeLevel
{
    Organization,
    Site,
    FacilityUnit,
}

public sealed class RoleAssignment
{
    public Guid Id { get; set; }

    public Guid StaffId { get; set; }

    public long RoleId { get; set; }

    public ScopeLevel ScopeLevel { get; set; }

    public Guid OrganizationId { get; set; }

    public Guid? SiteId { get; set; }

    public Guid? FacilityUnitId { get; set; }

    public bool IsActive { get; set; } = true;

    public DateTimeOffset? DeletedAt { get; set; }

    public Guid? GrantedBy { get; set; }

    public DateTimeOffset GrantedAt { get; set; }

    public int RowVersion { get; set; } = 1;

    public DateTimeOffset CreatedAt { get; set; }

    public DateTimeOffset UpdatedAt { get; set; }
}
