namespace R007.Contracts.Identity;

public sealed record GrantRoleAssignmentRequest(
    Guid StaffId,
    string RoleCode,
    ScopeLevel ScopeLevel,
    Guid OrganizationId,
    Guid? SiteId,
    Guid? FacilityUnitId);

public sealed record RoleAssignmentResponse(
    Guid Id,
    Guid StaffId,
    string RoleCode,
    ScopeLevel ScopeLevel,
    Guid OrganizationId,
    Guid? SiteId,
    Guid? FacilityUnitId,
    bool IsActive);

public sealed record RevokeRoleAssignmentRequest(string? Reason = null);
