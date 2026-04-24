using System.Security.Cryptography.X509Certificates;
using Azure.Core;
using Azure.Identity;
using PnP.Framework;

namespace Partner365.Bridge.Services;

public sealed class PnPCsomOperations : ICsomOperations
{
    private readonly CloudEnvironmentConfig _cloud;
    private readonly string _tenantId;
    private readonly string _clientId;
    private readonly string _adminSiteUrl;
    private readonly X509Certificate2 _cert;

    public PnPCsomOperations(
        CloudEnvironmentConfig cloud,
        string tenantId,
        string clientId,
        string adminSiteUrl,
        X509Certificate2 cert)
    {
        _cloud = cloud;
        _tenantId = tenantId;
        _clientId = clientId;
        _adminSiteUrl = adminSiteUrl;
        _cert = cert;
    }

    public async Task<string?> GetSiteLabelAsync(string siteUrl, CancellationToken ct)
    {
        using var ctx = await CreateAdminContextAsync(ct);
        var tenant = new Microsoft.Online.SharePoint.TenantAdministration.Tenant(ctx);
        var props = tenant.GetSitePropertiesByUrl(siteUrl, true);
        ctx.Load(props);
        await ctx.ExecuteQueryAsync();

        var label = props.SensitivityLabel2;
        return string.IsNullOrWhiteSpace(label) ? null : label;
    }

    public async Task SetSiteLabelAsync(string siteUrl, string labelId, CancellationToken ct)
    {
        using var ctx = await CreateAdminContextAsync(ct);
        var tenant = new Microsoft.Online.SharePoint.TenantAdministration.Tenant(ctx);
        var props = tenant.GetSitePropertiesByUrl(siteUrl, true);
        ctx.Load(props);
        await ctx.ExecuteQueryAsync();

        props.SensitivityLabel2 = labelId;
        props.Update();
        await ctx.ExecuteQueryAsync();
    }

    private async Task<Microsoft.SharePoint.Client.ClientContext> CreateAdminContextAsync(CancellationToken ct)
    {
        var credential = new ClientCertificateCredential(
            _tenantId,
            _clientId,
            _cert,
            new ClientCertificateCredentialOptions { AuthorityHost = _cloud.AuthorityHost });

        var token = await credential.GetTokenAsync(
            new TokenRequestContext(new[] { _cloud.CsomResourceScope }),
            ct);

        var auth = new AuthenticationManager();
        return auth.GetAccessTokenContext(_adminSiteUrl, token.Token);
    }
}
