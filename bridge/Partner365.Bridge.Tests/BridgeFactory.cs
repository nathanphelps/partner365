using Microsoft.AspNetCore.Hosting;
using Microsoft.AspNetCore.Mvc.Testing;
using Microsoft.Extensions.DependencyInjection;
using Moq;
using Partner365.Bridge.Services;

namespace Partner365.Bridge.Tests;

/// <summary>
/// WebApplicationFactory that sets required env vars before the host boots
/// and replaces <see cref="ICsomOperations"/> with a Moq double.
///
/// Env vars are set in the constructor so they are visible by the time
/// LegacyEnvVarMapper.Apply() runs at the top of Program.cs — before the
/// ConfigurationBuilder reads them into the Bridge options section.
/// </summary>
public sealed class BridgeFactory : WebApplicationFactory<Program>
{
    public Mock<ICsomOperations> Ops { get; } = new();

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
            var descriptor = services.FirstOrDefault(d => d.ServiceType == typeof(ICsomOperations));
            if (descriptor is not null) services.Remove(descriptor);
            services.AddSingleton(Ops.Object);
        });
    }
}
