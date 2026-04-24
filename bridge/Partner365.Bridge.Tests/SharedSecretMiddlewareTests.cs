using Microsoft.AspNetCore.Http;
using Partner365.Bridge.Services;
using Xunit;

namespace Partner365.Bridge.Tests;

public class SharedSecretMiddlewareTests
{
    private static async Task<int> Invoke(string? headerValue, string expectedSecret, string path = "/v1/sites/label")
    {
        var ctx = new DefaultHttpContext();
        ctx.Request.Path = path;
        if (headerValue is not null)
        {
            ctx.Request.Headers["X-Bridge-Secret"] = headerValue;
        }
        var nextCalled = false;
        var middleware = new SharedSecretMiddleware(_ =>
        {
            nextCalled = true;
            return Task.CompletedTask;
        }, expectedSecret);
        await middleware.Invoke(ctx);
        return nextCalled ? 200 : ctx.Response.StatusCode;
    }

    [Fact]
    public async Task Missing_header_returns_401()
    {
        var status = await Invoke(null, "expected");
        Assert.Equal(401, status);
    }

    [Fact]
    public async Task Wrong_header_returns_401()
    {
        var status = await Invoke("wrong-value", "expected");
        Assert.Equal(401, status);
    }

    [Fact]
    public async Task Correct_header_passes_through()
    {
        var status = await Invoke("expected", "expected");
        Assert.Equal(200, status);
    }

    [Fact]
    public async Task Health_endpoint_is_exempt()
    {
        var status = await Invoke(null, "expected", "/health");
        Assert.Equal(200, status);
    }

    [Theory]
    [InlineData("abc", "abcd")]
    [InlineData("abcd", "abc")]
    public async Task Different_length_does_not_match(string provided, string expected)
    {
        var status = await Invoke(provided, expected);
        Assert.Equal(401, status);
    }

    [Fact]
    public async Task Empty_server_secret_still_rejects_empty_provided()
    {
        // Guard against a misconfigured deployment (BRIDGE_SHARED_SECRET="") accidentally
        // turning the bridge into an unauthenticated service. The middleware must reject
        // an empty provided header even when the expected secret is also empty.
        var status = await Invoke("", "");
        Assert.Equal(401, status);
    }
}
