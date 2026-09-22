using Microsoft.AspNetCore.Authorization;
using Microsoft.Extensions.DependencyInjection;
using R007.Modules.Devices.Application;
using R007.Modules.Devices.Authorization;

namespace R007.Modules.Devices;

/// <summary>Composition-root entry point for the Devices module (architecture/14 §1).</summary>
public static class DevicesModule
{
    public static IServiceCollection AddDevicesModule(this IServiceCollection services)
    {
        services.AddScoped<IDeviceService, DeviceService>();
        services.AddScoped<IAuthorizationHandler, DeviceIdentityAuthorizationHandler>();
        return services;
    }

    public const string RequireDeviceIdentityPolicy = "device-identity";

    public static AuthorizationOptions AddDeviceIdentityPolicy(this AuthorizationOptions options)
    {
        options.AddPolicy(RequireDeviceIdentityPolicy, policy => policy.AddRequirements(new DeviceIdentityRequirement()));
        return options;
    }
}
