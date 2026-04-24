using System.ComponentModel.DataAnnotations;
using Partner365.Bridge;
using Xunit;

namespace Partner365.Bridge.Tests;

public class BridgeOptionsValidationTests
{
    private static BridgeOptions Valid() => new()
    {
        CloudEnvironment = "gcc_high",
        TenantId = "00000000-0000-0000-0000-000000000001",
        ClientId = "00000000-0000-0000-0000-000000000002",
        AdminSiteUrl = "https://contoso-admin.sharepoint.com",
        SharedSecret = "x",
        ListenUrl = "http://127.0.0.1:5300",
        CertPath = "C:/certs/bridge.pfx",
        CertPassword = "",
    };

    private static List<ValidationResult> ValidateAll(BridgeOptions opts)
    {
        var ctx = new ValidationContext(opts);
        var errors = new List<ValidationResult>();
        Validator.TryValidateObject(opts, ctx, errors, validateAllProperties: true);
        errors.AddRange(opts.ValidateCertSource());
        return errors;
    }

    [Fact]
    public void Valid_options_pass_validation()
    {
        Assert.Empty(ValidateAll(Valid()));
    }

    [Fact]
    public void Missing_TenantId_fails()
    {
        var opts = Valid() with { TenantId = "" };
        Assert.Contains(ValidateAll(opts), e => e.MemberNames.Contains(nameof(BridgeOptions.TenantId)));
    }

    [Fact]
    public void Missing_ClientId_fails()
    {
        var opts = Valid() with { ClientId = "" };
        Assert.Contains(ValidateAll(opts), e => e.MemberNames.Contains(nameof(BridgeOptions.ClientId)));
    }

    [Fact]
    public void Missing_SharedSecret_fails()
    {
        var opts = Valid() with { SharedSecret = "" };
        Assert.Contains(ValidateAll(opts), e => e.MemberNames.Contains(nameof(BridgeOptions.SharedSecret)));
    }

    [Fact]
    public void Missing_both_cert_sources_fails()
    {
        var opts = Valid() with { CertPath = null, CertPassword = null, CertThumbprint = null };
        var errors = ValidateAll(opts);
        Assert.Contains(errors, e => e.ErrorMessage != null && e.ErrorMessage.Contains("CertThumbprint"));
    }

    [Fact]
    public void Thumbprint_only_passes()
    {
        var opts = Valid() with { CertPath = null, CertPassword = null, CertThumbprint = "ABCDEF1234" };
        Assert.Empty(ValidateAll(opts));
    }

    [Fact]
    public void Path_only_passes()
    {
        var opts = Valid() with { CertPath = "C:/certs/bridge.pfx", CertPassword = "pw", CertThumbprint = null };
        Assert.Empty(ValidateAll(opts));
    }
}
