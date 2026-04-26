using System.Management.Automation;

namespace Partner365.Bridge.Services;

/// <summary>
/// Abstracts an in-process PowerShell invocation. Production implementation
/// hosts PowerShell via Microsoft.PowerShell.SDK; tests substitute a fake that
/// returns canned PSObject collections so unit tests do not depend on a real
/// PowerShell host or network access to Exchange Online.
/// </summary>
public interface IPowerShellRunner
{
    /// <summary>
    /// Runs the given pipeline and returns its result objects. The
    /// implementation is responsible for connecting to Exchange Online (or
    /// any other dependency) before invoking <paramref name="command"/>.
    /// Implementations should throw on errors written to the runspace's
    /// error stream so callers do not silently see partial results.
    /// </summary>
    Task<IReadOnlyList<PSObject>> InvokeAsync(
        string command,
        IDictionary<string, object>? parameters = null,
        CancellationToken cancellationToken = default);
}
