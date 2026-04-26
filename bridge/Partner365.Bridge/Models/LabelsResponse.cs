namespace Partner365.Bridge.Models;

public sealed record LabelsResponse(
    string Source,
    DateTimeOffset FetchedAt,
    IReadOnlyList<BridgeLabel> Labels);
