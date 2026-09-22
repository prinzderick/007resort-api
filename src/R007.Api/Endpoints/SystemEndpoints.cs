using System.Reflection;
using Microsoft.Extensions.Options;
using R007.Api.Configuration;
using R007.Contracts.Platform;

namespace R007.Api.Endpoints;

internal static class SystemEndpoints
{
    public const string ServiceName = "007resort-api";

    public static RouteGroupBuilder MapSystemEndpoints(this RouteGroupBuilder v1)
    {
        var group = v1.MapGroup("/system").WithTags("System");

        group.MapGet("/info", GetInfo)
            .WithName("GetSystemInfo")
            .WithSummary("Returns service identity, version, environment and deployment mode.");

        return v1;
    }

    private static SystemInfoResponse GetInfo(IHostEnvironment environment, IOptions<R007Options> options)
    {
        var version = typeof(SystemEndpoints).Assembly
            .GetCustomAttribute<AssemblyInformationalVersionAttribute>()?.InformationalVersion ?? "0.0.0";

        return new SystemInfoResponse(
            ServiceName,
            version,
            environment.EnvironmentName,
            options.Value.DeploymentMode.ToString());
    }
}
