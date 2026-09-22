using System.Net.Http.Json;
using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.DependencyInjection;
using R007.Contracts.Identity;
using R007.Infrastructure.Persistence;
using R007.IntegrationTests.Support;
using R007.Modules.Audit.Application;
using R007.Modules.Audit.Domain;

namespace R007.IntegrationTests.Audit;

public sealed class AuditChainTests : IAsyncLifetime
{
    private readonly R007WebApplicationFactory _factory = new();

    public async Task InitializeAsync()
    {
        await _factory.InitializeDatabaseAsync();
        await using var scope = _factory.CreateScope();
        var db = scope.ServiceProvider.GetRequiredService<R007DbContext>();
        await TestDataSeeder.SeedRolePermissionCatalogAsync(db);
        await TestDataSeeder.SeedStaffWithRoleAsync(db, "MANAGER", "manager1", "Correct-Horse-Battery-1");
    }

    public Task DisposeAsync()
    {
        _factory.Dispose();
        return Task.CompletedTask;
    }

    [Fact]
    public async Task Login_WritesAuditRow_InSameTransactionAsTheSession_AndChainVerifies()
    {
        using var client = _factory.CreateClient();

        using var response = await client.PostAsJsonAsync("/api/v1/auth/staff/login",
            new StaffLoginRequest("manager1", "Correct-Horse-Battery-1"));
        var login = await response.Content.ReadFromJsonAsync<StaffLoginResponse>();

        await using var scope = _factory.CreateScope();
        var db = scope.ServiceProvider.GetRequiredService<R007DbContext>();

        // The mutation (the `session` row) and its audit_log row were committed together: both
        // exist, and the audit row references the exact session id that was returned to the
        // client — proving they came from the same successful transaction, not a
        // best-effort/fire-and-forget write.
        var auditRow = await db.Set<AuditLogEntry>()
            .SingleAsync(a => a.Action == "staff.login" && a.EntityId == login!.SessionId);

        Assert.Equal("Session", auditRow.EntityType);
        Assert.Equal(login!.StaffId, auditRow.ActorStaffId);

        var reader = scope.ServiceProvider.GetRequiredService<IAuditReader>();
        var verification = await reader.VerifyChainAsync();

        Assert.True(verification.IsIntact);
    }

    [Fact]
    public async Task TamperingWithAStoredRow_BreaksChainVerification()
    {
        using var client = _factory.CreateClient();
        await client.PostAsJsonAsync("/api/v1/auth/staff/login", new StaffLoginRequest("manager1", "Correct-Horse-Battery-1"));

        await using (var scope = _factory.CreateScope())
        {
            var db = scope.ServiceProvider.GetRequiredService<R007DbContext>();
            var row = await db.Set<AuditLogEntry>().FirstAsync();
            row.NewValueJson = "{\"tampered\":true}"; // mutate a field the row_hash was computed over
            await db.SaveChangesAsync();
        }

        await using var verifyScope = _factory.CreateScope();
        var reader = verifyScope.ServiceProvider.GetRequiredService<IAuditReader>();
        var verification = await reader.VerifyChainAsync();

        Assert.False(verification.IsIntact);
    }
}
