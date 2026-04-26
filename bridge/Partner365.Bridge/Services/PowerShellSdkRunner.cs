using System.Management.Automation;
using System.Management.Automation.Runspaces;
using Microsoft.Extensions.Logging;
using Microsoft.Extensions.Options;

namespace Partner365.Bridge.Services;

/// <summary>
/// Hosts PowerShell in-process via Microsoft.PowerShell.SDK to call
/// Get-Label against Exchange Online's Compliance endpoint. A fresh
/// runspace is created per invocation to avoid long-lived runspace state
/// leaking between calls; the LabelEnumerationService cache prevents
/// per-sync churn.
/// </summary>
public sealed class PowerShellSdkRunner : IPowerShellRunner
{
    private readonly BridgeOptions _opts;
    private readonly ILogger<PowerShellSdkRunner> _log;

    public PowerShellSdkRunner(IOptions<BridgeOptions> opts, ILogger<PowerShellSdkRunner> log)
    {
        _opts = opts.Value;
        _log = log;
    }

    public async Task<IReadOnlyList<PSObject>> InvokeAsync(
        string command,
        IDictionary<string, object>? parameters = null,
        CancellationToken cancellationToken = default)
    {
        return await Task.Run(() => InvokeCore(command, parameters, cancellationToken), cancellationToken);
    }

    private IReadOnlyList<PSObject> InvokeCore(string command, IDictionary<string, object>? parameters, CancellationToken ct)
    {
        var iss = InitialSessionState.CreateDefault2();
        using var runspace = RunspaceFactory.CreateRunspace(iss);
        runspace.Open();

        using var ps = PowerShell.Create();
        ps.Runspace = runspace;
        using var cancellationRegistration = ct.Register(() => ps.Stop());

        ImportExchangeOnlineModule(ps);
        ConnectIPPSSession(ps);

        ps.Commands.Clear();
        ps.AddScript(command);
        if (parameters is not null)
        {
            foreach (var kv in parameters)
            {
                ps.AddParameter(kv.Key, kv.Value);
            }
        }

        var results = ps.Invoke();
        ThrowIfErrors(ps, command);
        return results;
    }

    private static void ImportExchangeOnlineModule(PowerShell ps)
    {
        ps.Commands.Clear();
        ps.AddCommand("Import-Module").AddParameter("Name", "ExchangeOnlineManagement").AddParameter("ErrorAction", "Stop");
        _ = ps.Invoke();
        ThrowIfErrors(ps, "Import-Module ExchangeOnlineManagement");
    }

    private void ConnectIPPSSession(PowerShell ps)
    {
        var environmentName = _opts.CloudEnvironment.ToLowerInvariant().Replace('_', '-') switch
        {
            "gcc-high" => "O365USGovGCCHigh",
            "commercial" => "O365Default",
            var x => throw new InvalidOperationException(
                $"Unsupported cloud environment '{x}' for Connect-IPPSSession. Expected 'commercial' or 'gcc-high'."),
        };

        var orgFqdn = _opts.AdminSiteUrl
            .Replace("https://", string.Empty, StringComparison.OrdinalIgnoreCase)
            .TrimEnd('/');
        if (orgFqdn.Contains("-admin."))
        {
            orgFqdn = orgFqdn.Replace("-admin.", ".", StringComparison.OrdinalIgnoreCase);
        }

        var thumbprint = _opts.CertThumbprint
            ?? throw new InvalidOperationException(
                "Bridge:CertThumbprint is required for label enumeration. Set BRIDGE_CERT_THUMBPRINT pointing to the cert in LocalMachine\\My.");

        ps.Commands.Clear();
        ps.AddCommand("Connect-IPPSSession")
            .AddParameter("AppId", _opts.ClientId)
            .AddParameter("Organization", orgFqdn)
            .AddParameter("CertificateThumbprint", thumbprint)
            .AddParameter("ExchangeEnvironmentName", environmentName)
            .AddParameter("ShowBanner", false)
            .AddParameter("ErrorAction", "Stop");

        _ = ps.Invoke();
        ThrowIfErrors(ps, "Connect-IPPSSession");
    }

    private static void ThrowIfErrors(PowerShell ps, string context)
    {
        if (!ps.HadErrors)
        {
            return;
        }

        var firstError = ps.Streams.Error.Count > 0
            ? ps.Streams.Error[0].ToString()
            : "PowerShell pipeline reported errors but the error stream was empty.";

        throw new InvalidOperationException($"{context} failed: {firstError}");
    }
}
