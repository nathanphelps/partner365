using System.ComponentModel.DataAnnotations;
using Microsoft.Extensions.Options;

namespace Partner365.Bridge;

/// <summary>
/// Bundles DataAnnotations validation with the cross-field cert-source rule.
/// Registering via <c>IValidateOptions&lt;BridgeOptions&gt;</c> guarantees both
/// rules run together whenever options are validated — no fluent-chain to forget.
/// </summary>
public sealed class BridgeOptionsValidator : IValidateOptions<BridgeOptions>
{
    public ValidateOptionsResult Validate(string? name, BridgeOptions options)
    {
        var failures = new List<string>();

        // DataAnnotations rules on the six [Required] string fields.
        var ctx = new ValidationContext(options);
        var dataAnnotationResults = new List<ValidationResult>();
        Validator.TryValidateObject(options, ctx, dataAnnotationResults, validateAllProperties: true);
        foreach (var r in dataAnnotationResults)
        {
            failures.Add(r.ErrorMessage ?? "Validation failed.");
        }

        // Cross-field: at least one cert source.
        foreach (var r in options.ValidateCertSource())
        {
            failures.Add(r.ErrorMessage ?? "Cert source validation failed.");
        }

        return failures.Count == 0
            ? ValidateOptionsResult.Success
            : ValidateOptionsResult.Fail(failures);
    }
}
