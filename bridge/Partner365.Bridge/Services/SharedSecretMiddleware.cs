using System.Security.Cryptography;
using System.Text;
using Microsoft.AspNetCore.Http;
using Microsoft.Extensions.Logging;

namespace Partner365.Bridge.Services;

public sealed class SharedSecretMiddleware
{
    private const string HeaderName = "X-Bridge-Secret";
    private readonly RequestDelegate _next;
    private readonly byte[] _expectedBytes;
    private readonly ILogger<SharedSecretMiddleware>? _logger;

    public SharedSecretMiddleware(RequestDelegate next, string expectedSecret, ILogger<SharedSecretMiddleware>? logger = null)
    {
        _next = next;
        _expectedBytes = Encoding.UTF8.GetBytes(expectedSecret);
        _logger = logger;
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
            // Note: the reject code is deliberately the same for missing vs wrong —
            // avoids disclosing which secret was probed.
            _logger?.LogWarning("Bridge auth rejected (missing header) path={Path} remote={Remote}",
                ctx.Request.Path, ctx.Connection.RemoteIpAddress);
            await Reject(ctx, "missing_secret", "Missing X-Bridge-Secret header.");
            return;
        }

        var providedBytes = Encoding.UTF8.GetBytes(provided.ToString());

        if (providedBytes.Length != _expectedBytes.Length ||
            !CryptographicOperations.FixedTimeEquals(providedBytes, _expectedBytes))
        {
            // Do NOT log the provided value or its length — those leak oracle info for timing attacks.
            _logger?.LogWarning("Bridge auth rejected (wrong secret) path={Path} remote={Remote}",
                ctx.Request.Path, ctx.Connection.RemoteIpAddress);
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
