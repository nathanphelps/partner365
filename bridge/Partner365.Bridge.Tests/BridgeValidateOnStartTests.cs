using Microsoft.AspNetCore.Hosting;
using Microsoft.AspNetCore.Mvc.Testing;
using Microsoft.Extensions.DependencyInjection;
using Microsoft.Extensions.DependencyInjection.Extensions;
using Microsoft.Extensions.Options;
using Moq;
using Partner365.Bridge.Services;
using Xunit;

namespace Partner365.Bridge.Tests;

/// <summary>
/// Boots the bridge host with deliberately-invalid config and asserts the
/// host refuses to start. Defends the 'service fails Start-Service and
/// docker compose up identically on misconfiguration' contract documented
/// in the sidecar setup guide — a regression would mean misconfigs only
/// surface on the first real request, which is the opposite behavior.
/// </summary>
[Collection("ProcessEnv")]
public class BridgeValidateOnStartTests
{
    private sealed class InvalidOptionsFactory : WebApplicationFactory<Program>
    {
        public Action<string, string?>? Before { get; init; }

        private static readonly string[] OwnedEnvKeys =
        {
            "BRIDGE_CLOUD_ENVIRONMENT",
            "BRIDGE_TENANT_ID",
            "BRIDGE_CLIENT_ID",
            "BRIDGE_ADMIN_SITE_URL",
            "BRIDGE_CERT_PATH",
            "BRIDGE_CERT_PASSWORD",
            "BRIDGE_CERT_THUMBPRINT",
            "BRIDGE_SHARED_SECRET",
            "Bridge__CloudEnvironment",
            "Bridge__TenantId",
            "Bridge__ClientId",
            "Bridge__AdminSiteUrl",
            "Bridge__CertPath",
            "Bridge__CertPassword",
            "Bridge__CertThumbprint",
            "Bridge__SharedSecret",
        };

        public InvalidOptionsFactory()
        {
            // Start from a valid baseline identical to BridgeFactory, then let
            // the test knock a single field out via Before to isolate the
            // validation failure it wants to exercise.
            Environment.SetEnvironmentVariable("BRIDGE_CLOUD_ENVIRONMENT", "commercial");
            Environment.SetEnvironmentVariable("BRIDGE_TENANT_ID", "11111111-1111-1111-1111-111111111111");
            Environment.SetEnvironmentVariable("BRIDGE_CLIENT_ID", "22222222-2222-2222-2222-222222222222");
            Environment.SetEnvironmentVariable("BRIDGE_ADMIN_SITE_URL", "https://test-admin.sharepoint.com");
            Environment.SetEnvironmentVariable("BRIDGE_CERT_PATH", "__TEST__");
            Environment.SetEnvironmentVariable("BRIDGE_CERT_PASSWORD", "");
            Environment.SetEnvironmentVariable("BRIDGE_SHARED_SECRET", "unit-test-secret");
        }

        protected override void ConfigureWebHost(IWebHostBuilder builder)
        {
            // Apply test-specific env-var edit BEFORE Program.cs runs. The test
            // factory constructor sets a valid baseline; Before() knocks a
            // single field out to target one validation path at a time.
            Before?.Invoke("Bridge__TenantId", null);
            Before?.Invoke("Bridge__ClientId", null);
            Before?.Invoke("Bridge__SharedSecret", null);
            Before?.Invoke("Bridge__CertPath", null);
            Before?.Invoke("Bridge__CertThumbprint", null);

            builder.ConfigureServices(services =>
            {
                services.RemoveAll<ICsomOperations>();
                services.AddSingleton(new Mock<ICsomOperations>().Object);
            });
        }

        protected override void Dispose(bool disposing)
        {
            if (disposing)
            {
                foreach (var key in OwnedEnvKeys)
                {
                    Environment.SetEnvironmentVariable(key, null);
                }
            }
            base.Dispose(disposing);
        }
    }

    [Fact]
    public void Missing_SharedSecret_fails_host_build()
    {
        using var factory = new InvalidOptionsFactory
        {
            Before = (key, value) =>
            {
                if (key == "Bridge__SharedSecret")
                {
                    Environment.SetEnvironmentVariable("BRIDGE_SHARED_SECRET", null);
                    Environment.SetEnvironmentVariable("Bridge__SharedSecret", null);
                }
            },
        };

        // Creating the client forces the host to start and resolve IOptions<>,
        // which triggers the IValidateOptions<BridgeOptions> path. Missing a
        // [Required] field should throw OptionsValidationException before any
        // request is served.
        var ex = Assert.ThrowsAny<Exception>(() => factory.CreateClient());
        Assert.Contains("SharedSecret", FlattenMessages(ex), StringComparison.OrdinalIgnoreCase);
    }

    [Fact]
    public void Missing_both_cert_sources_fails_host_build()
    {
        using var factory = new InvalidOptionsFactory
        {
            Before = (key, _) =>
            {
                if (key == "Bridge__CertPath" || key == "Bridge__CertThumbprint")
                {
                    Environment.SetEnvironmentVariable("BRIDGE_CERT_PATH", null);
                    Environment.SetEnvironmentVariable("BRIDGE_CERT_THUMBPRINT", null);
                    Environment.SetEnvironmentVariable("Bridge__CertPath", null);
                    Environment.SetEnvironmentVariable("Bridge__CertThumbprint", null);
                }
            },
        };

        var ex = Assert.ThrowsAny<Exception>(() => factory.CreateClient());
        var msg = FlattenMessages(ex);
        Assert.Contains("CertThumbprint", msg, StringComparison.OrdinalIgnoreCase);
        Assert.Contains("CertPath", msg, StringComparison.OrdinalIgnoreCase);
    }

    [Fact]
    public void Missing_TenantId_fails_host_build()
    {
        using var factory = new InvalidOptionsFactory
        {
            Before = (key, _) =>
            {
                if (key == "Bridge__TenantId")
                {
                    Environment.SetEnvironmentVariable("BRIDGE_TENANT_ID", null);
                    Environment.SetEnvironmentVariable("Bridge__TenantId", null);
                }
            },
        };

        var ex = Assert.ThrowsAny<Exception>(() => factory.CreateClient());
        Assert.Contains("TenantId", FlattenMessages(ex), StringComparison.OrdinalIgnoreCase);
    }

    private static string FlattenMessages(Exception ex)
    {
        var parts = new List<string>();
        for (var current = (Exception?)ex; current is not null; current = current.InnerException)
        {
            parts.Add(current.Message);
            if (current is OptionsValidationException ove)
            {
                parts.AddRange(ove.Failures);
            }
        }
        return string.Join(" | ", parts);
    }
}
