using Microsoft.Extensions.DependencyInjection;
using R007.Modules.Organization.Application;

namespace R007.Modules.Organization;

/// <summary>Composition-root entry point for the Organization module (architecture/14 §1).</summary>
public static class OrganizationModule
{
    public static IServiceCollection AddOrganizationModule(this IServiceCollection services)
    {
        services.AddScoped<IFacilityDirectory, FacilityDirectory>();
        return services;
    }
}
