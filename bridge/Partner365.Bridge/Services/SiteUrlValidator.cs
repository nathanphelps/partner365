namespace Partner365.Bridge.Services;

/// <summary>
/// Validates that a site URL is both well-formed and scoped to the configured tenant.
/// Prevents a compromised caller or stale config from driving CSOM against another tenant
/// that happens to share the same cert auth scope.
/// </summary>
public static class SiteUrlValidator
{
    public static void EnsureInTenant(string siteUrl, string adminSiteUrl)
    {
        if (string.IsNullOrWhiteSpace(siteUrl))
        {
            throw new ArgumentException("siteUrl must be provided.", nameof(siteUrl));
        }

        if (!Uri.TryCreate(siteUrl, UriKind.Absolute, out var siteUri))
        {
            throw new ArgumentException($"siteUrl '{siteUrl}' is not a valid absolute URI.", nameof(siteUrl));
        }

        if (siteUri.Scheme != Uri.UriSchemeHttps)
        {
            throw new ArgumentException($"siteUrl '{siteUrl}' must use https scheme.", nameof(siteUrl));
        }

        var adminUri = new Uri(adminSiteUrl);
        // Strip `-admin.` to derive the tenant-root host (e.g. `contoso-admin.sharepoint.com`
        // -> `contoso.sharepoint.com`). Sites live under that host, not under the admin host.
        var tenantHost = adminUri.Host.Replace("-admin.", ".", StringComparison.OrdinalIgnoreCase);

        if (!string.Equals(siteUri.Host, tenantHost, StringComparison.OrdinalIgnoreCase))
        {
            throw new ArgumentException(
                $"siteUrl host '{siteUri.Host}' does not match configured tenant host '{tenantHost}'.",
                nameof(siteUrl));
        }
    }
}
