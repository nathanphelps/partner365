using System.Net;

namespace Partner365.Bridge.Services;

public static class ErrorClassifier
{
    public static string Classify(Exception ex)
    {
        if (ex is HttpRequestException httpEx)
        {
            if (httpEx.StatusCode is HttpStatusCode.Unauthorized or HttpStatusCode.Forbidden)
                return "auth";
            if (httpEx.StatusCode is HttpStatusCode.TooManyRequests)
                return "throttle";
        }

        var msg = (ex.Message ?? "").ToLowerInvariant();
        var inner = (ex.InnerException?.Message ?? "").ToLowerInvariant();
        var combined = $"{msg} {inner}";

        if (Contains(combined, "401", "403", "unauthorized", "forbidden"))
            return "auth";
        if (Contains(combined, "429", "throttl"))
            return "throttle";
        if (Contains(combined, "timed out", "connection attempt failed", "connection reset", "socket"))
            return "network";
        if (Contains(combined, "certificate", "privatekey", "private key"))
            return "certificate";

        return "unknown";
    }

    private static bool Contains(string haystack, params string[] needles)
    {
        foreach (var n in needles)
        {
            if (haystack.Contains(n, StringComparison.OrdinalIgnoreCase))
                return true;
        }
        return false;
    }
}
