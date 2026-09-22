using System.Net;
using System.Net.Http.Headers;
using System.Net.Http.Json;
using Microsoft.Extensions.DependencyInjection;
using R007.Contracts.Devices;
using R007.Contracts.Identity;
using R007.Infrastructure.Persistence;
using R007.IntegrationTests.Support;

namespace R007.IntegrationTests.Identity;

/// <summary>
/// The task's mandatory negative test: "a staff member with a role named 'Manager' whose role's
/// permission bundle lacks the specific permission is DENIED." Proves the authorization handler
/// checks the permission bundle, never the role's name — a role called "Manager" grants nothing
/// by itself (architecture/06-roles-permissions.md §1).
/// </summary>
public sealed class PermissionAuthorizationTests : IAsyncLifetime
{
    private readonly R007WebApplicationFactory _factory = new();

    public async Task InitializeAsync()
    {
        await _factory.InitializeDatabaseAsync();
        await using var scope = _factory.CreateScope();
        var db = scope.ServiceProvider.GetRequiredService<R007DbContext>();
        await TestDataSeeder.SeedRolePermissionCatalogAsync(db);
        await TestDataSeeder.SeedStaffWithRoleAsync(db, "MANAGER", "manager1", "Correct-Horse-Battery-1");
        await TestDataSeeder.SeedStaffWithRoleAsync(db, "IT_ADMIN", "itadmin1", "Correct-Horse-Battery-2");
    }

    public Task DisposeAsync()
    {
        _factory.Dispose();
        return Task.CompletedTask;
    }

    [Fact]
    public async Task Manager_WithoutDeviceRegisterPermission_IsDeniedOnDeviceRegistration()
    {
        using var client = _factory.CreateClient();
        await AuthenticateAsync(client, "manager1", "Correct-Horse-Battery-1");

        client.DefaultRequestHeaders.Add("Idempotency-Key", Guid.NewGuid().ToString());
        using var response = await client.PostAsJsonAsync("/api/v1/devices/register", new DeviceRegisterRequest(
            TestDataSeeder.OrganizationId, TestDataSeeder.SiteId, null, "Pos", "Front Desk POS 1"));

        // A role literally named "Manager" must not bypass a permission its bundle does not
        // grant — this is the mandatory denial test.
        Assert.Equal(HttpStatusCode.Forbidden, response.StatusCode);
    }

    [Fact]
    public async Task ItAdmin_WithDeviceRegisterPermission_IsAllowedOnDeviceRegistration()
    {
        using var client = _factory.CreateClient();
        await AuthenticateAsync(client, "itadmin1", "Correct-Horse-Battery-2");

        client.DefaultRequestHeaders.Add("Idempotency-Key", Guid.NewGuid().ToString());
        using var response = await client.PostAsJsonAsync("/api/v1/devices/register", new DeviceRegisterRequest(
            TestDataSeeder.OrganizationId, TestDataSeeder.SiteId, null, "Pos", "Front Desk POS 2"));

        Assert.Equal(HttpStatusCode.Created, response.StatusCode);
    }

    [Fact]
    public async Task UnauthenticatedRequest_IsDeniedOnDeviceRegistration()
    {
        using var client = _factory.CreateClient();

        client.DefaultRequestHeaders.Add("Idempotency-Key", Guid.NewGuid().ToString());
        using var response = await client.PostAsJsonAsync("/api/v1/devices/register", new DeviceRegisterRequest(
            TestDataSeeder.OrganizationId, TestDataSeeder.SiteId, null, "Pos", "Unregistered attempt"));

        Assert.Equal(HttpStatusCode.Unauthorized, response.StatusCode);
    }

    private static async Task AuthenticateAsync(HttpClient client, string username, string password)
    {
        var login = await (await client.PostAsJsonAsync("/api/v1/auth/staff/login",
            new StaffLoginRequest(username, password)))
            .Content.ReadFromJsonAsync<StaffLoginResponse>();

        client.DefaultRequestHeaders.Authorization = new AuthenticationHeaderValue("Bearer", login!.AccessToken);
    }
}
