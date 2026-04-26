namespace Partner365.Bridge.Models;

public sealed record BridgeLabel(
    string Id,
    string Name,
    string? Description,
    string? Color,
    string? Tooltip,
    int Priority,
    bool IsActive,
    BridgeLabelParent? Parent,
    IReadOnlyList<string> ContentFormats,
    BridgeProtectionSettings ProtectionSettings);

public sealed record BridgeLabelParent(string Id);

public sealed record BridgeProtectionSettings(
    bool EncryptionEnabled,
    bool WatermarkEnabled,
    bool HeaderEnabled,
    bool FooterEnabled);
