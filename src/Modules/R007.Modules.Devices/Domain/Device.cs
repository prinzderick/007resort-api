namespace R007.Modules.Devices.Domain;

public enum DeviceType
{
    Pos,
    Tablet,
    Kds,
    Scanner,
    Printer,
    BiometricTerminal,
    NfcReader,
    AdminWebClient,
    Other,
}

public sealed class Device
{
    public Guid Id { get; set; }

    public Guid OrganizationId { get; set; }

    public Guid SiteId { get; set; }

    public Guid? FacilityUnitId { get; set; }

    public DeviceType DeviceType { get; set; }

    public required string Name { get; set; }

    public bool IsActive { get; set; } = true;

    public bool IsRevoked { get; set; }

    public DateTimeOffset? RevokedAt { get; set; }

    public int RowVersion { get; set; } = 1;

    public DateTimeOffset CreatedAt { get; set; }

    public DateTimeOffset UpdatedAt { get; set; }
}

/// <summary>One issued device-identity JWT (architecture/17 §3). <see cref="TokenId"/> is the
/// token's <c>jti</c>; a request bearing a device token whose <c>jti</c> has a
/// <see cref="RevokedAt"/> set is rejected even if the JWT itself has not expired.</summary>
public sealed class DeviceRegistration
{
    public Guid Id { get; set; }

    public Guid DeviceId { get; set; }

    public Guid TokenId { get; set; }

    public Guid? RegisteredByStaffId { get; set; }

    public DateTimeOffset IssuedAt { get; set; }

    public DateTimeOffset ExpiresAt { get; set; }

    public DateTimeOffset? RevokedAt { get; set; }

    public string? RevokedReason { get; set; }

    public DateTimeOffset CreatedAt { get; set; }
}

public sealed class DeviceBinding
{
    public Guid Id { get; set; }

    public Guid DeviceId { get; set; }

    public Guid FacilityUnitId { get; set; }

    public string? OperatingPointLabel { get; set; }

    public DateTimeOffset BoundAt { get; set; }

    public DateTimeOffset? UnboundAt { get; set; }

    public DateTimeOffset CreatedAt { get; set; }
}
