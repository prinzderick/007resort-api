using System.Net;
using System.Net.Http.Headers;
using System.Net.Http.Json;
using Microsoft.Extensions.DependencyInjection;
using R007.Contracts.Identity;
using R007.Infrastructure.Persistence;
using R007.IntegrationTests.Support;

namespace R007.IntegrationTests.Identity;

public sealed class StaffLoginTests : IAsyncLifetime
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
    public async Task Login_WithCorrectPassword_ReturnsAccessAndRefreshTokens()
    {
        using var client = _factory.CreateClient();

        using var response = await client.PostAsJsonAsync("/api/v1/auth/staff/login",
            new StaffLoginRequest("manager1", "Correct-Horse-Battery-1"));

        Assert.Equal(HttpStatusCode.OK, response.StatusCode);
        var body = await response.Content.ReadFromJsonAsync<StaffLoginResponse>();
        Assert.NotNull(body);
        Assert.False(string.IsNullOrWhiteSpace(body!.AccessToken));
        Assert.False(string.IsNullOrWhiteSpace(body.RefreshToken));
        Assert.True(body.AccessTokenExpiresAt > DateTimeOffset.UtcNow);
    }

    [Fact]
    public async Task Login_WithWrongPassword_ReturnsUnauthorizedProblem()
    {
        using var client = _factory.CreateClient();

        using var response = await client.PostAsJsonAsync("/api/v1/auth/staff/login",
            new StaffLoginRequest("manager1", "totally-wrong-password"));

        Assert.Equal(HttpStatusCode.Unauthorized, response.StatusCode);
        Assert.Equal("application/problem+json", response.Content.Headers.ContentType?.MediaType);
    }

    [Fact]
    public async Task Login_WithUnknownUsername_ReturnsUnauthorizedProblem_NotFoundLeak()
    {
        using var client = _factory.CreateClient();

        using var response = await client.PostAsJsonAsync("/api/v1/auth/staff/login",
            new StaffLoginRequest("no-such-user", "irrelevant"));

        Assert.Equal(HttpStatusCode.Unauthorized, response.StatusCode);
    }

    [Fact]
    public async Task AccessToken_AuthenticatesSubsequentRequest()
    {
        using var client = _factory.CreateClient();

        var login = await (await client.PostAsJsonAsync("/api/v1/auth/staff/login",
            new StaffLoginRequest("manager1", "Correct-Horse-Battery-1")))
            .Content.ReadFromJsonAsync<StaffLoginResponse>();

        client.DefaultRequestHeaders.Authorization = new AuthenticationHeaderValue("Bearer", login!.AccessToken);

        using var stepUp = await client.PostAsJsonAsync("/api/v1/auth/staff/step-up", new StaffStepUpRequest("Correct-Horse-Battery-1"));

        Assert.Equal(HttpStatusCode.OK, stepUp.StatusCode);
        var result = await stepUp.Content.ReadFromJsonAsync<StaffStepUpResponse>();
        Assert.True(result!.Verified);
    }
}
