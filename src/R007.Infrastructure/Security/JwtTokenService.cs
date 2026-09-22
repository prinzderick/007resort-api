using System.IdentityModel.Tokens.Jwt;
using System.Security.Claims;
using System.Text;
using Microsoft.IdentityModel.Tokens;
using Microsoft.Extensions.Options;
using R007.SharedKernel.Time;

namespace R007.Infrastructure.Security;

/// <summary>Well-known claim types shared by every JWT this API issues.</summary>
public static class R007ClaimTypes
{
    /// <summary>The staff member's id (staff-session tokens only).</summary>
    public const string StaffId = "r007:staff_id";

    /// <summary>The user account's id (staff-session tokens only).</summary>
    public const string UserAccountId = "r007:user_account_id";

    /// <summary>The <c>session</c> row this access token was issued from (staff-session tokens only).</summary>
    public const string SessionId = "r007:session_id";

    /// <summary>The device's id (device-identity tokens only).</summary>
    public const string DeviceId = "r007:device_id";
}

/// <summary>
/// Issues and validates the platform's two independent JWT-based bearer credentials
/// (architecture/17-security-model.md §1, §3):
///
///   1. A **staff session** access token (short-lived, ~15 min, <c>aud = StaffAudience</c>),
///      issued after password/PIN login, refreshed via the opaque, hashed refresh token in
///      the <c>session</c> table.
///   2. A **device identity** token (long-lived, <c>aud = DeviceAudience</c>), issued once at
///      device registration and re-issued on re-registration. It authenticates *the terminal*,
///      independently of whichever staff member is currently signed in on it.
///
/// Both reuse the same signing infrastructure (symmetric key, HMAC-SHA256) but are never
/// interchangeable: each carries a distinct <c>aud</c> claim, and ASP.NET Core authentication
/// registers them as two separate schemes (see <c>R007.Api</c> composition) so an endpoint can
/// require either, or — via a combinator policy — both at once (see
/// <c>R007.Modules.Devices</c>'s <c>RequireStaffAndDevice</c> policy for the one example
/// endpoint the spec asks for; the pattern is documented there for future endpoints).
/// </summary>
public sealed class JwtTokenService(IOptions<JwtSigningOptions> options, IClock clock)
{
    private readonly JwtSigningOptions _options = options.Value;

    public IssuedToken IssueStaffAccessToken(Guid userAccountId, Guid staffId, Guid sessionId)
    {
        var now = clock.UtcNow;
        var expiresAt = now.Add(_options.AccessTokenLifetime);

        var claims = new[]
        {
            new Claim(JwtRegisteredClaimNames.Sub, userAccountId.ToString()),
            new Claim(JwtRegisteredClaimNames.Jti, Guid.CreateVersion7().ToString()),
            new Claim(R007ClaimTypes.UserAccountId, userAccountId.ToString()),
            new Claim(R007ClaimTypes.StaffId, staffId.ToString()),
            new Claim(R007ClaimTypes.SessionId, sessionId.ToString()),
        };

        var token = CreateToken(claims, _options.StaffAudience, now, expiresAt);
        return new IssuedToken(token, expiresAt);
    }

    public IssuedToken IssueDeviceToken(Guid deviceId, Guid tokenId, DateTimeOffset? expiresAtOverride = null)
    {
        var now = clock.UtcNow;
        var expiresAt = expiresAtOverride ?? now.Add(_options.DeviceTokenLifetime);

        var claims = new[]
        {
            new Claim(JwtRegisteredClaimNames.Sub, deviceId.ToString()),
            new Claim(JwtRegisteredClaimNames.Jti, tokenId.ToString()),
            new Claim(R007ClaimTypes.DeviceId, deviceId.ToString()),
        };

        var token = CreateToken(claims, _options.DeviceAudience, now, expiresAt);
        return new IssuedToken(token, expiresAt);
    }

    private string CreateToken(IEnumerable<Claim> claims, string audience, DateTimeOffset now, DateTimeOffset expiresAt)
    {
        var key = new SymmetricSecurityKey(Encoding.UTF8.GetBytes(_options.SigningKey));
        var credentials = new SigningCredentials(key, SecurityAlgorithms.HmacSha256);

        var token = new JwtSecurityToken(
            issuer: _options.Issuer,
            audience: audience,
            claims: claims,
            notBefore: now.UtcDateTime,
            expires: expiresAt.UtcDateTime,
            signingCredentials: credentials);

        return new JwtSecurityTokenHandler().WriteToken(token);
    }
}

public sealed record IssuedToken(string Token, DateTimeOffset ExpiresAt);
