using System.ComponentModel.DataAnnotations;

namespace Partner365.Bridge;

public sealed record BridgeOptions
{
    [Required(AllowEmptyStrings = false)]
    public string CloudEnvironment { get; init; } = "";

    [Required(AllowEmptyStrings = false)]
    public string TenantId { get; init; } = "";

    [Required(AllowEmptyStrings = false)]
    public string ClientId { get; init; } = "";

    [Required(AllowEmptyStrings = false)]
    public string AdminSiteUrl { get; init; } = "";

    [Required(AllowEmptyStrings = false)]
    public string SharedSecret { get; init; } = "";

    [Required(AllowEmptyStrings = false)]
    public string ListenUrl { get; init; } = "";

    public string? CertPath { get; init; }
    public string? CertPassword { get; init; }
    public string? CertThumbprint { get; init; }

    /// <summary>
    /// At least one of CertPath or CertThumbprint must be populated.
    /// Separate from DataAnnotations because it's a cross-field rule.
    /// CertPassword is optional even when CertPath is set (PFXs with no password are valid).
    /// </summary>
    public IEnumerable<ValidationResult> ValidateCertSource()
    {
        if (string.IsNullOrWhiteSpace(CertThumbprint) && string.IsNullOrWhiteSpace(CertPath))
        {
            yield return new ValidationResult(
                "Bridge:CertThumbprint (Windows cert store) or Bridge:CertPath (PFX file; CertPassword optional) must be set.",
                new[] { nameof(CertThumbprint), nameof(CertPath) });
        }
    }
}
