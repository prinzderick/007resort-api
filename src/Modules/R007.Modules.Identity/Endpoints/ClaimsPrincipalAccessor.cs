using Microsoft.AspNetCore.Http;
using R007.Infrastructure.Security;

namespace R007.Modules.Identity.Endpoints;

/// <summary>Small DI-friendly helper for reading the current staff-session claims out of
/// <see cref="IHttpContextAccessor"/>, so endpoint delegates stay one-liners.</summary>
public sealed class ClaimsPrincipalAccessor(IHttpContextAccessor httpContextAccessor)
{
    public Guid RequireStaffId() => RequireGuidClaim(R007ClaimTypes.StaffId);

    public Guid RequireUserAccountId() => RequireGuidClaim(R007ClaimTypes.UserAccountId);

    public Guid? TryGetDeviceId()
    {
        var value = httpContextAccessor.HttpContext?.User.FindFirst(R007ClaimTypes.DeviceId)?.Value;
        return Guid.TryParse(value, out var id) ? id : null;
    }

    private Guid RequireGuidClaim(string claimType)
    {
        var value = httpContextAccessor.HttpContext?.User.FindFirst(claimType)?.Value;
        if (value is null || !Guid.TryParse(value, out var id))
        {
            throw new InvalidOperationException($"Required claim '{claimType}' was missing or invalid on an authenticated request.");
        }

        return id;
    }
}
