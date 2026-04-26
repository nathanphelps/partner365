using System.Management.Automation;
using Microsoft.Extensions.Logging.Abstractions;
using Microsoft.Extensions.Time.Testing;
using Partner365.Bridge.Services;
using Xunit;

namespace Partner365.Bridge.Tests;

public class LabelEnumerationServiceTests
{
    private static PSObject MakeLabel(
        string id = "00000000-0000-0000-0000-000000000001",
        string name = "Confidential",
        string? parentId = null,
        string disabled = "False",
        string contentType = "File, Email",
        string settings = "[color, #4472C4] [isparent, False]",
        string labelActions = "[]")
    {
        var pso = new PSObject();
        pso.Properties.Add(new PSNoteProperty("ImmutableId", id));
        pso.Properties.Add(new PSNoteProperty("DisplayName", name));
        pso.Properties.Add(new PSNoteProperty("Comment", "desc"));
        pso.Properties.Add(new PSNoteProperty("Tooltip", "tip"));
        pso.Properties.Add(new PSNoteProperty("Priority", 5));
        pso.Properties.Add(new PSNoteProperty("ParentId", parentId));
        pso.Properties.Add(new PSNoteProperty("Disabled", disabled));
        pso.Properties.Add(new PSNoteProperty("ContentType", contentType));
        pso.Properties.Add(new PSNoteProperty("Settings", settings));
        pso.Properties.Add(new PSNoteProperty("LabelActions", labelActions));
        return pso;
    }

    private sealed class FakeRunner : IPowerShellRunner
    {
        public int InvokeCount { get; private set; }
        public Func<IReadOnlyList<PSObject>>? Result { get; set; }
        public Exception? Throw { get; set; }

        public Task<IReadOnlyList<PSObject>> InvokeAsync(string command, IDictionary<string, object>? parameters = null, CancellationToken cancellationToken = default)
        {
            InvokeCount++;
            if (Throw is not null) throw Throw;
            return Task.FromResult(Result?.Invoke() ?? Array.Empty<PSObject>());
        }
    }

    [Fact]
    public async Task Maps_basic_label_fields()
    {
        var runner = new FakeRunner { Result = () => new[] { MakeLabel() } };
        var clock = new FakeTimeProvider(DateTimeOffset.UtcNow);
        var sut = new LabelEnumerationService(runner, clock, NullLogger<LabelEnumerationService>.Instance);

        var response = await sut.GetLabelsAsync(default);

        Assert.Single(response.Labels);
        var label = response.Labels[0];
        Assert.Equal("00000000-0000-0000-0000-000000000001", label.Id);
        Assert.Equal("Confidential", label.Name);
        Assert.Equal("#4472C4", label.Color);
        Assert.Equal(5, label.Priority);
        Assert.Null(label.Parent);
        Assert.True(label.IsActive);
        Assert.Equal(new[] { "file", "email" }, label.ContentFormats);
    }

    [Fact]
    public async Task Maps_parent_id_to_nested_object()
    {
        var runner = new FakeRunner { Result = () => new[] { MakeLabel(parentId: "PARENT-GUID") } };
        var clock = new FakeTimeProvider(DateTimeOffset.UtcNow);
        var sut = new LabelEnumerationService(runner, clock, NullLogger<LabelEnumerationService>.Instance);

        var response = await sut.GetLabelsAsync(default);

        Assert.NotNull(response.Labels[0].Parent);
        Assert.Equal("PARENT-GUID", response.Labels[0].Parent!.Id);
    }

    [Fact]
    public async Task Disabled_True_yields_inactive()
    {
        var runner = new FakeRunner { Result = () => new[] { MakeLabel(disabled: "True") } };
        var clock = new FakeTimeProvider(DateTimeOffset.UtcNow);
        var sut = new LabelEnumerationService(runner, clock, NullLogger<LabelEnumerationService>.Instance);

        var response = await sut.GetLabelsAsync(default);

        Assert.False(response.Labels[0].IsActive);
    }

    [Fact]
    public async Task Encryption_action_sets_encryptionEnabled_true()
    {
        var runner = new FakeRunner
        {
            Result = () => new[] { MakeLabel(labelActions: "[{\"Type\":\"encrypt\"}]") },
        };
        var clock = new FakeTimeProvider(DateTimeOffset.UtcNow);
        var sut = new LabelEnumerationService(runner, clock, NullLogger<LabelEnumerationService>.Instance);

        var response = await sut.GetLabelsAsync(default);

        Assert.True(response.Labels[0].ProtectionSettings.EncryptionEnabled);
        Assert.False(response.Labels[0].ProtectionSettings.WatermarkEnabled);
    }

    [Fact]
    public async Task UnifiedGroup_content_type_maps_to_group()
    {
        var runner = new FakeRunner
        {
            Result = () => new[] { MakeLabel(contentType: "Site, UnifiedGroup") },
        };
        var clock = new FakeTimeProvider(DateTimeOffset.UtcNow);
        var sut = new LabelEnumerationService(runner, clock, NullLogger<LabelEnumerationService>.Instance);

        var response = await sut.GetLabelsAsync(default);

        Assert.Equal(new[] { "site", "group" }, response.Labels[0].ContentFormats);
    }

    [Fact]
    public async Task Cache_hit_within_ttl_does_not_reinvoke_runner()
    {
        var runner = new FakeRunner { Result = () => new[] { MakeLabel() } };
        var clock = new FakeTimeProvider(DateTimeOffset.UtcNow);
        var sut = new LabelEnumerationService(runner, clock, NullLogger<LabelEnumerationService>.Instance);

        await sut.GetLabelsAsync(default);
        clock.Advance(TimeSpan.FromMinutes(4));
        await sut.GetLabelsAsync(default);

        Assert.Equal(1, runner.InvokeCount);
    }

    [Fact]
    public async Task Cache_miss_after_ttl_reinvokes_runner()
    {
        var runner = new FakeRunner { Result = () => new[] { MakeLabel() } };
        var clock = new FakeTimeProvider(DateTimeOffset.UtcNow);
        var sut = new LabelEnumerationService(runner, clock, NullLogger<LabelEnumerationService>.Instance);

        await sut.GetLabelsAsync(default);
        clock.Advance(TimeSpan.FromMinutes(6));
        await sut.GetLabelsAsync(default);

        Assert.Equal(2, runner.InvokeCount);
    }

    [Fact]
    public async Task Runner_exception_propagates_and_does_not_cache()
    {
        var runner = new FakeRunner { Throw = new InvalidOperationException("Connect-IPPSSession failed") };
        var clock = new FakeTimeProvider(DateTimeOffset.UtcNow);
        var sut = new LabelEnumerationService(runner, clock, NullLogger<LabelEnumerationService>.Instance);

        await Assert.ThrowsAsync<InvalidOperationException>(() => sut.GetLabelsAsync(default));
        runner.Throw = null;
        runner.Result = () => new[] { MakeLabel() };

        var response = await sut.GetLabelsAsync(default);

        Assert.Single(response.Labels);
        Assert.Equal(2, runner.InvokeCount);
    }
}
