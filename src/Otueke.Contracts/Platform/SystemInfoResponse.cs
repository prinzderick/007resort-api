namespace Otueke.Contracts.Platform;

/// <summary>Response for <c>GET /api/v1/system/info</c>.</summary>
/// <param name="Service">Logical service name (always "otueke-api").</param>
/// <param name="Version">Informational version of the running build.</param>
/// <param name="Environment">ASP.NET Core hosting environment (Development, Staging, Production).</param>
/// <param name="Mode">Deployment mode: "Site" (on-premises facility server) or "Cloud".</param>
public sealed record SystemInfoResponse(string Service, string Version, string Environment, string Mode);
