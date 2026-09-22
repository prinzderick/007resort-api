namespace R007.Infrastructure.Security;

/// <summary>
/// Minimal, technology-level view of the JWT settings <see cref="JwtTokenService"/> needs.
/// Kept independent of <c>R007.Api</c>'s configuration types so <c>R007.Infrastructure</c> does
/// not depend on the host project; <c>R007.Api</c> maps its bound <c>R007Options.Jwt</c> onto
/// this type at composition time.
/// </summary>
public sealed class JwtSigningOptions
{
    /// <summary>Symmetric signing key — from configuration/user-secrets/environment only, never hardcoded.</summary>
    public required string SigningKey { get; init; }

    public required string Issuer { get; init; }

    public required string StaffAudience { get; init; }

    public required string DeviceAudience { get; init; }

    public required TimeSpan AccessTokenLifetime { get; init; }

    public required TimeSpan DeviceTokenLifetime { get; init; }
}
