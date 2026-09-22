using System.Reflection;
using Microsoft.EntityFrameworkCore;

namespace R007.Infrastructure.Persistence;

/// <summary>
/// The single physical EF Core <see cref="DbContext"/> shared by every module, matching the
/// "one deployable, many internally isolated modules" model (architecture/14-api-module-map.md
/// §1: "another module never queries another module's tables directly").
///
/// Each module owns its entity classes and <see cref="Microsoft.EntityFrameworkCore.IEntityTypeConfiguration{TEntity}"/>
/// mappings inside its own project (<c>src/Modules/R007.Modules.*</c>), never referencing this
/// type's concrete DbSets from another module's code. To avoid a compile-time circular
/// reference (modules would need to reference Infrastructure for the DbContext type, and
/// Infrastructure would need to reference every module to apply its configurations),
/// <see cref="OnModelCreating"/> discovers module assemblies already loaded into the process by
/// the host (<c>R007.Api</c> references every module, so by the time EF Core builds its model,
/// on first use, all module assemblies are loaded) and applies their configurations by
/// convention. Application services access entities via <see cref="DbContext.Set{TEntity}"/>
/// rather than named DbSet properties, so no module needs a property declared here.
/// </summary>
public sealed class R007DbContext(DbContextOptions<R007DbContext> options) : DbContext(options)
{
    /// <summary>Assembly name prefix used to discover module assemblies at model-build time.</summary>
    public const string ModuleAssemblyPrefix = "R007.Modules.";

    protected override void OnModelCreating(ModelBuilder modelBuilder)
    {
        base.OnModelCreating(modelBuilder);

        // This assembly's own configurations (e.g. IdempotencyRecord — a cross-cutting concern
        // shared by every module, not owned by any one of them) are always applied too.
        modelBuilder.ApplyConfigurationsFromAssembly(typeof(R007DbContext).Assembly);

        foreach (var assembly in DiscoverModuleAssemblies())
        {
            modelBuilder.ApplyConfigurationsFromAssembly(assembly);
        }
    }

    private static IEnumerable<Assembly> DiscoverModuleAssemblies() => AppDomain.CurrentDomain
        .GetAssemblies()
        .Where(a => !a.IsDynamic && a.GetName().Name?.StartsWith(ModuleAssemblyPrefix, StringComparison.Ordinal) == true);
}
