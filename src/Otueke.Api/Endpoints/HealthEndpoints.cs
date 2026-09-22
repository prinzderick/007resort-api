using Microsoft.AspNetCore.Diagnostics.HealthChecks;
using Microsoft.Extensions.Diagnostics.HealthChecks;

namespace Otueke.Api.Endpoints;

internal static class HealthEndpoints
{
    /// <summary>Tag for checks that only prove the process is alive (no external dependencies).</summary>
    public const string LiveTag = "live";

    /// <summary>Tag for checks that prove the instance can serve traffic (database, etc.).</summary>
    public const string ReadyTag = "ready";

    public static IServiceCollection AddOtuekeHealthChecks(this IServiceCollection services)
    {
        services.AddHealthChecks()
            .AddCheck("self", () => HealthCheckResult.Healthy(), tags: [LiveTag, ReadyTag]);

        // TODO(phase-1): add a MySQL 8.4 connectivity check tagged ReadyTag once the persistence
        // stack is approved (see src/Otueke.Infrastructure/README.md and the architecture ADRs).
        return services;
    }

    public static IEndpointRouteBuilder MapOtuekeHealthEndpoints(this IEndpointRouteBuilder endpoints)
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
