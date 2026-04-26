using System.Management.Automation;
using System.Text.Json;
using System.Text.RegularExpressions;
using Microsoft.Extensions.Logging;
using Partner365.Bridge.Models;

namespace Partner365.Bridge.Services;

public sealed class LabelEnumerationService
{
    private static readonly TimeSpan CacheTtl = TimeSpan.FromMinutes(5);

    private readonly IPowerShellRunner _runner;
    private readonly TimeProvider _clock;
    private readonly ILogger<LabelEnumerationService> _log;
    private readonly SemaphoreSlim _gate = new(1, 1);

    private LabelsResponse? _cached;
    private DateTimeOffset _cachedAt;

    public LabelEnumerationService(
        IPowerShellRunner runner,
        TimeProvider clock,
        ILogger<LabelEnumerationService> log)
    {
        _runner = runner;
        _clock = clock;
        _log = log;
    }

    public async Task<LabelsResponse> GetLabelsAsync(CancellationToken ct)
    {
        var now = _clock.GetUtcNow();
        if (_cached is not null && now - _cachedAt < CacheTtl)
        {
            _log.LogDebug("Label cache hit (age={Age}).", now - _cachedAt);
            return _cached;
        }

        await _gate.WaitAsync(ct);
        try
        {
            now = _clock.GetUtcNow();
            if (_cached is not null && now - _cachedAt < CacheTtl)
            {
                return _cached;
            }

            var raw = await _runner.InvokeAsync(
                "Get-Label -IncludeDetailedLabelActions",
                parameters: null,
                cancellationToken: ct);

            var mapped = raw.Select(MapLabel).ToList();
            var response = new LabelsResponse(
                Source: "powershell",
                FetchedAt: now,
                Labels: mapped);

            _cached = response;
            _cachedAt = now;
            return response;
        }
        finally
        {
            _gate.Release();
        }
    }

    private static BridgeLabel MapLabel(PSObject pso)
    {
        string Get(string name) => pso.Properties[name]?.Value?.ToString() ?? string.Empty;
        string? GetOrNull(string name)
        {
            var s = pso.Properties[name]?.Value?.ToString();
            return string.IsNullOrEmpty(s) ? null : s;
        }
        int GetInt(string name)
        {
            var v = pso.Properties[name]?.Value;
            return v switch
            {
                int i => i,
                long l => (int)l,
                string s when int.TryParse(s, out var parsed) => parsed,
                _ => 0,
            };
        }

        var id = Get("ImmutableId");
        if (string.IsNullOrEmpty(id))
        {
            id = Get("Guid");
        }

        var disabled = Get("Disabled");
        var isActive = !string.Equals(disabled, "True", StringComparison.OrdinalIgnoreCase);

        var color = ExtractSettingsValue(Get("Settings"), "color");
        var contentFormats = ParseContentType(Get("ContentType"));
        var protection = ParseLabelActions(Get("LabelActions"));

        var parentId = GetOrNull("ParentId");
        var parent = parentId is null ? null : new BridgeLabelParent(parentId);

        return new BridgeLabel(
            Id: id,
            Name: Get("DisplayName"),
            Description: GetOrNull("Comment"),
            Color: color,
            Tooltip: GetOrNull("Tooltip"),
            Priority: GetInt("Priority"),
            IsActive: isActive,
            Parent: parent,
            ContentFormats: contentFormats,
            ProtectionSettings: protection);
    }

    private static string? ExtractSettingsValue(string settings, string key)
    {
        if (string.IsNullOrEmpty(settings))
        {
            return null;
        }

        var match = SettingsValuePattern(key).Match(settings);
        return match.Success ? match.Groups[1].Value.Trim() : null;
    }

    private static IReadOnlyList<string> ParseContentType(string value)
    {
        // Mirrors CompliancePowerShellService::parseContentType: lowercase
        // substring matching to {file, email, site, group} where
        // "UnifiedGroup" (or just "Group") collapses to "group".
        if (string.IsNullOrWhiteSpace(value))
        {
            return Array.Empty<string>();
        }

        var lower = value.ToLowerInvariant();
        var formats = new List<string>();
        if (lower.Contains("file")) formats.Add("file");
        if (lower.Contains("email")) formats.Add("email");
        if (lower.Contains("site")) formats.Add("site");
        if (lower.Contains("unifiedgroup") || lower.Contains("group")) formats.Add("group");
        return formats;
    }

    private static BridgeProtectionSettings ParseLabelActions(string actionsJson)
    {
        if (string.IsNullOrWhiteSpace(actionsJson) || actionsJson == "[]")
        {
            return new BridgeProtectionSettings(false, false, false, false);
        }

        try
        {
            using var doc = JsonDocument.Parse(actionsJson);
            bool encryption = false, watermark = false, header = false, footer = false;
            foreach (var element in doc.RootElement.EnumerateArray())
            {
                if (!element.TryGetProperty("Type", out var typeProp))
                {
                    continue;
                }
                var type = typeProp.GetString()?.ToLowerInvariant() ?? string.Empty;
                if (type.Contains("encrypt")) encryption = true;
                if (type.Contains("watermark")) watermark = true;
                if (type.Contains("header")) header = true;
                if (type.Contains("footer")) footer = true;
            }
            return new BridgeProtectionSettings(encryption, watermark, header, footer);
        }
        catch (JsonException)
        {
            return new BridgeProtectionSettings(false, false, false, false);
        }
    }

    private static readonly Dictionary<string, Regex> _settingsRegexCache = new();

    private static Regex SettingsValuePattern(string key)
    {
        if (_settingsRegexCache.TryGetValue(key, out var cached))
        {
            return cached;
        }
        var pattern = new Regex(
            $@"\[\s*{Regex.Escape(key)}\s*,\s*([^\]]+)\s*\]",
            RegexOptions.IgnoreCase | RegexOptions.Compiled);
        _settingsRegexCache[key] = pattern;
        return pattern;
    }
}
