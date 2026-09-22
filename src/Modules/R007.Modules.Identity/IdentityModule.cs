using Microsoft.AspNetCore.Authorization;
using Microsoft.Extensions.DependencyInjection;
using R007.Contracts.Identity;
using R007.Infrastructure.Security;
using R007.Modules.Identity.Application;
using R007.Modules.Identity.Authorization;
using R007.Modules.Identity.Endpoints;

namespace R007.Modules.Identity;

/// <summary>Composition-root entry point for the Identity module (architecture/14 §1).</summary>
public static class IdentityModule
{
    public static IServiceCollection AddIdentityModule(this IServiceCollection services)
    {
        services.AddScoped<IStaffAuthService, StaffAuthService>();
        services.AddScoped<IRoleAssignmentService, RoleAssignmentService>();
        services.AddScoped<IAuthorizationHandler, PermissionAuthorizationHandler>();
        services.AddSingleton<Argon2IdHasher>();
        services.AddSingleton<JwtTokenService>();
        services.AddHttpContextAccessor();
        services.AddScoped<ClaimsPrincipalAccessor>();
        return services;
    }

    /// <summary>Registers one <see cref="AuthorizationPolicy"/> per permission code the API needs
    /// declared ahead of time, named <c>"perm:{code}"</c>, so endpoints can write
    /// <c>.RequirePermission(PermissionCodes.X, ScopeLevel.Site)</c> instead of constructing a
    /// policy inline.</summary>
    public static AuthorizationOptions AddPermissionPolicy(this AuthorizationOptions options, string permissionCode, ScopeLevel minimumScope)
    {
        options.AddPolicy(PolicyName(permissionCode), policy => policy.AddRequirements(new PermissionRequirement(permissionCode, minimumScope)));
        return options;
    }

    public static string PolicyName(string permissionCode) => $"perm:{permissionCode}";
}
