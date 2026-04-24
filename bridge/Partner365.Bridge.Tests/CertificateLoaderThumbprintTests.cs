using System.Security.Cryptography;
using System.Security.Cryptography.X509Certificates;
using Partner365.Bridge.Services;
using Xunit;

namespace Partner365.Bridge.Tests;

public class CertificateLoaderThumbprintTests : IDisposable
{
    private readonly X509Certificate2 _cert;
    private readonly string _thumbprint;
    private readonly X509Store _store;

    public CertificateLoaderThumbprintTests()
    {
        using var rsa = RSA.Create(2048);
        var req = new CertificateRequest("CN=ThumbprintTest", rsa, HashAlgorithmName.SHA256, RSASignaturePadding.Pkcs1);
        // Persist the key so we get HasPrivateKey=true after re-reading from the store.
        using var created = req.CreateSelfSigned(DateTimeOffset.UtcNow, DateTimeOffset.UtcNow.AddYears(1));
        var pfxBytes = created.Export(X509ContentType.Pfx, "");
        _cert = X509CertificateLoader.LoadPkcs12(
            pfxBytes, "",
            X509KeyStorageFlags.UserKeySet | X509KeyStorageFlags.PersistKeySet | X509KeyStorageFlags.Exportable);

        _thumbprint = _cert.Thumbprint;

        _store = new X509Store(StoreName.My, StoreLocation.CurrentUser);
        _store.Open(OpenFlags.ReadWrite);
        _store.Add(_cert);
    }

    public void Dispose()
    {
        try { _store.Remove(_cert); } catch { /* best effort */ }
        _store.Close();
        _cert.Dispose();
    }

    [Fact]
    public void Finds_cert_by_thumbprint()
    {
        var loaded = CertificateLoader.LoadFromStoreForTests(_thumbprint, StoreLocation.CurrentUser);
        Assert.NotNull(loaded);
        Assert.Equal(_thumbprint, loaded.Thumbprint);
    }

    [Fact]
    public void Normalizes_thumbprint_with_spaces()
    {
        // MMC's "Copy Thumbprint" inserts spaces every 2 chars.
        var spaced = string.Join(" ", Enumerable.Range(0, _thumbprint.Length / 2)
            .Select(i => _thumbprint.Substring(i * 2, 2)));
        var loaded = CertificateLoader.LoadFromStoreForTests(spaced, StoreLocation.CurrentUser);
        Assert.Equal(_thumbprint, loaded.Thumbprint);
    }

    [Fact]
    public void Normalizes_thumbprint_with_U200E_marker()
    {
        // MMC often prepends U+200E (Left-to-Right Mark) when copying.
        var prefixed = "‎" + _thumbprint;
        var loaded = CertificateLoader.LoadFromStoreForTests(prefixed, StoreLocation.CurrentUser);
        Assert.Equal(_thumbprint, loaded.Thumbprint);
    }

    [Fact]
    public void Is_case_insensitive_on_thumbprint()
    {
        var lower = _thumbprint.ToLowerInvariant();
        var loaded = CertificateLoader.LoadFromStoreForTests(lower, StoreLocation.CurrentUser);
        Assert.Equal(_thumbprint, loaded.Thumbprint);
    }

    [Fact]
    public void Throws_when_thumbprint_not_found()
    {
        var ex = Assert.Throws<InvalidOperationException>(() =>
            CertificateLoader.LoadFromStoreForTests(
                "0000000000000000000000000000000000000000",
                StoreLocation.CurrentUser));
        Assert.Contains("not found", ex.Message, StringComparison.OrdinalIgnoreCase);
    }
}
