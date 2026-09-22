using Microsoft.AspNetCore.Builder;
using Microsoft.AspNetCore.Http;
using Microsoft.AspNetCore.Routing;
using R007.Contracts.Identity;
using R007.Infrastructure.Idempotency;
using R007.Modules.Identity.Application;
using R007.SharedKernel.Results;

namespace R007.Modules.Identity.Endpoints;

public static class RoleAssignmentEndpoints
{
    /// <summary>Idempotency scope name for role-assignment changes (task scope: required
    /// alongside device registration).</summary>
    public const string GrantIdempotencyScope = "role_assignment.manage";

    public static IEndpointRouteBuilder MapRoleAssignmentEndpoints(this IEndpointRouteBuilder app)
    {
        var group = app.MapGroup("/role-assignments").WithTags("Identity");

        group.MapPost("/", async (GrantRoleAssignmentRequest request, IRoleAssignmentService service, ClaimsPrincipalAccessor accessor, CancellationToken ct) =>
        {
            var grantedBy = accessor.RequireStaffId();
            var result = await service.GrantAsync(request, grantedBy, ct);

            return result.IsSuccess
                ? Results.Created($"/api/v1/role-assignments/{result.Value.Id}", result.Value)
                : Problem(result.Error, StatusCodes.Status404NotFound);
        })
        .WithName("GrantRoleAssignment")
        .RequirePermission(PermissionCodes.RoleAssignmentManage, ScopeLevel.Site)
        .RequireIdempotencyKey(GrantIdempotencyScope)
        .Produces<RoleAssignmentResponse>(StatusCodes.Status201Created)
        .ProducesProblem(StatusCodes.Status404NotFound);

        group.MapPost("/{id:guid}/revoke", async (Guid id, RevokeRoleAssignmentRequest? request, IRoleAssignmentService service, ClaimsPrincipalAccessor accessor, CancellationToken ct) =>
        {
            var revokedBy = accessor.RequireStaffId();
            var result = await service.RevokeAsync(id, revokedBy, request?.Reason, ct);

            return result.IsSuccess ? Results.NoContent() : Problem(result.Error, StatusCodes.Status404NotFound);
        })
        .WithName("RevokeRoleAssignment")
        .RequirePermission(PermissionCodes.RoleAssignmentManage, ScopeLevel.Site)
        .RequireIdempotencyKey(GrantIdempotencyScope)
        .Produces(StatusCodes.Status204NoContent)
        .ProducesProblem(StatusCodes.Status404NotFound);

        return app;
    }

    private static IResult Problem(Error error, int statusCode) => Results.Problem(
        title: error.Code,
        detail: error.Message,
        statusCode: statusCode,
        extensions: new Dictionary<string, object?> { ["code"] = error.Code });
}
