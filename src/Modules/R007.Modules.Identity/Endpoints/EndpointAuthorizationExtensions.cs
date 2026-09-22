using Microsoft.AspNetCore.Builder;
using R007.Contracts.Identity;

namespace R007.Modules.Identity.Endpoints;

/// <summary>Convenience so an endpoint declares the permission it needs in one call, e.g.
/// <c>.RequirePermission(PermissionCodes.AuditView, ScopeLevel.Site)</c>, instead of every module
/// needing to know the <c>"perm:{code}"</c> policy-naming convention.</summary>
public static class EndpointAuthorizationExtensions
{
    public static TBuilder RequirePermission<TBuilder>(this TBuilder builder, string permissionCode, ScopeLevel minimumScope = ScopeLevel.FacilityUnit)
        where TBuilder : IEndpointConventionBuilder
    {
        // minimumScope is accepted for readability at the call site and to keep the signature
        // stable as scope-aware policies are introduced; the registered policy name is keyed
        // only on the permission code today (see PermissionAuthorizationHandler's documented
        // Phase 1 scope-matching simplification).
        return builder.RequireAuthorization(IdentityModule.PolicyName(permissionCode));
    }
}
