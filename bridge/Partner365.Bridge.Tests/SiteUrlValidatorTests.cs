using Partner365.Bridge.Services;
using Xunit;

namespace Partner365.Bridge.Tests;

public class SiteUrlValidatorTests
{
    private const string AdminUrl = "https://contoso-admin.sharepoint.com";
    private const string GccAdminUrl = "https://gdotsg-admin.sharepoint.us";

    [Fact]
    public void Accepts_site_on_tenant_host()
    {
        SiteUrlValidator.EnsureInTenant("https://contoso.sharepoint.com/sites/Finance", AdminUrl);
    }

    [Fact]
    public void Accepts_teams_path_on_tenant_host()
    {
        SiteUrlValidator.EnsureInTenant("https://contoso.sharepoint.com/teams/Engineering", AdminUrl);
    }

    [Fact]
    public void Accepts_gcc_high_site()
    {
        SiteUrlValidator.EnsureInTenant("https://gdotsg.sharepoint.us/sites/Finance", GccAdminUrl);
    }

    [Fact]
    public void Rejects_different_tenant_host()
    {
        var ex = Assert.Throws<ArgumentException>(() =>
            SiteUrlValidator.EnsureInTenant("https://other.sharepoint.com/sites/Finance", AdminUrl));
        Assert.Contains("does not match configured tenant host", ex.Message);
    }

    [Fact]
    public void Rejects_admin_host_itself()
    {
        Assert.Throws<ArgumentException>(() =>
            SiteUrlValidator.EnsureInTenant("https://contoso-admin.sharepoint.com/sites/Finance", AdminUrl));
    }

    [Fact]
    public void Rejects_http_scheme()
    {
        Assert.Throws<ArgumentException>(() =>
            SiteUrlValidator.EnsureInTenant("http://contoso.sharepoint.com/sites/Finance", AdminUrl));
    }

    [Fact]
    public void Rejects_malformed_url()
    {
        Assert.Throws<ArgumentException>(() =>
            SiteUrlValidator.EnsureInTenant("not a url", AdminUrl));
    }

    [Fact]
    public void Rejects_empty_url()
    {
        Assert.Throws<ArgumentException>(() =>
            SiteUrlValidator.EnsureInTenant("", AdminUrl));
    }

    [Fact]
    public void Rejects_gcc_url_when_configured_commercial()
    {
        Assert.Throws<ArgumentException>(() =>
            SiteUrlValidator.EnsureInTenant("https://gdotsg.sharepoint.us/sites/Finance", AdminUrl));
    }
}
