using Microsoft.AspNetCore.Mvc.Testing;
using System.Net;
using System.Net.Http.Json;
using Xunit;

namespace Partner365.Bridge.Tests;

public class BridgeStartupTests : IClassFixture<BridgeFactory>
{
    private readonly BridgeFactory _factory;

    public BridgeStartupTests(BridgeFactory factory)
    {
        _factory = factory;
    }

    [Fact]
    public async Task Health_returns_200_without_secret()
    {
        var client = _factory.CreateClient();
        var resp = await client.GetAsync("/health");
        Assert.Equal(HttpStatusCode.OK, resp.StatusCode);

        var body = await resp.Content.ReadFromJsonAsync<Dictionary<string, object>>();
        Assert.NotNull(body);
        Assert.Equal("ok", body!["status"]!.ToString());
    }

    [Fact]
    public async Task V1_endpoint_requires_shared_secret()
    {
        var client = _factory.CreateClient();
        var resp = await client.PostAsJsonAsync("/v1/sites/label:read",
            new { SiteUrl = "https://a/sites/x" });
        Assert.Equal(HttpStatusCode.Unauthorized, resp.StatusCode);
    }
}
