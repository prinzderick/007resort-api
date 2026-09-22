namespace Otueke.Api.Configuration;

/// <summary>Root platform options bound from the <c>Otueke</c> configuration section.</summary>
public sealed class OtuekeOptions
{
    public const string SectionName = "Otueke";

    /// <summary>
    /// Where this instance runs. The same binary serves both roles:
    /// <see cref="DeploymentMode.Site"/> (on-premises facility server, local MySQL, source of truth for
    /// live operations) or <see cref="DeploymentMode.Cloud"/> (central sync/reporting instance).
    /// </summary>
    public DeploymentMode DeploymentMode { get; set; } = DeploymentMode.Site;
}

/// <summary>Deployment roles supported by the API binary.</summary>
public enum DeploymentMode
{
    Site,
    Cloud,
}
