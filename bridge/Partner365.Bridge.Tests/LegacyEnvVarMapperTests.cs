using Partner365.Bridge.Services;
using Xunit;

namespace Partner365.Bridge.Tests;

public class LegacyEnvVarMapperTests : IDisposable
{
    private readonly List<string> _touchedKeys = new();

    private void Set(string key, string? value)
    {
        _touchedKeys.Add(key);
        Environment.SetEnvironmentVariable(key, value);
    }

    public void Dispose()
    {
        foreach (var k in _touchedKeys) Environment.SetEnvironmentVariable(k, null);
    }

    [Theory]
    [InlineData("BRIDGE_CLOUD_ENVIRONMENT", "Bridge__CloudEnvironment", "gcc_high")]
    [InlineData("BRIDGE_TENANT_ID", "Bridge__TenantId", "t")]
    [InlineData("BRIDGE_CLIENT_ID", "Bridge__ClientId", "c")]
    [InlineData("BRIDGE_ADMIN_SITE_URL", "Bridge__AdminSiteUrl", "u")]
    [InlineData("BRIDGE_CERT_PATH", "Bridge__CertPath", "p")]
    [InlineData("BRIDGE_CERT_PASSWORD", "Bridge__CertPassword", "pw")]
    [InlineData("BRIDGE_CERT_THUMBPRINT", "Bridge__CertThumbprint", "abcd")]
    [InlineData("BRIDGE_SHARED_SECRET", "Bridge__SharedSecret", "s")]
    public void Maps_flat_env_var_to_double_underscore_form(string flat, string mapped, string value)
    {
        Set(flat, value);
        Set(mapped, null);

        LegacyEnvVarMapper.Apply();

        Assert.Equal(value, Environment.GetEnvironmentVariable(mapped));
    }

    [Fact]
    public void Does_not_overwrite_existing_double_underscore_form()
    {
        Set("BRIDGE_TENANT_ID", "flat-value");
        Set("Bridge__TenantId", "explicit-value");

        LegacyEnvVarMapper.Apply();

        Assert.Equal("explicit-value", Environment.GetEnvironmentVariable("Bridge__TenantId"));
    }

    [Fact]
    public void Ignores_unset_flat_vars()
    {
        Set("BRIDGE_TENANT_ID", null);
        Set("Bridge__TenantId", null);

        LegacyEnvVarMapper.Apply();

        Assert.Null(Environment.GetEnvironmentVariable("Bridge__TenantId"));
    }

    [Fact]
    public void Ignores_empty_flat_vars()
    {
        Set("BRIDGE_TENANT_ID", "");
        Set("Bridge__TenantId", null);

        LegacyEnvVarMapper.Apply();

        Assert.Null(Environment.GetEnvironmentVariable("Bridge__TenantId"));
    }
}
