using Microsoft.AspNetCore.Authorization;
using Microsoft.EntityFrameworkCore;
using R007.Contracts.Identity;
using R007.Infrastructure.Persistence;
using R007.Infrastructure.Security;
using R007.Modules.Identity.Domain;

namespace R007.Modules.Identity.Authorization;

/// <summary>
/// Resolves <c>staff × permission × scope</c> against <c>role_assignment</c> /
/// <c>role_permission</c> / <c>permission</c> — never against a role's name
/// (architecture/06-roles-permissions.md §1). This is the single place in the codebase that
/// decides "may this staff member do X"; every other permission-gated endpoint reuses it by
/// declaring a <see cref="PermissionRequirement"/>, never by checking
/// <c>User.IsInRole("Manager")</c> or similar.
///
/// <b>Phase 1 scope-matching simplification (documented deviation).</b> The platform's Phase 1
/// topology is a single organization and a single site (architecture/03 §1), so this handler
/// grants access whenever the staff member holds an *active* role assignment bundling the
/// requested permission at *any* scope level, rather than resolving the requirement's
/// <see cref="PermissionRequirement.MinimumScope"/> against a concrete resource's
/// organization/site/facility ancestry chain (there is, so far, only one of each to matter).
/// Once a second site or facility-scoped sensitive action needs to distinguish "supervisor of
/// Facility A" from "supervisor of Facility B", this handler must be extended to take the acted-
/// upon resource's facility/site id as an authorization resource and walk the ancestry chain —
/// tracked as a Phase 2 follow-up.
/// </summary>
public sealed class PermissionAuthorizationHandler(R007DbContext db) : AuthorizationHandler<PermissionRequirement>
{
    protected override async Task HandleRequirementAsync(AuthorizationHandlerContext context, PermissionRequirement requirement)
    {
        var staffIdClaim = context.User.FindFirst(R007ClaimTypes.StaffId)?.Value;
        if (staffIdClaim is null || !Guid.TryParse(staffIdClaim, out var staffId))
        {
            return;
        }

        var hasPermission = await db.Set<RoleAssignment>()
            .Where(ra => ra.StaffId == staffId && ra.IsActive)
            .Join(db.Set<RolePermission>(), ra => ra.RoleId, rp => rp.RoleId, (ra, rp) => rp)
            .Join(db.Set<Permission>(), rp => rp.PermissionId, p => p.Id, (rp, p) => p)
            .AnyAsync(p => p.Code == requirement.PermissionCode);

        if (hasPermission)
        {
            context.Succeed(requirement);
        }
    }
}
