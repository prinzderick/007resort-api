using System.Security.Claims;
using Microsoft.AspNetCore.Authorization;
using Microsoft.Data.Sqlite;
using Microsoft.EntityFrameworkCore;
using R007.Contracts.Identity;
using R007.Infrastructure.Persistence;
using R007.Infrastructure.Security;
using R007.Modules.Identity.Authorization;
using DomainScopeLevel = R007.Modules.Identity.Domain.ScopeLevel;
using R007.Modules.Identity.Domain;

namespace R007.UnitTests.Identity;

/// <summary>
/// Focused unit-level version of the mandatory denial test (a fuller HTTP-level version lives in
/// R007.IntegrationTests.Identity.PermissionAuthorizationTests): a staff member holding a role
/// named "Manager" whose bundle does not include the requested permission is denied, and the
/// handler never inspects the role's name.
/// </summary>
public sealed class PermissionAuthorizationHandlerTests : IDisposable
{
    private readonly SqliteConnection _connection = new("DataSource=:memory:");
    private readonly R007DbContext _db;

    public PermissionAuthorizationHandlerTests()
    {
        _connection.Open();
        var options = new DbContextOptionsBuilder<R007DbContext>().UseSqlite(_connection).Options;
        _db = new R007DbContext(options);
        _db.Database.EnsureCreated();
    }

    public void Dispose()
    {
        _db.Dispose();
        _connection.Dispose();
    }

    [Fact]
    public async Task Staff_WithRoleNamedManager_ButBundleLacksPermission_IsDenied()
    {
        var staffId = Guid.CreateVersion7();
        var role = new Role { Code = "MANAGER", Name = "Manager", CreatedAt = DateTimeOffset.UtcNow, UpdatedAt = DateTimeOffset.UtcNow };
        var grantedPermission = new Permission { Code = "staff.manage", CreatedAt = DateTimeOffset.UtcNow };
        var deniedPermission = new Permission { Code = "device.register", CreatedAt = DateTimeOffset.UtcNow };
        _db.AddRange(role, grantedPermission, deniedPermission);
        await _db.SaveChangesAsync();

        _db.Set<RolePermission>().Add(new RolePermission { RoleId = role.Id, PermissionId = grantedPermission.Id });
        _db.Set<RoleAssignment>().Add(new RoleAssignment
        {
            Id = Guid.CreateVersion7(),
            StaffId = staffId,
            RoleId = role.Id,
            ScopeLevel = DomainScopeLevel.Site,
            OrganizationId = Guid.CreateVersion7(),
            GrantedAt = DateTimeOffset.UtcNow,
            CreatedAt = DateTimeOffset.UtcNow,
            UpdatedAt = DateTimeOffset.UtcNow,
        });
        await _db.SaveChangesAsync();

        var handler = new PermissionAuthorizationHandler(_db);

        var deniedResult = await Evaluate(handler, staffId, "device.register");
        var allowedResult = await Evaluate(handler, staffId, "staff.manage");

        Assert.False(deniedResult, "Manager's bundle does not include device.register, so this must be denied regardless of the role's name.");
        Assert.True(allowedResult, "Manager's bundle does include staff.manage.");
    }

    [Fact]
    public async Task Staff_WithNoRoleAssignments_IsDeniedEveryPermission()
    {
        var handler = new PermissionAuthorizationHandler(_db);

        var result = await Evaluate(handler, Guid.CreateVersion7(), "audit.view");

        Assert.False(result);
    }

    private static async Task<bool> Evaluate(PermissionAuthorizationHandler handler, Guid staffId, string permissionCode)
    {
        var requirement = new PermissionRequirement(permissionCode, R007.Contracts.Identity.ScopeLevel.FacilityUnit);
        var claims = new ClaimsPrincipal(new ClaimsIdentity([new Claim(R007ClaimTypes.StaffId, staffId.ToString())], "TestAuth"));
        var context = new AuthorizationHandlerContext([requirement], claims, resource: null);

        await handler.HandleAsync(context);

        return context.HasSucceeded;
    }
}
