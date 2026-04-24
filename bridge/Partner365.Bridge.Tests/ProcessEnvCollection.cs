using Xunit;

namespace Partner365.Bridge.Tests;

/// <summary>
/// Serializes tests that mutate process-scope environment variables.
/// xUnit runs test classes in parallel by default; `BridgeFactory` and
/// `BridgeValidateOnStartTests` both set/unset `BRIDGE_*` vars, and parallel
/// execution causes racy reads during host startup.
/// </summary>
[CollectionDefinition("ProcessEnv", DisableParallelization = true)]
public sealed class ProcessEnvCollection
{
}
