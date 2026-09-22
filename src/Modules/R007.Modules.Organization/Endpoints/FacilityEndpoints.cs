using Microsoft.AspNetCore.Builder;
using Microsoft.AspNetCore.Http;
using Microsoft.AspNetCore.Routing;
using R007.Modules.Organization.Application;

namespace R007.Modules.Organization.Endpoints;

public static class FacilityEndpoints
{
    public static IEndpointRouteBuilder MapOrganizationEndpoints(this IEndpointRouteBuilder app)
    {
        var group = app.MapGroup("/facilities").WithTags("Organization");

        group.MapGet("/{id:guid}/capabilities", async (Guid id, IFacilityDirectory directory, CancellationToken ct) =>
        {
            var result = await directory.GetFacilityCapabilitiesAsync(id, ct);

            return result.IsSuccess
                ? Results.Ok(result.Value)
                : Results.Problem(
                    title: "Facility not found",
                    detail: result.Error.Message,
                    statusCode: StatusCodes.Status404NotFound,
                    extensions: new Dictionary<string, object?> { ["code"] = result.Error.Code });
        })
        .WithName("GetFacilityCapabilities")
        .Produces<R007.Contracts.Organization.FacilityCapabilitiesResponse>()
        .ProducesProblem(StatusCodes.Status404NotFound);

        app.MapGroup("/sites").WithTags("Organization")
            .MapGet("/{siteId:guid}/facilities", async (Guid siteId, IFacilityDirectory directory, CancellationToken ct) =>
            {
                var result = await directory.ListFacilitiesForSiteAsync(siteId, ct);
                return Results.Ok(result.Value);
            })
            .WithName("ListFacilitiesForSite")
            .Produces<IReadOnlyList<R007.Contracts.Organization.FacilitySummaryDto>>();

        return app;
    }
}
