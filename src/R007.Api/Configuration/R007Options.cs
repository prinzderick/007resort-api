namespace R007.Api.Configuration;

/// <summary>Root platform options bound from the <c>R007</c> configuration section.</summary>
public sealed class R007Options
{
    public const string SectionName = "R007";

    /// <summary>
    /// Where this instance runs. The same binary serves both roles:
    /// <see cref="DeploymentMode.Site"/> (on-premises facility server, local MySQL, source of truth for
    /// live operations) or <see cref="DeploymentMode.Cloud"/> (central sync/reporting instance).
    /// </summary>
    public DeploymentMode DeploymentMode { get; set; } = DeploymentMode.Site;

    public JwtOptions Jwt { get; set; } = new();

    public MigrationsOptions Migrations { get; set; } = new();
}

/// <summary>
/// JWT signing configuration. <see cref="SigningKey"/> must come only from configuration,
/// user-secrets or environment variables (<c>R007:Jwt:SigningKey</c> / <c>R007__Jwt__SigningKey</c>)
/// — it is never hardcoded and never committed (architecture/17-security-model.md §6).
/// </summary>
public sealed class JwtOptions
{
    /// <summary>Symmetric signing key. Required at startup; validated by <c>ValidateOnStart</c>.</summary>
    public string SigningKey { get; set; } = string.Empty;

    public string Issuer { get; set; } = "https://r007.local";

    /// <summary>Audience for staff session access tokens (device tokens use <see cref="DeviceAudience"/>).</summary>
    public string StaffAudience { get; set; } = "r007-staff";

    /// <summary>Audience for device-identity tokens issued by device registration.</summary>
    public string DeviceAudience { get; set; } = "r007-device";

    public int AccessTokenLifetimeMinutes { get; set; } = 15;

    public int RefreshTokenLifetimeDays { get; set; } = 30;

    public int DeviceTokenLifetimeDays { get; set; } = 365;
}

/// <summary>
/// Controls whether the DbUp migration runner applies pending <c>db/migrations</c> scripts
/// automatically at API startup. Automatic apply happens when <see cref="AutoApply"/> is true,
/// when <c>ASPNETCORE_ENVIRONMENT=Development</c>, or (as a deliberate operator opt-in) when
/// <see cref="R007Options.DeploymentMode"/> is <see cref="DeploymentMode.Site"/> — a site server
/// is expected to self-manage its own schema on deploy. Other environments (Cloud, staging,
/// production) must run the separate manual migration runner
/// (<c>dotnet run --project tools/R007.MigrationRunner</c>) as a deploy step instead; see
/// db/migrations/README.md.
/// </summary>
public sealed class MigrationsOptions
{
    public bool AutoApply { get; set; }
}

/// <summary>Deployment roles supported by the API binary.</summary>
public enum DeploymentMode
{
    Site,
    Cloud,
}
