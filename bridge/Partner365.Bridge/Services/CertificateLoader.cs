using System.Security.Cryptography.X509Certificates;

namespace Partner365.Bridge.Services;

public static class CertificateLoader
{
    public static X509Certificate2 Load(BridgeOptions opts)
    {
        // Thumbprint wins if both set — admins consolidating on the Windows cert
        // store shouldn't have to clear CertPath for it to take effect.
        if (!string.IsNullOrWhiteSpace(opts.CertThumbprint))
        {
            return LoadFromStore(opts.CertThumbprint!, StoreLocation.LocalMachine);
        }

        if (!string.IsNullOrWhiteSpace(opts.CertPath))
        {
            return LoadFromPfx(opts.CertPath!, opts.CertPassword ?? "");
        }

        throw new InvalidOperationException(
            "Bridge cert source not configured. Set Bridge:CertThumbprint " +
            "(Windows cert store) or Bridge:CertPath + Bridge:CertPassword (PFX file).");
    }

    public static X509Certificate2 LoadFromPfx(string path, string password)
    {
        if (!File.Exists(path))
        {
            throw new FileNotFoundException($"Certificate PFX not found at '{path}'.", path);
        }

        return X509CertificateLoader.LoadPkcs12FromFile(
            path,
            password,
            X509KeyStorageFlags.MachineKeySet | X509KeyStorageFlags.PersistKeySet | X509KeyStorageFlags.Exportable);
    }

    /// <summary>Test-only overload. Production code always uses LocalMachine.</summary>
    internal static X509Certificate2 LoadFromStoreForTests(string thumbprint, StoreLocation location)
        => LoadFromStore(thumbprint, location);

    private static X509Certificate2 LoadFromStore(string thumbprint, StoreLocation location)
    {
        // MMC's "Copy Thumbprint" paste often includes U+200E (LTR mark) and spaces.
        // Strip non-alphanumerics and upper-case before comparing.
        var normalized = new string(thumbprint.Where(char.IsLetterOrDigit).ToArray())
            .ToUpperInvariant();

        using var store = new X509Store(StoreName.My, location);
        store.Open(OpenFlags.ReadOnly);
        var matches = store.Certificates.Find(
            X509FindType.FindByThumbprint, normalized, validOnly: false);

        if (matches.Count == 0)
        {
            var storeName = location == StoreLocation.LocalMachine ? "LocalMachine\\My" : "CurrentUser\\My";
            throw new InvalidOperationException(
                $"Certificate with thumbprint '{normalized}' not found in {storeName}.");
        }

        var cert = matches[0];
        if (!cert.HasPrivateKey)
        {
            throw new InvalidOperationException(
                $"Certificate '{normalized}' has no private key. Re-import the PFX " +
                "(not just the .cer) and ensure the service account can read the key.");
        }

        return cert;
    }
}
