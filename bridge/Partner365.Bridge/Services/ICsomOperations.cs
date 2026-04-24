namespace Partner365.Bridge.Services;

/// <summary>
/// Thin wrapper around CSOM SharePoint tenant admin operations.
/// Exists so <see cref="SharePointCsomService"/> is unit-testable without a live SharePoint.
/// </summary>
public interface ICsomOperations
{
    Task<string?> GetSiteLabelAsync(string siteUrl, CancellationToken ct);
    Task SetSiteLabelAsync(string siteUrl, string labelId, CancellationToken ct);
}
