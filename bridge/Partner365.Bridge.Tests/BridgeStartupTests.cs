using Microsoft.AspNetCore.Mvc.Testing;
using Moq;
using Partner365.Bridge.Services;
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

    private HttpClient AuthorizedClient()
    {
        var client = _factory.CreateClient();
        client.DefaultRequestHeaders.Add("X-Bridge-Secret", "unit-test-secret");
        return client;
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
    public async Task Health_cert_thumbprint_is_null_in_test_harness()
    {
        var client = _factory.CreateClient();
        var resp = await client.GetAsync("/health");
        var body = await resp.Content.ReadAsStringAsync();
        // Sentinel string must never leak to the wire; field is null in test mode.
        Assert.DoesNotContain("__TEST__", body);
    }

    [Fact]
    public async Task V1_endpoint_requires_shared_secret()
    {
        var client = _factory.CreateClient();
        var resp = await client.PostAsJsonAsync("/v1/sites/label:read",
            new { SiteUrl = "https://a/sites/x" });
        Assert.Equal(HttpStatusCode.Unauthorized, resp.StatusCode);
    }

    [Fact]
    public async Task Cross_tenant_siteUrl_returns_400()
    {
        var client = AuthorizedClient();
        var resp = await client.PostAsJsonAsync("/v1/sites/label",
            new { SiteUrl = "https://other-tenant.sharepoint.com/sites/x", LabelId = "lbl" });
        Assert.Equal(HttpStatusCode.BadRequest, resp.StatusCode);
    }

    [Fact]
    public async Task Missing_body_returns_400()
    {
        var client = AuthorizedClient();
        // Minimal apis return 400 for unbindable body; our explicit null-guard covers the deserialized-null case.
        var resp = await client.PostAsync("/v1/sites/label",
            new StringContent("null", System.Text.Encoding.UTF8, "application/json"));
        Assert.Equal(HttpStatusCode.BadRequest, resp.StatusCode);
    }

    [Fact]
    public async Task Conflict_from_csom_service_returns_409()
    {
        // BRIDGE_ADMIN_SITE_URL = https://test-admin.sharepoint.com, so tenant host is test.sharepoint.com.
        _factory.Ops
            .Setup(o => o.GetSiteLabelAsync("https://test.sharepoint.com/sites/x", It.IsAny<CancellationToken>()))
            .ReturnsAsync("existing-label");

        var client = AuthorizedClient();
        var resp = await client.PostAsJsonAsync("/v1/sites/label",
            new { SiteUrl = "https://test.sharepoint.com/sites/x", LabelId = "target-label" });
        Assert.Equal(HttpStatusCode.Conflict, resp.StatusCode);
    }

    [Fact]
    public async Task Ops_failure_with_401_classifies_as_auth()
    {
        _factory.Ops
            .Setup(o => o.GetSiteLabelAsync(It.IsAny<string>(), It.IsAny<CancellationToken>()))
            .ThrowsAsync(new HttpRequestException("401 Unauthorized", null, HttpStatusCode.Unauthorized));

        var client = AuthorizedClient();
        var resp = await client.PostAsJsonAsync("/v1/sites/label",
            new { SiteUrl = "https://test.sharepoint.com/sites/x", LabelId = "lbl" });
        Assert.Equal(HttpStatusCode.BadGateway, resp.StatusCode);

        var body = await resp.Content.ReadAsStringAsync();
        Assert.Contains("\"code\":\"auth\"", body);
        // ex.Message is NOT echoed verbatim anymore — should see the sanitized "Bridge operation failed" form.
        Assert.Contains("Bridge operation failed", body);
    }

    [Fact]
    public async Task Read_endpoint_returns_label_from_ops()
    {
        _factory.Ops
            .Setup(o => o.GetSiteLabelAsync("https://test.sharepoint.com/sites/x", It.IsAny<CancellationToken>()))
            .ReturnsAsync("label-xyz");

        var client = AuthorizedClient();
        var resp = await client.PostAsJsonAsync("/v1/sites/label:read",
            new { SiteUrl = "https://test.sharepoint.com/sites/x" });
        Assert.Equal(HttpStatusCode.OK, resp.StatusCode);

        var body = await resp.Content.ReadAsStringAsync();
        Assert.Contains("\"labelId\":\"label-xyz\"", body);
    }

    [Fact]
    public async Task Unclassified_ops_failure_returns_500_not_502()
    {
        // NullReferenceException / generic programming bugs have no ErrorClassifier category.
        // They should bucket to 500 "internal_error" so the caller's retry policy doesn't
        // keep hammering the bridge thinking SharePoint is down.
        _factory.Ops
            .Setup(o => o.GetSiteLabelAsync(It.IsAny<string>(), It.IsAny<CancellationToken>()))
            .ThrowsAsync(new NullReferenceException("unexpected null inside ops layer"));

        var client = AuthorizedClient();
        var resp = await client.PostAsJsonAsync("/v1/sites/label",
            new { SiteUrl = "https://test.sharepoint.com/sites/x", LabelId = "lbl" });

        Assert.Equal(HttpStatusCode.InternalServerError, resp.StatusCode);
        var body = await resp.Content.ReadAsStringAsync();
        Assert.Contains("\"code\":\"internal_error\"", body);
    }

    [Fact]
    public async Task ArgumentException_from_ops_returns_400_not_502()
    {
        // Caller-bug exceptions (beyond the SiteUrlValidator path) should also bucket to 400.
        _factory.Ops
            .Setup(o => o.GetSiteLabelAsync(It.IsAny<string>(), It.IsAny<CancellationToken>()))
            .ThrowsAsync(new ArgumentException("labelId must be a GUID"));

        var client = AuthorizedClient();
        var resp = await client.PostAsJsonAsync("/v1/sites/label",
            new { SiteUrl = "https://test.sharepoint.com/sites/x", LabelId = "lbl" });

        Assert.Equal(HttpStatusCode.BadRequest, resp.StatusCode);
    }
}
