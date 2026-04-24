using System.Security.Cryptography;
using System.Security.Cryptography.X509Certificates;
using Partner365.Bridge;
using Partner365.Bridge.Services;
using Xunit;

namespace Partner365.Bridge.Tests;

public class CertificateLoaderTests : IDisposable
{
    private readonly string _tempPfxPath;
    private readonly string _password = "test-pw";

    public CertificateLoaderTests()
    {
        _tempPfxPath = Path.Combine(Path.GetTempPath(), $"bridge-test-{Guid.NewGuid()}.pfx");
        using var rsa = RSA.Create(2048);
        var req = new CertificateRequest("CN=BridgeTest", rsa, HashAlgorithmName.SHA256, RSASignaturePadding.Pkcs1);
        using var cert = req.CreateSelfSigned(DateTimeOffset.UtcNow, DateTimeOffset.UtcNow.AddYears(1));
        File.WriteAllBytes(_tempPfxPath, cert.Export(X509ContentType.Pfx, _password));
    }

    public void Dispose()
    {
        if (File.Exists(_tempPfxPath)) File.Delete(_tempPfxPath);
    }

    [Fact]
    public void Loads_cert_from_valid_pfx()
    {
        var cert = CertificateLoader.LoadFromPfx(_tempPfxPath, _password);
        Assert.NotNull(cert);
        Assert.True(cert.HasPrivateKey);
    }

    [Fact]
    public void Missing_file_throws()
    {
        Assert.Throws<FileNotFoundException>(() =>
            CertificateLoader.LoadFromPfx("/no/such/path.pfx", _password));
    }

    [Fact]
    public void Wrong_password_throws()
    {
        Assert.Throws<CryptographicException>(() =>
            CertificateLoader.LoadFromPfx(_tempPfxPath, "wrong"));
    }

    [Fact]
    public void Empty_password_allowed_when_cert_has_none()
    {
        var path = Path.Combine(Path.GetTempPath(), $"bridge-nopw-{Guid.NewGuid()}.pfx");
        try
        {
            using var rsa = RSA.Create(2048);
            var req = new CertificateRequest("CN=NoPw", rsa, HashAlgorithmName.SHA256, RSASignaturePadding.Pkcs1);
            using var cert = req.CreateSelfSigned(DateTimeOffset.UtcNow, DateTimeOffset.UtcNow.AddYears(1));
            File.WriteAllBytes(path, cert.Export(X509ContentType.Pfx, (string?)null));

            var loaded = CertificateLoader.LoadFromPfx(path, "");
            Assert.NotNull(loaded);
            Assert.True(loaded.HasPrivateKey);
        }
        finally
        {
            if (File.Exists(path)) File.Delete(path);
        }
    }

    [Fact]
    public void Load_throws_when_neither_path_nor_thumbprint_set()
    {
        var opts = new BridgeOptions
        {
            CloudEnvironment = "gcc_high",
            TenantId = "t",
            ClientId = "c",
            AdminSiteUrl = "https://x-admin.sharepoint.com",
            SharedSecret = "s",
            ListenUrl = "http://127.0.0.1:5300",
        };

        var ex = Assert.Throws<InvalidOperationException>(() => CertificateLoader.Load(opts));
        Assert.Contains("cert source not configured", ex.Message, StringComparison.OrdinalIgnoreCase);
    }

    [Fact]
    public void Load_dispatches_to_pfx_when_CertPath_set()
    {
        var opts = new BridgeOptions
        {
            CloudEnvironment = "gcc_high",
            TenantId = "t",
            ClientId = "c",
            AdminSiteUrl = "https://x-admin.sharepoint.com",
            SharedSecret = "s",
            ListenUrl = "http://127.0.0.1:5300",
            CertPath = _tempPfxPath,
            CertPassword = _password,
        };

        var cert = CertificateLoader.Load(opts);
        Assert.NotNull(cert);
        Assert.True(cert.HasPrivateKey);
    }
}
