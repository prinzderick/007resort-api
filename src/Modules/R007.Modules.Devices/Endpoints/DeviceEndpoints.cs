using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Builder;
using Microsoft.AspNetCore.Http;
using Microsoft.AspNetCore.Routing;
using R007.Contracts.Devices;
using R007.Contracts.Identity;
using R007.Infrastructure.Idempotency;
using R007.Modules.Devices.Application;
using R007.Modules.Devices.Authorization;
using R007.Modules.Identity.Endpoints;
using R007.SharedKernel.Results;

namespace R007.Modules.Devices.Endpoints;

public static class DeviceEndpoints
{
    /// <summary>Idempotency scope name for device registration (task scope: required on device
    /// registration and role-assignment changes).</summary>
    public const string RegisterIdempotencyScope = "device.register";

    public static IEndpointRouteBuilder MapDeviceEndpoints(this IEndpointRouteBuilder app)
    {
        var group = app.MapGroup("/devices").WithTags("Devices");

        group.MapPost("/register", async (DeviceRegisterRequest request, IDeviceService devices, ClaimsPrincipalAccessor accessor, CancellationToken ct) =>
        {
            // Requires an authenticated staff session holding device.register — device
            // commissioning is always performed by IT/Admin staff (architecture/06 §2), never
            // anonymously.
            var staffId = accessor.RequireStaffId();

            var result = await devices.RegisterAsync(request, staffId, ct);

            return result.IsSuccess
                ? Results.Created($"/api/v1/devices/{result.Value.DeviceId}", result.Value)
                : Problem(result.Error, StatusCodes.Status400BadRequest);
        })
        .WithName("RegisterDevice")
        .RequirePermission(PermissionCodes.DeviceRegister)
        .RequireIdempotencyKey(RegisterIdempotencyScope)
        .Produces<DeviceRegisterResponse>(StatusCodes.Status201Created)
        .ProducesProblem(StatusCodes.Status400BadRequest); // invalid device type, or Idempotency-Key missing

        // The one worked example of requiring BOTH a staff session AND a device-identity token
        // on the same endpoint (see DeviceIdentityAuthorizationHandler for the full rationale).
        // RequireAuthenticatedUser() enforces the staff session (default scheme, "Staff"
        // JwtBearer); AddRequirements(DeviceIdentityRequirement) additionally requires a valid,
        // unrevoked device token in the X-Device-Token header. Future endpoints that need this
        // combination reuse the same pattern: `.RequireAuthorization(p => p.RequireAuthenticatedUser().AddRequirements(new DeviceIdentityRequirement()))`.
        group.MapGet("/{id:guid}", async (Guid id, IDeviceService devices, CancellationToken ct) =>
        {
            var result = await devices.GetAsync(id, ct);
            return result.IsSuccess ? Results.Ok(result.Value) : Problem(result.Error, StatusCodes.Status404NotFound);
        })
        .WithName("GetDevice")
        .RequireAuthorization(policy => policy.RequireAuthenticatedUser().AddRequirements(new DeviceIdentityRequirement()))
        .Produces<DeviceResponse>()
        .ProducesProblem(StatusCodes.Status404NotFound)
        .ProducesProblem(StatusCodes.Status403Forbidden);

        return app;
    }

    private static IResult Problem(Error error, int statusCode) => Results.Problem(
        title: error.Code,
        detail: error.Message,
        statusCode: statusCode,
        extensions: new Dictionary<string, object?> { ["code"] = error.Code });
}
