using System.Net;
using System.Net.Http.Json;
using Moq;
using Partner365.Bridge.Models;
using Xunit;

namespace Partner365.Bridge.Tests;

public class LabelEndpointTests : IClassFixture<BridgeFactory>
{
    private readonly BridgeFactory _factory;

    public LabelEndpointTests(BridgeFactory factory) => _factory = factory;

    [Fact]
    public async Task Without_secret_returns_401()
    {
        using var client = _factory.CreateClient();
        var response = await client.GetAsync("/v1/labels");
        Assert.Equal(HttpStatusCode.Unauthorized, response.StatusCode);
    }

    [Fact]
    public async Task With_secret_returns_labels()
    {
        var labels = new LabelsResponse(
            Source: "powershell",
            FetchedAt: DateTimeOffset.UtcNow,
            Labels: new[]
            {
                new BridgeLabel(
                    Id: "label-1",
                    Name: "Confidential",
                    Description: null,
                    Color: "#4472C4",
                    Tooltip: null,
                    Priority: 5,
                    IsActive: true,
                    Parent: null,
                    ContentFormats: new[] { "file", "email" },
                    ProtectionSettings: new BridgeProtectionSettings(
                        EncryptionEnabled: true,
                        WatermarkEnabled: false,
                        HeaderEnabled: false,
                        FooterEnabled: false)),
            });
        _factory.LabelService.Setup(s => s.GetLabelsAsync(It.IsAny<CancellationToken>()))
            .ReturnsAsync(labels);

        using var client = _factory.CreateClient();
        client.DefaultRequestHeaders.Add("X-Bridge-Secret", "unit-test-secret");
        var response = await client.GetAsync("/v1/labels");

        Assert.Equal(HttpStatusCode.OK, response.StatusCode);
        var body = await response.Content.ReadFromJsonAsync<LabelsResponse>();
        Assert.NotNull(body);
        Assert.Single(body!.Labels);
        Assert.Equal("Confidential", body.Labels[0].Name);
        Assert.Equal(new[] { "file", "email" }, body.Labels[0].ContentFormats);
        Assert.True(body.Labels[0].ProtectionSettings.EncryptionEnabled);
    }

    [Fact]
    public async Task Service_throws_classified_upstream_returns_502()
    {
        _factory.LabelService.Setup(s => s.GetLabelsAsync(It.IsAny<CancellationToken>()))
            .ThrowsAsync(new InvalidOperationException("Connect-IPPSSession failed: 401 Unauthorized from Exchange Online"));

        using var client = _factory.CreateClient();
        client.DefaultRequestHeaders.Add("X-Bridge-Secret", "unit-test-secret");
        var response = await client.GetAsync("/v1/labels");

        Assert.Equal(HttpStatusCode.BadGateway, response.StatusCode);
    }

    [Fact]
    public async Task Service_throws_unclassified_returns_500()
    {
        _factory.LabelService.Setup(s => s.GetLabelsAsync(It.IsAny<CancellationToken>()))
            .ThrowsAsync(new InvalidOperationException("Something internal went wrong"));

        using var client = _factory.CreateClient();
        client.DefaultRequestHeaders.Add("X-Bridge-Secret", "unit-test-secret");
        var response = await client.GetAsync("/v1/labels");

        Assert.Equal(HttpStatusCode.InternalServerError, response.StatusCode);
    }
}
