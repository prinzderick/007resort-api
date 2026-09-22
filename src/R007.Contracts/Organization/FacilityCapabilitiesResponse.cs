namespace R007.Contracts.Organization;

/// <summary>Response for <c>GET /api/v1/facilities/{id}/capabilities</c> (architecture/15 §2).</summary>
public sealed record FacilityCapabilitiesResponse(
    Guid FacilityUnitId,
    string Code,
    string Name,
    IReadOnlyList<FacilityCapabilityDto> Capabilities);

public sealed record FacilityCapabilityDto(
    string CapabilityCode,
    bool IsEnabled,
    IReadOnlyList<OperatingRuleDto> Rules);

public sealed record OperatingRuleDto(string Key, string Value);

/// <summary>Summary row for <c>GET /api/v1/sites/{siteId}/facilities</c>-style listings.</summary>
public sealed record FacilitySummaryDto(
    Guid Id,
    string Code,
    string Name,
    Guid? ParentId,
    bool IsActive);
