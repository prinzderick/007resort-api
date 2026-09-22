using System.Reflection;
using Microsoft.Extensions.Options;
using Otueke.Api.Configuration;
using Otueke.Contracts.Platform;

namespace Otueke.Api.Endpoints;

internal static class SystemEndpoints
{
    public const string ServiceName = "otueke-api";

    public static RouteGroupBuilder MapSystemEndpoints(this RouteGroupBuilder v1)
    {
        var group = v1.MapGroup("/system").WithTags("System");

        group.MapGet("/info", GetInfo)
            .WithName("GetSystemInfo")
            .WithSummary("Returns service identity, version, environment and deployment mode.");

        return v1;
    }

    private static SystemInfoResponse GetInfo(IHostEnvironment environment, IOptions<OtuekeOptions> options)
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
