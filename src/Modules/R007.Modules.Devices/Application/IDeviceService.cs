using R007.Contracts.Devices;
using R007.SharedKernel.Results;

namespace R007.Modules.Devices.Application;

/// <summary>The Devices module's public entry point (architecture/14 §1).</summary>
public interface IDeviceService
{
    Task<Result<DeviceRegisterResponse>> RegisterAsync(DeviceRegisterRequest request, Guid? registeredByStaffId, CancellationToken cancellationToken = default);

    Task<Result<DeviceResponse>> GetAsync(Guid deviceId, CancellationToken cancellationToken = default);

    /// <summary>True if <paramref name="deviceId"/> has an unrevoked, unexpired registration
    /// matching <paramref name="tokenId"/> — used by the device-identity authentication handler.</summary>
    Task<bool> IsRegistrationActiveAsync(Guid deviceId, Guid tokenId, CancellationToken cancellationToken = default);
}
