using Microsoft.AspNetCore.Hosting;
using Microsoft.AspNetCore.Mvc.Testing;
using Microsoft.Data.Sqlite;
using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.Configuration;
using Microsoft.Extensions.DependencyInjection;
using Microsoft.Extensions.DependencyInjection.Extensions;
using R007.Infrastructure.Persistence;

namespace R007.IntegrationTests.Support;

/// <summary>
/// Test host for the whole API. A real MySQL 8.4 instance was not reachable in this environment
/// (no Docker daemon, no local `mysqld` — see the PR description's "test results" section for
/// details), so this factory swaps the production Pomelo/MySQL <see cref="R007DbContext"/>
/// registration for an in-memory SQLite connection kept open for the fixture's lifetime, and
/// builds the schema from the EF Core model directly (<c>EnsureCreated</c>) rather than through
/// the MySQL-dialect DbUp migration in <c>db/migrations</c> — that migration is exercised
/// separately by manual review of its SQL and by running it against MySQL when available; it is
/// not itself executed by this test suite. None of the C# entity configurations hardcode a
/// MySQL-specific column type, so the same model maps cleanly onto SQLite for behavioural
/// (business-logic, authorization, idempotency, audit-chain) testing.
/// </summary>
public sealed class R007WebApplicationFactory : WebApplicationFactory<Program>
{
    public const string JwtSigningKeyForTests = "integration-test-signing-key-please-do-not-use-in-prod!!";

    private readonly SqliteConnection _connection = new("DataSource=:memory:");

    public R007WebApplicationFactory()
    {
        _connection.Open();
    }

    protected override void ConfigureWebHost(IWebHostBuilder builder)
    {
        builder.UseEnvironment("Testing");

        builder.ConfigureAppConfiguration((_, config) =>
        {
            config.AddInMemoryCollection(new Dictionary<string, string?>
            {
                ["R007:Jwt:SigningKey"] = JwtSigningKeyForTests,
                ["R007:Jwt:Issuer"] = "https://r007.local",
                ["R007:Jwt:StaffAudience"] = "r007-staff",
                ["R007:Jwt:DeviceAudience"] = "r007-device",
                ["R007:DeploymentMode"] = "Site",
            });
        });

        builder.ConfigureServices(services =>
        {
            services.RemoveAll<DbContextOptions<R007DbContext>>();
            services.AddDbContext<R007DbContext>(options => options.UseSqlite(_connection));
        });
    }

    /// <summary>Builds the SQLite schema from the current EF Core model. Call once per test
    /// (or once per fixture, for tests that share state deliberately) before seeding.</summary>
    public async Task InitializeDatabaseAsync()
    {
        await using var scope = Services.CreateAsyncScope();
        var db = scope.ServiceProvider.GetRequiredService<R007DbContext>();
        await db.Database.EnsureCreatedAsync();
    }

    public AsyncServiceScope CreateScope() => Services.CreateAsyncScope();

    protected override void Dispose(bool disposing)
    {
        base.Dispose(disposing);
        if (disposing)
        {
            _connection.Dispose();
        }
    }
}
