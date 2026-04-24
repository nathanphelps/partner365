using Azure.Identity;

namespace Partner365.Bridge.Services;

/// <summary>
/// Tenant-scoped settings derived at startup from <c>BRIDGE_CLOUD_ENVIRONMENT</c>
/// and <c>BRIDGE_ADMIN_SITE_URL</c>. Construct via <see cref="For"/> only — the
/// factory validates inputs and the constructor is private so there is no other
/// way to produce an instance with unchecked values.
/// </summary>
public sealed record CloudEnvironmentConfig
{
    public Uri AuthorityHost { get; }
    public string CsomResourceScope { get; }
    public string CloudEnvironmentName { get; }

    private CloudEnvironmentConfig(Uri authorityHost, string csomResourceScope, string cloudEnvironmentName)
    {
        AuthorityHost = authorityHost;
        CsomResourceScope = csomResourceScope;
        CloudEnvironmentName = cloudEnvironmentName;
    }

    public static CloudEnvironmentConfig For(string cloudEnvironment, string adminSiteUrl)
    {
        ArgumentException.ThrowIfNullOrWhiteSpace(cloudEnvironment);
        ArgumentException.ThrowIfNullOrWhiteSpace(adminSiteUrl);

        var authority = cloudEnvironment.ToLowerInvariant() switch
        {
            "commercial" => AzureAuthorityHosts.AzurePublicCloud,
            "gcc-high" => AzureAuthorityHosts.AzureGovernment,
            _ => throw new ArgumentException(
                $"Unknown cloud environment '{cloudEnvironment}'. Expected 'commercial' or 'gcc-high'.",
                nameof(cloudEnvironment)),
        };

        var resource = DeriveResourceScope(adminSiteUrl);

        return new CloudEnvironmentConfig(authority, resource, cloudEnvironment.ToLowerInvariant());
    }

    private static string DeriveResourceScope(string adminSiteUrl)
    {
        var uri = new Uri(adminSiteUrl);
        var host = uri.Host;

        if (!host.Contains("-admin.", StringComparison.OrdinalIgnoreCase))
        {
            throw new ArgumentException(
                $"Admin site URL '{adminSiteUrl}' must contain '-admin.' (e.g. 'contoso-admin.sharepoint.com').",
                nameof(adminSiteUrl));
        }

        var tenantHost = host.Replace("-admin.", ".", StringComparison.OrdinalIgnoreCase);
        return $"https://{tenantHost}/.default";
    }
}
