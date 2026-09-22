using System.IdentityModel.Tokens.Jwt;
using System.Security.Claims;
using System.Text;
using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Http;
using Microsoft.Extensions.Options;
using Microsoft.IdentityModel.Tokens;
using R007.Infrastructure.Security;
using R007.Modules.Devices.Application;

namespace R007.Modules.Devices.Authorization;

/// <summary>
/// Marker requirement: "this request must also carry a valid, unrevoked device-identity token",
/// independently of whichever staff member is authenticated (architecture/06 §5, architecture/17
/// §1/§3). No data — the actual token lives in the request, read by
/// <see cref="DeviceIdentityAuthorizationHandler"/>.
/// </summary>
public sealed class DeviceIdentityRequirement : IAuthorizationRequirement;

/// <summary>
/// <b>The one worked example</b> of combining staff-session and device-identity as two
/// independent bearer credentials on a single endpoint (task scope: "implement ONE example
/// endpoint/authorization-requirement combinator that requires BOTH... document the pattern for
/// future endpoints — do not retrofit every endpoint").
///
/// <b>Why a custom header, not a second <c>Authorization</c> header.</b> HTTP allows only one
/// <c>Authorization</c> header per request, and it is already carrying the staff session's JWT
/// (validated by ASP.NET Core's standard JwtBearer authentication middleware, scheme
/// <c>"Staff"</c>, <c>aud = r007-staff</c>). The device-identity JWT — issued once by
/// <c>POST /api/v1/devices/register</c>, <c>aud = r007-device</c> — travels in a second header,
/// <c>X-Device-Token</c>, and is validated here rather than as a second ASP.NET Core
/// authentication scheme, because a request must authenticate as BOTH principals at once, not as
/// either/or — ASP.NET Core's built-in multi-scheme support
/// (<c>[Authorize(AuthenticationSchemes = "A,B")]</c>) is an OR across schemes, not an AND, so
/// composing them as a normal authentication scheme would not enforce "both required".
///
/// Endpoint example: <c>GET /api/v1/devices/{id}</c> requires
/// <c>.RequireAuthorization().AddDeviceIdentityRequirement()</c> — a signed-in staff member may
/// look up device details only when the request itself also proves it originates from a
/// currently-registered device, closing the "valid staff session replayed from an unregistered
/// or revoked terminal" gap the same way architecture/06 §5 describes for sensitive actions.
/// Future endpoints reuse this same requirement/handler pair; it is not wired onto every
/// endpoint in Phase 1 (only the one example), per the task's explicit scope.
/// </summary>
public sealed class DeviceIdentityAuthorizationHandler(
    IOptions<JwtSigningOptions> jwtOptions,
    IDeviceService deviceService,
    IHttpContextAccessor httpContextAccessor) : AuthorizationHandler<DeviceIdentityRequirement>
{
    public const string HeaderName = "X-Device-Token";

    protected override async Task HandleRequirementAsync(AuthorizationHandlerContext context, DeviceIdentityRequirement requirement)
    {
        var http = httpContextAccessor.HttpContext;
        if (http is null || !http.Request.Headers.TryGetValue(HeaderName, out var headerValues))
        {
            return;
        }

        var token = headerValues.ToString();
        if (string.IsNullOrWhiteSpace(token))
        {
            return;
        }

        var options = jwtOptions.Value;
        var validationParameters = new TokenValidationParameters
        {
            ValidateIssuer = true,
            ValidIssuer = options.Issuer,
            ValidateAudience = true,
            ValidAudience = options.DeviceAudience,
            ValidateLifetime = true,
            ValidateIssuerSigningKey = true,
            IssuerSigningKey = new SymmetricSecurityKey(Encoding.UTF8.GetBytes(options.SigningKey)),
            ClockSkew = TimeSpan.FromSeconds(30),
        };

        ClaimsPrincipal principal;
        try
        {
            principal = new JwtSecurityTokenHandler().ValidateToken(token, validationParameters, out _);
        }
        catch (SecurityTokenException)
        {
            return;
        }

        var deviceIdClaim = principal.FindFirst(R007ClaimTypes.DeviceId)?.Value;
        var tokenIdClaim = principal.FindFirst(JwtRegisteredClaimNames.Jti)?.Value;

        if (!Guid.TryParse(deviceIdClaim, out var deviceId) || !Guid.TryParse(tokenIdClaim, out var tokenId))
        {
            return;
        }

        if (await deviceService.IsRegistrationActiveAsync(deviceId, tokenId, http.RequestAborted))
        {
            context.Succeed(requirement);
        }
    }
}
