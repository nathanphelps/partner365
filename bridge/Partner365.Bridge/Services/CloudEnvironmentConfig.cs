using Azure.Identity;

namespace Partner365.Bridge.Services;

public sealed record CloudEnvironmentConfig(Uri AuthorityHost, string CsomResourceScope, string CloudEnvironmentName)
{
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
