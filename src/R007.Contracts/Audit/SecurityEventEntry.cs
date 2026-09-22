namespace R007.Contracts.Audit;

public sealed record SecurityEventEntry(
    string EventType,
    string Severity,
    Guid? ActorStaffId = null,
    Guid? DeviceId = null,
    string? IpAddress = null,
    string? DetailsJson = null);
