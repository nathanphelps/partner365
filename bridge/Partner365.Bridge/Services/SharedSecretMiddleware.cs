using System.Security.Cryptography;
using System.Text;
using Microsoft.AspNetCore.Http;

namespace Partner365.Bridge.Services;

public sealed class SharedSecretMiddleware
{
    private const string HeaderName = "X-Bridge-Secret";
    private readonly RequestDelegate _next;
    private readonly byte[] _expectedBytes;

    public SharedSecretMiddleware(RequestDelegate next, string expectedSecret)
    {
        _next = next;
        _expectedBytes = Encoding.UTF8.GetBytes(expectedSecret);
    }

    public async Task Invoke(HttpContext ctx)
    {
        if (IsExempt(ctx.Request.Path))
        {
            await _next(ctx);
            return;
        }

        if (!ctx.Request.Headers.TryGetValue(HeaderName, out var provided) || provided.Count == 0)
        {
            await Reject(ctx, "missing_secret", "Missing X-Bridge-Secret header.");
            return;
        }

        var providedBytes = Encoding.UTF8.GetBytes(provided.ToString());

        if (providedBytes.Length != _expectedBytes.Length ||
            !CryptographicOperations.FixedTimeEquals(providedBytes, _expectedBytes))
        {
            await Reject(ctx, "missing_secret", "Invalid X-Bridge-Secret header.");
            return;
        }

        await _next(ctx);
    }

    private static bool IsExempt(PathString path) =>
        path.Equals("/health", StringComparison.OrdinalIgnoreCase);

    private static async Task Reject(HttpContext ctx, string code, string message)
    {
        ctx.Response.StatusCode = 401;
        ctx.Response.ContentType = "application/json";
        var escaped = message.Replace("\\", "\\\\").Replace("\"", "\\\"");
        await ctx.Response.WriteAsync(
            "{\"error\":{\"code\":\"" + code + "\",\"message\":\"" + escaped + "\"}}");
    }
}
