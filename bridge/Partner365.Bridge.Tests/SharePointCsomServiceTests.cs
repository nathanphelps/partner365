using Moq;
using Partner365.Bridge.Services;
using Xunit;

namespace Partner365.Bridge.Tests;

public class SharePointCsomServiceTests
{
    private static SharePointCsomService MakeService(Mock<ICsomOperations> ops)
    {
        return new SharePointCsomService(ops.Object);
    }

    [Fact]
    public async Task SetLabel_fast_path_when_current_equals_target()
    {
        var ops = new Mock<ICsomOperations>();
        ops.Setup(o => o.GetSiteLabelAsync("https://a/sites/x", It.IsAny<CancellationToken>()))
            .ReturnsAsync("label-1");

        var svc = MakeService(ops);
        var result = await svc.SetLabelAsync("https://a/sites/x", "label-1", overwrite: false, default);

        Assert.True(result.FastPath);
        ops.Verify(o => o.SetSiteLabelAsync(It.IsAny<string>(), It.IsAny<string>(), It.IsAny<CancellationToken>()), Times.Never);
    }

    [Fact]
    public async Task SetLabel_applies_when_unlabeled()
    {
        var ops = new Mock<ICsomOperations>();
        ops.Setup(o => o.GetSiteLabelAsync(It.IsAny<string>(), It.IsAny<CancellationToken>()))
            .ReturnsAsync((string?)null);

        var svc = MakeService(ops);
        var result = await svc.SetLabelAsync("https://a/sites/x", "label-1", overwrite: false, default);

        Assert.False(result.FastPath);
        ops.Verify(o => o.SetSiteLabelAsync("https://a/sites/x", "label-1", It.IsAny<CancellationToken>()), Times.Once);
    }

    [Fact]
    public async Task SetLabel_throws_conflict_when_different_label_and_overwrite_false()
    {
        var ops = new Mock<ICsomOperations>();
        ops.Setup(o => o.GetSiteLabelAsync(It.IsAny<string>(), It.IsAny<CancellationToken>()))
            .ReturnsAsync("different-label");

        var svc = MakeService(ops);
        await Assert.ThrowsAsync<LabelConflictException>(() =>
            svc.SetLabelAsync("https://a/sites/x", "target-label", overwrite: false, default));

        ops.Verify(o => o.SetSiteLabelAsync(It.IsAny<string>(), It.IsAny<string>(), It.IsAny<CancellationToken>()), Times.Never);
    }

    [Fact]
    public async Task SetLabel_applies_when_overwrite_true_and_labels_differ()
    {
        var ops = new Mock<ICsomOperations>();
        ops.Setup(o => o.GetSiteLabelAsync(It.IsAny<string>(), It.IsAny<CancellationToken>()))
            .ReturnsAsync("different-label");

        var svc = MakeService(ops);
        var result = await svc.SetLabelAsync("https://a/sites/x", "target-label", overwrite: true, default);

        Assert.False(result.FastPath);
        ops.Verify(o => o.SetSiteLabelAsync("https://a/sites/x", "target-label", It.IsAny<CancellationToken>()), Times.Once);
    }

    [Fact]
    public async Task ReadLabel_returns_ops_result()
    {
        var ops = new Mock<ICsomOperations>();
        ops.Setup(o => o.GetSiteLabelAsync("https://a/sites/x", It.IsAny<CancellationToken>()))
            .ReturnsAsync("label-xyz");

        var svc = MakeService(ops);
        var result = await svc.ReadLabelAsync("https://a/sites/x", default);

        Assert.Equal("label-xyz", result);
    }

    [Fact]
    public async Task ReadLabel_returns_null_when_unlabeled()
    {
        var ops = new Mock<ICsomOperations>();
        ops.Setup(o => o.GetSiteLabelAsync(It.IsAny<string>(), It.IsAny<CancellationToken>()))
            .ReturnsAsync((string?)null);

        var svc = MakeService(ops);
        var result = await svc.ReadLabelAsync("https://a/sites/x", default);

        Assert.Null(result);
    }
}
