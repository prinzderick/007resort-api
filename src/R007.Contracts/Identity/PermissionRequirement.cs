using Microsoft.AspNetCore.Authorization;

namespace R007.Contracts.Identity;

/// <summary>
/// Declares that an endpoint requires <see cref="PermissionCode"/> at (or above)
/// <see cref="MinimumScope"/> (architecture/06-roles-permissions.md §1). The requirement itself
/// carries no logic — <c>R007.Modules.Identity</c> registers the
/// <see cref="Microsoft.AspNetCore.Authorization.IAuthorizationHandler"/> that resolves
/// <c>staff × permission × scope</c> against <c>role_assignment</c>/<c>role_permission</c>. It
/// lives in <c>R007.Contracts</c> (not the Identity module) purely so every module can declare
/// the permission its own endpoints need without depending on Identity's internals — the same
/// reason permission code strings live in <see cref="PermissionCodes"/> here rather than in the
/// Identity module.
///
/// <b>Never role-name-based.</b> This type has no notion of a role; the handler resolves purely
/// through the permission bundles a staff member's active role assignments grant at the
/// requested scope (or an ancestor of it) — a role literally named "Manager" grants nothing on
/// its own (architecture/06 §1, and the mandatory denial test in
/// R007.UnitTests/Identity/PermissionAuthorizationHandlerTests.cs).
/// </summary>
public sealed class PermissionRequirement(string permissionCode, ScopeLevel minimumScope) : IAuthorizationRequirement
{
    public string PermissionCode { get; } = permissionCode;

    public ScopeLevel MinimumScope { get; } = minimumScope;
}
