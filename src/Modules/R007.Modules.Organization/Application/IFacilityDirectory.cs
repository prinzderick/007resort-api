using R007.Contracts.Organization;
using R007.SharedKernel.Results;

namespace R007.Modules.Organization.Application;

/// <summary>
/// The Organization module's public entry point (architecture/14-api-module-map.md §1: "the only
/// entry point other modules or the host may call"). Other modules and the API host depend on
/// this interface only — never on <c>R007.Modules.Organization.Domain</c> or
/// <c>R007.Modules.Organization.Persistence</c> types directly.
/// </summary>
public interface IFacilityDirectory
{
    Task<Result<FacilityCapabilitiesResponse>> GetFacilityCapabilitiesAsync(Guid facilityUnitId, CancellationToken cancellationToken = default);

    Task<Result<IReadOnlyList<FacilitySummaryDto>>> ListFacilitiesForSiteAsync(Guid siteId, CancellationToken cancellationToken = default);

    /// <summary>
    /// Resolves whether <paramref name="facilityUnitId"/> has <paramref name="capabilityCode"/>
    /// enabled and, if so, its operating rules — the primitive every other module's business
    /// logic queries instead of branching on facility name (architecture/05 §1).
    /// </summary>
    Task<Result<FacilityCapabilityDto?>> GetEffectiveCapabilityAsync(Guid facilityUnitId, string capabilityCode, CancellationToken cancellationToken = default);
}
