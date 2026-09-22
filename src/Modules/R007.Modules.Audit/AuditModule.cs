using Microsoft.Extensions.DependencyInjection;
using R007.Modules.Audit.Application;

namespace R007.Modules.Audit;

/// <summary>Composition-root entry point for the Audit module (architecture/14 §1).</summary>
public static class AuditModule
{
    public static IServiceCollection AddAuditModule(this IServiceCollection services)
    {
        services.AddScoped<IAuditWriter, AuditWriter>();
        services.AddScoped<IAuditReader, AuditReader>();
        return services;
    }
}
