using System.Reflection;
using NetArchTest.Rules;

namespace R007.UnitTests.Architecture;

/// <summary>
/// Enforces architecture/14-api-module-map.md §1: "another module never queries another
/// module's tables directly — it calls the service [...] a module boundary is enforced by a
/// lightweight architecture test (e.g. NetArchTest)". Concretely: no module's <c>.Domain</c> or
/// <c>.Persistence</c> namespace (its entities and EF Core mappings — the module's own tables)
/// may be referenced from a *different* module's assembly. Everything a module exposes to
/// others lives in its <c>.Application</c> namespace (interfaces) or in <c>R007.Contracts</c>.
/// </summary>
public sealed class ModuleBoundaryTests
{
    private static readonly (string Name, Assembly Assembly)[] ModuleAssemblies =
    [
        ("Organization", typeof(R007.Modules.Organization.OrganizationModule).Assembly),
        ("Identity", typeof(R007.Modules.Identity.IdentityModule).Assembly),
        ("Devices", typeof(R007.Modules.Devices.DevicesModule).Assembly),
        ("Audit", typeof(R007.Modules.Audit.AuditModule).Assembly),
    ];

    public static IEnumerable<object[]> ModulePairs()
    {
        foreach (var owner in ModuleAssemblies)
        {
            foreach (var other in ModuleAssemblies)
            {
                if (owner.Name != other.Name)
                {
                    yield return [owner, other];
                }
            }
        }
    }

    [Theory]
    [MemberData(nameof(ModulePairs))]
    public void Module_DoesNotReferenceAnotherModules_DomainOrPersistenceNamespaces(
        (string Name, Assembly Assembly) owner, (string Name, Assembly Assembly) other)
    {
        var ownerDomainNamespace = $"R007.Modules.{owner.Name}.Domain";
        var ownerPersistenceNamespace = $"R007.Modules.{owner.Name}.Persistence";

        var result = Types.InAssembly(other.Assembly)
            .That()
            .ResideInNamespace($"R007.Modules.{other.Name}")
            .ShouldNot()
            .HaveDependencyOnAny(ownerDomainNamespace, ownerPersistenceNamespace)
            .GetResult();

        Assert.True(result.IsSuccessful,
            $"{other.Name} must not depend on {owner.Name}'s internal namespaces " +
            $"({ownerDomainNamespace}, {ownerPersistenceNamespace}). Offenders: " +
            string.Join(", ", result.FailingTypeNames ?? []));
    }

    [Fact]
    public void EveryModule_HasAnApplicationNamespace()
    {
        foreach (var (name, assembly) in ModuleAssemblies)
        {
            var hasApplicationTypes = Types.InAssembly(assembly)
                .That().ResideInNamespace($"R007.Modules.{name}.Application")
                .GetTypes()
                .Any();

            Assert.True(hasApplicationTypes, $"{name} module has no public Application-layer types.");
        }
    }
}
