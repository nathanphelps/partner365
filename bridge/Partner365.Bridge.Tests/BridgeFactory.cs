using Microsoft.AspNetCore.Hosting;
using Microsoft.AspNetCore.Mvc.Testing;
using Microsoft.Extensions.DependencyInjection;
using Microsoft.Extensions.DependencyInjection.Extensions;
using Moq;
using Partner365.Bridge.Models;
using Partner365.Bridge.Services;

namespace Partner365.Bridge.Tests;

/// <summary>
/// WebApplicationFactory that sets required env vars before the host boots
/// and replaces <see cref="ICsomOperations"/> with a Moq double.
///
/// Env vars are set in the constructor so they are visible by the time
/// LegacyEnvVarMapper.Apply() runs at the top of Program.cs — before the
/// ConfigurationBuilder reads them into the Bridge options section.
///
/// Dispose nulls every env var the factory touched (including the
/// Bridge__* form that LegacyEnvVarMapper populates) so process-scope
/// env vars don't leak into sibling test classes in the same test run.
/// </summary>
public sealed class BridgeFactory : WebApplicationFactory<Program>
{
    public Mock<ICsomOperations> Ops { get; } = new();
    public Mock<ILabelEnumerationService> LabelService { get; } = new();

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

    public BridgeFactory()
    {
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
        builder.ConfigureServices(services =>
        {
            // Use RemoveAll so if Program.cs ever registers multiple ICsomOperations
            // (e.g., conditional fallback registration), all are cleared before we
            // inject the mock.
            services.RemoveAll<ICsomOperations>();
            services.AddSingleton(Ops.Object);

            // Replace label-enumeration plumbing with mocks so tests don't try
            // to spin up a real PowerShell host or call Connect-IPPSSession.
            services.RemoveAll<ILabelEnumerationService>();
            services.AddSingleton(LabelService.Object);
            services.RemoveAll<IPowerShellRunner>();
            services.AddSingleton(Mock.Of<IPowerShellRunner>());
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
