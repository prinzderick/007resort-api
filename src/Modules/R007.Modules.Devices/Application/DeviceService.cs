using System.Text.Json;
using Microsoft.EntityFrameworkCore;
using R007.Contracts.Audit;
using R007.Contracts.Devices;
using R007.Infrastructure.Persistence;
using R007.Infrastructure.Security;
using R007.Modules.Audit.Application;
using R007.Modules.Devices.Domain;
using R007.SharedKernel.Results;
using R007.SharedKernel.Time;

namespace R007.Modules.Devices.Application;

public sealed class DeviceService(R007DbContext db, JwtTokenService jwtTokenService, IAuditWriter auditWriter, IClock clock) : IDeviceService
{
    public static readonly Error NotFound = new("devices.not_found", "Device was not found.");
    public static readonly Error InvalidType = new("devices.invalid_type", "Unknown device type.");

    public async Task<Result<DeviceRegisterResponse>> RegisterAsync(DeviceRegisterRequest request, Guid? registeredByStaffId, CancellationToken cancellationToken = default)
    {
        if (!Enum.TryParse<DeviceType>(request.DeviceType, ignoreCase: true, out var deviceType))
        {
            return Result.Failure<DeviceRegisterResponse>(InvalidType);
        }

        await using var transaction = await db.Database.BeginTransactionAsync(cancellationToken);

        var now = clock.UtcNow;
        var device = new Device
        {
            Id = Guid.CreateVersion7(),
            OrganizationId = request.OrganizationId,
            SiteId = request.SiteId,
            FacilityUnitId = request.FacilityUnitId,
            DeviceType = deviceType,
            Name = request.Name,
            CreatedAt = now,
            UpdatedAt = now,
        };
        db.Set<Device>().Add(device);

        var tokenId = Guid.CreateVersion7();
        var deviceToken = jwtTokenService.IssueDeviceToken(device.Id, tokenId);

        db.Set<DeviceRegistration>().Add(new DeviceRegistration
        {
            Id = Guid.CreateVersion7(),
            DeviceId = device.Id,
            TokenId = tokenId,
            RegisteredByStaffId = registeredByStaffId,
            IssuedAt = now,
            ExpiresAt = deviceToken.ExpiresAt,
            CreatedAt = now,
        });

        await auditWriter.RecordAsync(new AuditEntry(
            OrganizationId: request.OrganizationId,
            SiteId: request.SiteId,
            Action: "device.register",
            EntityType: "Device",
            EntityId: device.Id,
            ActorStaffId: registeredByStaffId,
            FacilityUnitId: request.FacilityUnitId,
            DeviceId: device.Id,
            NewValueJson: JsonSerializer.Serialize(new { device.Id, device.Name, DeviceType = request.DeviceType })), cancellationToken);

        await db.SaveChangesAsync(cancellationToken);
        await transaction.CommitAsync(cancellationToken);

        return Result.Success(new DeviceRegisterResponse(device.Id, deviceToken.Token, deviceToken.ExpiresAt));
    }

    public async Task<Result<DeviceResponse>> GetAsync(Guid deviceId, CancellationToken cancellationToken = default)
    {
        var device = await db.Set<Device>().FirstOrDefaultAsync(d => d.Id == deviceId, cancellationToken);
        if (device is null)
        {
            return Result.Failure<DeviceResponse>(NotFound);
        }

        return Result.Success(new DeviceResponse(
            device.Id, device.OrganizationId, device.SiteId, device.FacilityUnitId,
            device.DeviceType.ToString(), device.Name, device.IsActive, device.IsRevoked));
    }

    public async Task<bool> IsRegistrationActiveAsync(Guid deviceId, Guid tokenId, CancellationToken cancellationToken = default)
    {
        var now = clock.UtcNow;
        return await db.Set<DeviceRegistration>().AnyAsync(
            r => r.DeviceId == deviceId && r.TokenId == tokenId && r.RevokedAt == null && r.ExpiresAt > now,
            cancellationToken);
    }
}
