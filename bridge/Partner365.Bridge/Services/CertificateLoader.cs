using System.Security.Cryptography.X509Certificates;

namespace Partner365.Bridge.Services;

public static class CertificateLoader
{
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
}
