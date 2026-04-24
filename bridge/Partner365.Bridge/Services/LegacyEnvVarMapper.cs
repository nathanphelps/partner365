namespace Partner365.Bridge.Services;

/// <summary>
/// Copies legacy flat <c>BRIDGE_*</c> environment variables into the double-underscore
/// form (<c>Bridge__TenantId</c>) that .NET Configuration reads as nested keys
/// (<c>Bridge:TenantId</c>).
///
/// Purpose: docker-compose.yml already ships with flat names. Rather than force
/// admins to edit their compose file, we alias the flat form at startup.
/// </summary>
public static class LegacyEnvVarMapper
{
    private static readonly (string Flat, string Mapped)[] Aliases =
    {
        ("BRIDGE_CLOUD_ENVIRONMENT", "Bridge__CloudEnvironment"),
        ("BRIDGE_TENANT_ID",         "Bridge__TenantId"),
        ("BRIDGE_CLIENT_ID",         "Bridge__ClientId"),
        ("BRIDGE_ADMIN_SITE_URL",    "Bridge__AdminSiteUrl"),
        ("BRIDGE_CERT_PATH",         "Bridge__CertPath"),
        ("BRIDGE_CERT_PASSWORD",     "Bridge__CertPassword"),
        ("BRIDGE_CERT_THUMBPRINT",   "Bridge__CertThumbprint"),
        ("BRIDGE_SHARED_SECRET",     "Bridge__SharedSecret"),
    };

    public static void Apply()
    {
        foreach (var (flat, mapped) in Aliases)
        {
            var flatValue = Environment.GetEnvironmentVariable(flat);
            if (string.IsNullOrWhiteSpace(flatValue)) continue;

            // Don't clobber an explicit double-underscore override.
            var existing = Environment.GetEnvironmentVariable(mapped);
            if (!string.IsNullOrWhiteSpace(existing)) continue;

            Environment.SetEnvironmentVariable(mapped, flatValue);
        }
    }
}
