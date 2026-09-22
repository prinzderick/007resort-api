using System.Net;
using System.Net.Http.Json;
using Microsoft.AspNetCore.Mvc.Testing;
using Otueke.Contracts.Platform;

namespace Otueke.IntegrationTests;

public sealed class ApiSmokeTests(WebApplicationFactory<Program> factory)
    : IClassFixture<WebApplicationFactory<Program>>
{
    [Theory]
    [InlineData("/health/live")]
    [InlineData("/health/ready")]
    public async Task HealthEndpoints_ReturnOk(string path)
    {
        using var client = factory.CreateClient();

        using var response = await client.GetAsync(path);

        Assert.Equal(HttpStatusCode.OK, response.StatusCode);
    }

    [Fact]
    public async Task SystemInfo_ReturnsServiceIdentity()
    {
        using var client = factory.CreateClient();

        var info = await client.GetFromJsonAsync<SystemInfoResponse>(
            "/api/v1/system/info");

        Assert.NotNull(info);
        Assert.Equal("otueke-api", info.Service);
        Assert.Equal("Site", info.Mode);
        Assert.False(string.IsNullOrWhiteSpace(info.Version));
    }

    [Fact]
    public async Task UnknownRoute_ReturnsProblemDetails()
    {
        using var client = factory.CreateClient();

        using var response = await client.GetAsync("/api/v1/does-not-exist");

        Assert.Equal(HttpStatusCode.NotFound, response.StatusCode);
        Assert.Equal("application/problem+json", response.Content.Headers.ContentType?.MediaType);
    }
}
