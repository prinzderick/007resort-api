using R007.Infrastructure.Persistence;
using R007.Infrastructure.Security;
using R007.Modules.Identity.Domain;
using R007.Modules.Organization.Domain;

namespace R007.IntegrationTests.Support;

/// <summary>
/// Minimal, hand-rolled equivalent of the reference-data section of
/// <c>db/migrations/V0001__initial_schema.sql</c> (roles/permissions/bundles), plus one seeded
/// organization/site/staff member per test scenario. Used instead of running the MySQL-dialect
/// migration against SQLite (see <see cref="R007WebApplicationFactory"/> for why).
/// </summary>
public static class TestDataSeeder
{
    public static readonly Guid OrganizationId = Guid.Parse("00000000-0000-7000-8000-000000000001");
    public static readonly Guid SiteId = Guid.Parse("00000000-0000-7000-8000-000000000002");

    public static async Task SeedRolePermissionCatalogAsync(R007DbContext db)
    {
        db.Set<OrganizationEntity>().Add(new OrganizationEntity { Id = OrganizationId, Name = "007 Resort & Spa", CreatedAt = DateTimeOffset.UtcNow, UpdatedAt = DateTimeOffset.UtcNow });
        db.Set<Site>().Add(new Site { Id = SiteId, OrganizationId = OrganizationId, Name = "Main Property", CreatedAt = DateTimeOffset.UtcNow, UpdatedAt = DateTimeOffset.UtcNow });

        var manager = new Role { Code = "MANAGER", Name = "Manager", CreatedAt = DateTimeOffset.UtcNow, UpdatedAt = DateTimeOffset.UtcNow };
        var itAdmin = new Role { Code = "IT_ADMIN", Name = "IT / system administrator", CreatedAt = DateTimeOffset.UtcNow, UpdatedAt = DateTimeOffset.UtcNow };
        var owner = new Role { Code = "OWNER", Name = "Owner / super admin", CreatedAt = DateTimeOffset.UtcNow, UpdatedAt = DateTimeOffset.UtcNow };
        db.Set<Role>().AddRange(manager, itAdmin, owner);

        var deviceRegister = new Permission { Code = "device.register", CreatedAt = DateTimeOffset.UtcNow };
        var auditView = new Permission { Code = "audit.view", CreatedAt = DateTimeOffset.UtcNow };
        var roleAssignmentManage = new Permission { Code = "role_assignment.manage", CreatedAt = DateTimeOffset.UtcNow };
        var staffManage = new Permission { Code = "staff.manage", CreatedAt = DateTimeOffset.UtcNow };
        db.Set<Permission>().AddRange(deviceRegister, auditView, roleAssignmentManage, staffManage);

        await db.SaveChangesAsync();

        // Manager: staff.manage + role_assignment.manage ONLY — deliberately NOT device.register
        // or audit.view, matching architecture/06 §2 (Manager's bundle does not include IT/Admin
        // permissions), so the mandatory "Manager without the permission is denied" test has a
        // real gap to exercise.
        db.Set<RolePermission>().AddRange(
            new RolePermission { RoleId = manager.Id, PermissionId = staffManage.Id },
            new RolePermission { RoleId = manager.Id, PermissionId = roleAssignmentManage.Id },
            new RolePermission { RoleId = itAdmin.Id, PermissionId = deviceRegister.Id },
            new RolePermission { RoleId = itAdmin.Id, PermissionId = auditView.Id },
            new RolePermission { RoleId = owner.Id, PermissionId = deviceRegister.Id },
            new RolePermission { RoleId = owner.Id, PermissionId = auditView.Id },
            new RolePermission { RoleId = owner.Id, PermissionId = roleAssignmentManage.Id },
            new RolePermission { RoleId = owner.Id, PermissionId = staffManage.Id });

        await db.SaveChangesAsync();
    }

    public static async Task<(Staff Staff, UserAccount Account)> SeedStaffWithRoleAsync(
        R007DbContext db, string roleCode, string username, string password)
    {
        var role = db.Set<Role>().Local.FirstOrDefault(r => r.Code == roleCode)
            ?? throw new InvalidOperationException($"Role '{roleCode}' was not seeded. Call SeedRolePermissionCatalogAsync first.");

        var now = DateTimeOffset.UtcNow;
        var staff = new Staff
        {
            Id = Guid.CreateVersion7(),
            OrganizationId = OrganizationId,
            SiteId = SiteId,
            StaffNumber = username,
            FirstName = "Test",
            LastName = roleCode,
            CreatedAt = now,
            UpdatedAt = now,
        };
        db.Set<Staff>().Add(staff);

        var account = new UserAccount
        {
            Id = Guid.CreateVersion7(),
            StaffId = staff.Id,
            Username = username,
            CreatedAt = now,
            UpdatedAt = now,
        };
        db.Set<UserAccount>().Add(account);

        var hasher = new Argon2IdHasher();
        db.Set<Credential>().Add(new Credential
        {
            Id = Guid.CreateVersion7(),
            UserAccountId = account.Id,
            CredentialType = CredentialType.Password,
            CredentialHash = hasher.Hash(password),
            CreatedAt = now,
            UpdatedAt = now,
        });

        db.Set<RoleAssignment>().Add(new RoleAssignment
        {
            Id = Guid.CreateVersion7(),
            StaffId = staff.Id,
            RoleId = role.Id,
            ScopeLevel = ScopeLevel.Site,
            OrganizationId = OrganizationId,
            SiteId = SiteId,
            GrantedAt = now,
            CreatedAt = now,
            UpdatedAt = now,
        });

        await db.SaveChangesAsync();

        return (staff, account);
    }
}
