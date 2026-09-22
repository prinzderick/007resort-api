using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Builder;
using Microsoft.AspNetCore.Http;
using Microsoft.AspNetCore.Routing;
using R007.Contracts.Identity;
using R007.Infrastructure.Security;
using R007.Modules.Identity.Application;

namespace R007.Modules.Identity.Endpoints;

public static class AuthEndpoints
{
    public static IEndpointRouteBuilder MapIdentityEndpoints(this IEndpointRouteBuilder app)
    {
        var group = app.MapGroup("/auth").WithTags("Identity");

        group.MapPost("/staff/login", async (StaffLoginRequest request, IStaffAuthService auth, HttpContext http, CancellationToken ct) =>
        {
            var ip = http.Connection.RemoteIpAddress?.ToString();
            var userAgent = http.Request.Headers.UserAgent.ToString();
            var result = await auth.LoginAsync(request, ip, userAgent, ct);

            return result.IsSuccess
                ? Results.Ok(result.Value)
                : Problem(result.Error, StatusCodes.Status401Unauthorized);
        })
        .WithName("StaffLogin")
        .AllowAnonymous()
        .Produces<StaffLoginResponse>()
        .ProducesProblem(StatusCodes.Status401Unauthorized);
        // Login is intentionally NOT idempotency-key gated (architecture/15 §1 lists it as an
        // explicit exception): each call is a fresh authentication attempt, not a retryable
        // creation of a single resource, and a duplicate key would only complicate lockout
        // counting for no benefit.

        group.MapPost("/staff/step-up", async (StaffStepUpRequest request, IStaffAuthService auth, ClaimsPrincipalAccessor accessor, CancellationToken ct) =>
        {
            var userAccountId = accessor.RequireUserAccountId();
            var result = await auth.StepUpAsync(userAccountId, request, ct);

            return result.IsSuccess
                ? Results.Ok(result.Value)
                : Problem(result.Error, StatusCodes.Status401Unauthorized);
        })
        .WithName("StaffStepUp")
        .RequireAuthorization()
        .Produces<StaffStepUpResponse>()
        .ProducesProblem(StatusCodes.Status401Unauthorized);

        app.MapPost("/sessions/{id:guid}/revoke", async (Guid id, RevokeSessionRequest? request, IStaffAuthService auth, ClaimsPrincipalAccessor accessor, CancellationToken ct) =>
        {
            var staffId = accessor.RequireStaffId();
            var result = await auth.RevokeSessionAsync(id, staffId, request?.Reason, ct);

            return result.IsSuccess
                ? Results.NoContent()
                : Problem(result.Error, StatusCodes.Status404NotFound);
        })
        .WithTags("Identity")
        .WithName("RevokeSession")
        .RequireAuthorization()
        .Produces(StatusCodes.Status204NoContent)
        .ProducesProblem(StatusCodes.Status404NotFound);

        return app;
    }

    private static IResult Problem(R007.SharedKernel.Results.Error error, int statusCode) => Results.Problem(
        title: error.Code,
        detail: error.Message,
        statusCode: statusCode,
        extensions: new Dictionary<string, object?> { ["code"] = error.Code });
}
