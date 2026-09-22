using Microsoft.AspNetCore.Builder;
using Microsoft.AspNetCore.Http;
using Microsoft.AspNetCore.Routing;
using R007.Modules.Audit.Application;

namespace R007.Modules.Audit.Endpoints;

public static class AuditEndpoints
{
    public static IEndpointRouteBuilder MapAuditEndpoints(this IEndpointRouteBuilder app)
    {
        app.MapGet("/audit", async (string? entityType, Guid? entityId, int? limit, IAuditReader reader, CancellationToken ct) =>
        {
            var rows = await reader.QueryAsync(entityType, entityId, limit ?? 50, ct);
            return Results.Ok(rows);
        })
        .WithTags("Audit")
        .WithName("QueryAuditLog")
        // Permission requirement is applied by the Api host composition (Identity's
        // RequirePermission extension), see R007.Api Program.cs — the Audit module itself does
        // not depend on Identity, so it cannot reference that extension directly.
        .RequireAuthorization("perm:audit.view")
        .Produces<IReadOnlyList<R007.Contracts.Audit.AuditLogEntryResponse>>();

        return app;
    }
}
