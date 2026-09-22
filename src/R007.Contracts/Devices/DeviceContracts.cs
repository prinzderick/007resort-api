namespace R007.Contracts.Devices;

public sealed record DeviceRegisterRequest(
    Guid OrganizationId,
    Guid SiteId,
    Guid? FacilityUnitId,
    string DeviceType,
    string Name);

public sealed record DeviceRegisterResponse(
    Guid DeviceId,
    string DeviceToken,
    DateTimeOffset DeviceTokenExpiresAt);

public sealed record DeviceResponse(
    Guid Id,
    Guid OrganizationId,
    Guid SiteId,
    Guid? FacilityUnitId,
    string DeviceType,
    string Name,
    bool IsActive,
    bool IsRevoked);
