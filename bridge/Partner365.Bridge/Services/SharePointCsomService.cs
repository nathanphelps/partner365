namespace Partner365.Bridge.Services;

public sealed record SetLabelResult(bool FastPath);

public sealed class LabelConflictException : Exception
{
    public string SiteUrl { get; }
    public string ExistingLabelId { get; }
    public string TargetLabelId { get; }

    public LabelConflictException(string siteUrl, string existing, string target)
        : base($"Site '{siteUrl}' already has label '{existing}'; refusing to overwrite with '{target}'.")
    {
        SiteUrl = siteUrl;
        ExistingLabelId = existing;
        TargetLabelId = target;
    }
}

public sealed class SharePointCsomService
{
    private readonly ICsomOperations _ops;

    public SharePointCsomService(ICsomOperations ops)
    {
        _ops = ops;
    }

    public async Task<SetLabelResult> SetLabelAsync(string siteUrl, string labelId, bool overwrite, CancellationToken ct)
    {
        var current = await _ops.GetSiteLabelAsync(siteUrl, ct);

        if (!string.IsNullOrEmpty(current) && string.Equals(current, labelId, StringComparison.OrdinalIgnoreCase))
        {
            return new SetLabelResult(FastPath: true);
        }

        if (!string.IsNullOrEmpty(current) && !overwrite)
        {
            throw new LabelConflictException(siteUrl, current, labelId);
        }

        await _ops.SetSiteLabelAsync(siteUrl, labelId, ct);
        return new SetLabelResult(FastPath: false);
    }

    public Task<string?> ReadLabelAsync(string siteUrl, CancellationToken ct)
    {
        return _ops.GetSiteLabelAsync(siteUrl, ct);
    }
}
