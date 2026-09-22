using System.Net;
using System.Net.Http.Headers;
using System.Net.Http.Json;
using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.DependencyInjection;
using R007.Contracts.Devices;
using R007.Contracts.Identity;
using R007.Infrastructure.Idempotency;
using R007.Infrastructure.Persistence;
using R007.IntegrationTests.Support;
using R007.Modules.Devices.Domain;

namespace R007.IntegrationTests.Devices;

public sealed class DeviceRegistrationTests : IAsyncLifetime
{
    private readonly R007WebApplicationFactory _factory = new();

    public async Task InitializeAsync()
    {
        await _factory.InitializeDatabaseAsync();
        await using var scope = _factory.CreateScope();
        var db = scope.ServiceProvider.GetRequiredService<R007DbContext>();
        await TestDataSeeder.SeedRolePermissionCatalogAsync(db);
        await TestDataSeeder.SeedStaffWithRoleAsync(db, "IT_ADMIN", "itadmin1", "Correct-Horse-Battery-2");
    }

    public Task DisposeAsync()
    {
        _factory.Dispose();
        return Task.CompletedTask;
    }

    [Fact]
    public async Task Register_ThenGet_WithStaffAndDeviceToken_Succeeds()
    {
        using var client = _factory.CreateClient();
        var accessToken = await LoginAsync(client, "itadmin1", "Correct-Horse-Battery-2");
        client.DefaultRequestHeaders.Authorization = new AuthenticationHeaderValue("Bearer", accessToken);

        client.DefaultRequestHeaders.Add("Idempotency-Key", Guid.NewGuid().ToString());
        using var registerResponse = await client.PostAsJsonAsync("/api/v1/devices/register", new DeviceRegisterRequest(
            TestDataSeeder.OrganizationId, TestDataSeeder.SiteId, null, "Tablet", "Attendant Tablet 1"));

        Assert.Equal(HttpStatusCode.Created, registerResponse.StatusCode);
        var registered = await registerResponse.Content.ReadFromJsonAsync<DeviceRegisterResponse>();
        Assert.NotNull(registered);
        Assert.False(string.IsNullOrWhiteSpace(registered!.DeviceToken));

        // The one worked example: GET /devices/{id} requires BOTH the staff session
        // (Authorization header, already set above) AND the device-identity token
        // (X-Device-Token header) from this same registration.
        client.DefaultRequestHeaders.Remove("Idempotency-Key");
        var request = new HttpRequestMessage(HttpMethod.Get, $"/api/v1/devices/{registered.DeviceId}");
        request.Headers.Add("X-Device-Token", registered.DeviceToken);
        using var getResponse = await client.SendAsync(request);

        Assert.Equal(HttpStatusCode.OK, getResponse.StatusCode);
    }

    [Fact]
    public async Task Get_WithStaffSessionButNoDeviceToken_IsForbidden()
    {
        using var client = _factory.CreateClient();
        var accessToken = await LoginAsync(client, "itadmin1", "Correct-Horse-Battery-2");
        client.DefaultRequestHeaders.Authorization = new AuthenticationHeaderValue("Bearer", accessToken);

        client.DefaultRequestHeaders.Add("Idempotency-Key", Guid.NewGuid().ToString());
        var registered = await (await client.PostAsJsonAsync("/api/v1/devices/register", new DeviceRegisterRequest(
            TestDataSeeder.OrganizationId, TestDataSeeder.SiteId, null, "Tablet", "Attendant Tablet 2")))
            .Content.ReadFromJsonAsync<DeviceRegisterResponse>();

        client.DefaultRequestHeaders.Remove("Idempotency-Key");
        // No X-Device-Token header at all.
        using var response = await client.GetAsync($"/api/v1/devices/{registered!.DeviceId}");

        Assert.Equal(HttpStatusCode.Forbidden, response.StatusCode);
    }

    [Fact]
    public async Task Register_MissingIdempotencyKey_Returns400()
    {
        using var client = _factory.CreateClient();
        var accessToken = await LoginAsync(client, "itadmin1", "Correct-Horse-Battery-2");
        client.DefaultRequestHeaders.Authorization = new AuthenticationHeaderValue("Bearer", accessToken);

        using var response = await client.PostAsJsonAsync("/api/v1/devices/register", new DeviceRegisterRequest(
            TestDataSeeder.OrganizationId, TestDataSeeder.SiteId, null, "Tablet", "No Key Tablet"));

        Assert.Equal(HttpStatusCode.BadRequest, response.StatusCode);
    }

    [Fact]
    public async Task Register_DuplicateIdempotencyKeyWithSameRequest_ReplaysOriginalResponse_NoDoubleEffect()
    {
        using var client = _factory.CreateClient();
        var accessToken = await LoginAsync(client, "itadmin1", "Correct-Horse-Battery-2");
        client.DefaultRequestHeaders.Authorization = new AuthenticationHeaderValue("Bearer", accessToken);

        var key = Guid.NewGuid().ToString();
        var request = new DeviceRegisterRequest(TestDataSeeder.OrganizationId, TestDataSeeder.SiteId, null, "Kds", "Kitchen KDS 1");

        client.DefaultRequestHeaders.Add("Idempotency-Key", key);
        var first = await (await client.PostAsJsonAsync("/api/v1/devices/register", request))
            .Content.ReadFromJsonAsync<DeviceRegisterResponse>();

        var second = await (await client.PostAsJsonAsync("/api/v1/devices/register", request))
            .Content.ReadFromJsonAsync<DeviceRegisterResponse>();

        // Same response replayed, not a second device created.
        Assert.Equal(first!.DeviceId, second!.DeviceId);
        Assert.Equal(first.DeviceToken, second.DeviceToken);

        await using var scope = _factory.CreateScope();
        var db = scope.ServiceProvider.GetRequiredService<R007DbContext>();
        var deviceCount = await db.Set<Device>().CountAsync(d => d.Name == "Kitchen KDS 1");
        var idempotencyRecordCount = await db.Set<IdempotencyRecord>().CountAsync(r => r.Scope == "device.register" && r.Key == key);

        Assert.Equal(1, deviceCount);
        Assert.Equal(1, idempotencyRecordCount);
    }

    [Fact]
    public async Task Register_DuplicateIdempotencyKeyWithDifferentRequest_Returns409()
    {
        using var client = _factory.CreateClient();
        var accessToken = await LoginAsync(client, "itadmin1", "Correct-Horse-Battery-2");
        client.DefaultRequestHeaders.Authorization = new AuthenticationHeaderValue("Bearer", accessToken);

        var key = Guid.NewGuid().ToString();
        client.DefaultRequestHeaders.Add("Idempotency-Key", key);

        using var first = await client.PostAsJsonAsync("/api/v1/devices/register", new DeviceRegisterRequest(
            TestDataSeeder.OrganizationId, TestDataSeeder.SiteId, null, "Kds", "Kitchen KDS A"));
        Assert.Equal(HttpStatusCode.Created, first.StatusCode);

        using var second = await client.PostAsJsonAsync("/api/v1/devices/register", new DeviceRegisterRequest(
            TestDataSeeder.OrganizationId, TestDataSeeder.SiteId, null, "Kds", "A Completely Different Device"));

        Assert.Equal(HttpStatusCode.Conflict, second.StatusCode);
    }

    private static async Task<string> LoginAsync(HttpClient client, string username, string password)
    {
        var login = await (await client.PostAsJsonAsync("/api/v1/auth/staff/login",
            new StaffLoginRequest(username, password)))
            .Content.ReadFromJsonAsync<StaffLoginResponse>();
        return login!.AccessToken;
    }
}
