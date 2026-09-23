using Microsoft.AspNetCore.Diagnostics.HealthChecks;
using Microsoft.Extensions.Diagnostics.HealthChecks;

namespace R007.Api.Endpoints;

internal static class HealthEndpoints
{
    /// <summary>Tag for checks that only prove the process is alive (no external dependencies).</summary>
    public const string LiveTag = "live";

    /// <summary>Tag for checks that prove the instance can serve traffic (database, etc.).</summary>
    public const string ReadyTag = "ready";

    public static IServiceCollection AddR007HealthChecks(this IServiceCollection services)
    {
        services.AddHealthChecks()
            .AddCheck("self", () => HealthCheckResult.Healthy(), tags: [LiveTag, ReadyTag]);

        // TODO(phase-1): add a MySQL 8.4 connectivity check tagged ReadyTag once the persistence
        // stack is approved (see src/R007.Infrastructure/README.md and the architecture ADRs).
        return services;
    }

    public static IEndpointRouteBuilder MapR007HealthEndpoints(this IEndpointRouteBuilder endpoints)
    {
        endpoints.MapHealthChecks("/health/live", new HealthCheckOptions
        {
            Predicate = check => check.Tags.Contains(LiveTag),
        }).ExcludeFromDescription();

        endpoints.MapHealthChecks("/health/ready", new HealthCheckOptions
        {
            Predicate = check => check.Tags.Contains(ReadyTag),
        }).ExcludeFromDescription();

        return endpoints;
    }
}
