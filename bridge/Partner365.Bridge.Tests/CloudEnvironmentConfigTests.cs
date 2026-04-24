using Azure.Identity;
using Partner365.Bridge.Services;
using Xunit;

namespace Partner365.Bridge.Tests;

public class CloudEnvironmentConfigTests
{
    [Fact]
    public void Commercial_uses_public_authority()
    {
        var config = CloudEnvironmentConfig.For("commercial", "https://contoso-admin.sharepoint.com");
        Assert.Equal(AzureAuthorityHosts.AzurePublicCloud, config.AuthorityHost);
    }

    [Fact]
    public void GccHigh_uses_government_authority()
    {
        var config = CloudEnvironmentConfig.For("gcc-high", "https://gdotsg-admin.sharepoint.us");
        Assert.Equal(AzureAuthorityHosts.AzureGovernment, config.AuthorityHost);
    }

    [Fact]
    public void Commercial_resource_derives_from_admin_url()
    {
        var config = CloudEnvironmentConfig.For("commercial", "https://contoso-admin.sharepoint.com");
        Assert.Equal("https://contoso.sharepoint.com/.default", config.CsomResourceScope);
    }

    [Fact]
    public void GccHigh_resource_derives_from_admin_url()
    {
        var config = CloudEnvironmentConfig.For("gcc-high", "https://gdotsg-admin.sharepoint.us");
        Assert.Equal("https://gdotsg.sharepoint.us/.default", config.CsomResourceScope);
    }

    [Fact]
    public void Unknown_environment_throws()
    {
        Assert.Throws<ArgumentException>(() =>
            CloudEnvironmentConfig.For("martian-cloud", "https://x-admin.sharepoint.com"));
    }

    [Fact]
    public void Admin_url_without_dash_admin_throws()
    {
        Assert.Throws<ArgumentException>(() =>
            CloudEnvironmentConfig.For("commercial", "https://contoso.sharepoint.com"));
    }
}
