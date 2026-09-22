using Microsoft.EntityFrameworkCore;
using R007.Contracts.Organization;
using R007.Infrastructure.Persistence;
using R007.Modules.Organization.Domain;
using R007.SharedKernel.Results;

namespace R007.Modules.Organization.Application;

public sealed class FacilityDirectory(R007DbContext db) : IFacilityDirectory
{
    public static readonly Error NotFound = new("organization.facility.not_found", "Facility unit was not found.");

    public async Task<Result<FacilityCapabilitiesResponse>> GetFacilityCapabilitiesAsync(Guid facilityUnitId, CancellationToken cancellationToken = default)
    {
        var facility = await db.Set<FacilityUnit>()
            .Include(f => f.Capabilities).ThenInclude(c => c.Rules)
            .FirstOrDefaultAsync(f => f.Id == facilityUnitId, cancellationToken);

        if (facility is null)
        {
            return Result.Failure<FacilityCapabilitiesResponse>(NotFound);
        }

        var capabilities = facility.Capabilities
            .Select(c => new FacilityCapabilityDto(
                c.CapabilityCode,
                c.IsEnabled,
                c.Rules.Select(r => new OperatingRuleDto(r.RuleKey, r.RuleValue)).ToList()))
            .ToList();

        return Result.Success(new FacilityCapabilitiesResponse(facility.Id, facility.Code, facility.Name, capabilities));
    }

    public async Task<Result<IReadOnlyList<FacilitySummaryDto>>> ListFacilitiesForSiteAsync(Guid siteId, CancellationToken cancellationToken = default)
    {
        var facilities = await db.Set<FacilityUnit>()
            .Where(f => f.SiteId == siteId)
            .OrderBy(f => f.Code)
            .Select(f => new FacilitySummaryDto(f.Id, f.Code, f.Name, f.ParentId, f.IsActive))
            .ToListAsync(cancellationToken);

        return Result.Success<IReadOnlyList<FacilitySummaryDto>>(facilities);
    }

    public async Task<Result<FacilityCapabilityDto?>> GetEffectiveCapabilityAsync(Guid facilityUnitId, string capabilityCode, CancellationToken cancellationToken = default)
    {
        var capability = await db.Set<FacilityCapability>()
            .Include(c => c.Rules)
            .FirstOrDefaultAsync(c => c.FacilityUnitId == facilityUnitId && c.CapabilityCode == capabilityCode, cancellationToken);

        if (capability is null)
        {
            return Result.Success<FacilityCapabilityDto?>(null);
        }

        var dto = new FacilityCapabilityDto(
            capability.CapabilityCode,
            capability.IsEnabled,
            capability.Rules.Select(r => new OperatingRuleDto(r.RuleKey, r.RuleValue)).ToList());

        return Result.Success<FacilityCapabilityDto?>(dto);
    }
}
