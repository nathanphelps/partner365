using Partner365.Bridge.Services;
using Xunit;

namespace Partner365.Bridge.Tests;

public class ErrorClassifierTests
{
    [Theory]
    [InlineData("401 Unauthorized", "auth")]
    [InlineData("403 Forbidden", "auth")]
    [InlineData("Access is forbidden", "auth")]
    [InlineData("user is unauthorized", "auth")]
    [InlineData("429 Too Many Requests", "throttle")]
    [InlineData("Request was throttled", "throttle")]
    [InlineData("The operation has timed out", "network")]
    [InlineData("A connection attempt failed", "network")]
    [InlineData("Certificate validation failed", "certificate")]
    [InlineData("Unable to load private key", "certificate")]
    [InlineData("Something totally weird happened", "unknown")]
    public void Classifies_message_into_error_code(string message, string expected)
    {
        var ex = new InvalidOperationException(message);
        Assert.Equal(expected, ErrorClassifier.Classify(ex));
    }

    [Fact]
    public void HttpRequestException_401_classifies_as_auth()
    {
        var ex = new HttpRequestException("401 Unauthorized", null, System.Net.HttpStatusCode.Unauthorized);
        Assert.Equal("auth", ErrorClassifier.Classify(ex));
    }

    [Fact]
    public void HttpRequestException_429_classifies_as_throttle()
    {
        var ex = new HttpRequestException("Too many requests", null, System.Net.HttpStatusCode.TooManyRequests);
        Assert.Equal("throttle", ErrorClassifier.Classify(ex));
    }
}
