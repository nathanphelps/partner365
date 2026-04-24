using System.Text.Json;
using System.Text.Json.Serialization;
using Microsoft.AspNetCore.Mvc;
using Partner365.Bridge.Models;
using Partner365.Bridge.Services;

var builder = WebApplication.CreateBuilder(args);

// --- Configuration (env vars only) ---
var cloudEnv = RequireEnv("BRIDGE_CLOUD_ENVIRONMENT");
var tenantId = RequireEnv("BRIDGE_TENANT_ID");
var clientId = RequireEnv("BRIDGE_CLIENT_ID");
var adminUrl = RequireEnv("BRIDGE_ADMIN_SITE_URL");
var certPath = RequireEnv("BRIDGE_CERT_PATH");
var certPw = Environment.GetEnvironmentVariable("BRIDGE_CERT_PASSWORD") ?? "";
var secret = RequireEnv("BRIDGE_SHARED_SECRET");

var cloudCfg = CloudEnvironmentConfig.For(cloudEnv, adminUrl);

// BRIDGE_CERT_PATH="__TEST__" is the only sentinel — it skips cert load so that
// BridgeFactory (integration tests) can start the host without a real PFX and then
// inject a mock ICsomOperations. Certificate thumbprint is then reported as null
// in /health responses rather than leaking the sentinel to clients.
System.Security.Cryptography.X509Certificates.X509Certificate2? cert = null;
if (certPath != "__TEST__")
{
    cert = CertificateLoader.LoadFromPfx(certPath, certPw);
}

builder.Services.AddSingleton(cloudCfg);
builder.Services.AddSingleton(_ => new BridgeStartupInfo(
    CloudEnvironmentName: cloudCfg.CloudEnvironmentName,
    CertThumbprint: cert?.Thumbprint));

if (cert is not null)
{
    builder.Services.AddSingleton<ICsomOperations>(
        _ => new PnPCsomOperations(cloudCfg, tenantId, clientId, adminUrl, cert));
}
// else: the test harness (BridgeFactory) injects a mock ICsomOperations.

builder.Services.AddSingleton<SharePointCsomService>();

builder.Services.ConfigureHttpJsonOptions(options =>
{
    options.SerializerOptions.PropertyNamingPolicy = JsonNamingPolicy.CamelCase;
    options.SerializerOptions.DefaultIgnoreCondition = JsonIgnoreCondition.WhenWritingNull;
});

var app = builder.Build();

// --- Middleware: shared secret on all /v1/* ---
var middlewareLogger = app.Services.GetRequiredService<ILoggerFactory>().CreateLogger<SharedSecretMiddleware>();
app.Use(async (ctx, next) =>
{
    var mw = new SharedSecretMiddleware(_ => next(), secret, middlewareLogger);
    await mw.Invoke(ctx);
});

// --- Endpoints ---
app.MapGet("/health", (BridgeStartupInfo info) =>
    Results.Ok(new HealthResponse("ok", info.CloudEnvironmentName, info.CertThumbprint)));

app.MapPost("/v1/sites/label", async (
    [FromQuery] bool? overwrite,
    [FromBody] SetLabelRequest? req,
    SharePointCsomService svc,
    ILogger<Program> log,
    CancellationToken ct) =>
{
    var requestId = Guid.NewGuid().ToString("N");

    if (req is null)
    {
        return BadRequest(requestId, "Request body is required.");
    }

    try
    {
        SiteUrlValidator.EnsureInTenant(req.SiteUrl, adminUrl);
    }
    catch (ArgumentException ex)
    {
        log.LogInformation("{RequestId} set-label rejected: {Message}", requestId, ex.Message);
        return BadRequest(requestId, ex.Message);
    }

    try
    {
        var result = await svc.SetLabelAsync(req.SiteUrl, req.LabelId, overwrite ?? false, ct);
        log.LogInformation("{RequestId} set-label site={Site} fastPath={FastPath}", requestId, req.SiteUrl, result.FastPath);
        return Results.Ok(new SetLabelResponse(req.SiteUrl, req.LabelId, result.FastPath));
    }
    catch (OperationCanceledException)
    {
        // Client disconnected; don't classify as bridge failure.
        throw;
    }
    catch (LabelConflictException ex)
    {
        log.LogInformation("{RequestId} set-label conflict site={Site}", requestId, req.SiteUrl);
        return Results.Json(new ErrorResponse(new ErrorBody("already_labeled", ex.Message, requestId)), statusCode: 409);
    }
    catch (Exception ex)
    {
        var code = ErrorClassifier.Classify(ex);
        // Log at Error: this either classified into a known category (operator action needed)
        // or fell through to "unknown" (author needs a new handler).
        log.LogError(ex, "{RequestId} set-label failed site={Site} code={Code}", requestId, req.SiteUrl, code);
        return Results.Json(new ErrorResponse(new ErrorBody(code, SanitizeMessage(ex), requestId)), statusCode: 502);
    }
});

app.MapPost("/v1/sites/label:read", async (
    [FromBody] ReadLabelRequest? req,
    SharePointCsomService svc,
    ILogger<Program> log,
    CancellationToken ct) =>
{
    var requestId = Guid.NewGuid().ToString("N");

    if (req is null)
    {
        return BadRequest(requestId, "Request body is required.");
    }

    try
    {
        SiteUrlValidator.EnsureInTenant(req.SiteUrl, adminUrl);
    }
    catch (ArgumentException ex)
    {
        log.LogInformation("{RequestId} read-label rejected: {Message}", requestId, ex.Message);
        return BadRequest(requestId, ex.Message);
    }

    try
    {
        var labelId = await svc.ReadLabelAsync(req.SiteUrl, ct);
        log.LogInformation("{RequestId} read-label site={Site} labelId={LabelId}", requestId, req.SiteUrl, labelId ?? "(none)");
        return Results.Ok(new ReadLabelResponse(req.SiteUrl, labelId));
    }
    catch (OperationCanceledException)
    {
        throw;
    }
    catch (Exception ex)
    {
        var code = ErrorClassifier.Classify(ex);
        log.LogError(ex, "{RequestId} read-label failed site={Site} code={Code}", requestId, req.SiteUrl, code);
        return Results.Json(new ErrorResponse(new ErrorBody(code, SanitizeMessage(ex), requestId)), statusCode: 502);
    }
});

static IResult BadRequest(string requestId, string message) =>
    Results.Json(new ErrorResponse(new ErrorBody("bad_request", message, requestId)), statusCode: 400);

// Client-facing error messages drop server-internal details. Full exception info lives only in bridge logs,
// cross-referenced by requestId.
static string SanitizeMessage(Exception ex) =>
    $"Bridge operation failed ({ex.GetType().Name}). See bridge logs.";

app.Run();

static string RequireEnv(string name)
{
    var v = Environment.GetEnvironmentVariable(name);
    if (string.IsNullOrWhiteSpace(v))
    {
        throw new InvalidOperationException($"Required environment variable '{name}' is not set.");
    }
    return v;
}

public sealed record BridgeStartupInfo(string CloudEnvironmentName, string? CertThumbprint);

public partial class Program { }
