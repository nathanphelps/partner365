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

// Cert load is deferred when running under the integration test harness.
System.Security.Cryptography.X509Certificates.X509Certificate2? cert = null;
if (certPath != "__TEST__")
{
    cert = CertificateLoader.LoadFromPfx(certPath, certPw);
}

builder.Services.AddSingleton(cloudCfg);
builder.Services.AddSingleton(_ => new BridgeStartupInfo(
    CloudEnvironmentName: cloudCfg.CloudEnvironmentName,
    CertThumbprint: cert?.Thumbprint ?? "__TEST__"));

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
app.Use(async (ctx, next) =>
{
    var mw = new SharedSecretMiddleware(_ => next(), secret);
    await mw.Invoke(ctx);
});

// --- Endpoints ---
app.MapGet("/health", (BridgeStartupInfo info) =>
    Results.Ok(new HealthResponse("ok", info.CloudEnvironmentName, info.CertThumbprint)));

app.MapPost("/v1/sites/label", async (
    [FromQuery] bool? overwrite,
    [FromBody] SetLabelRequest req,
    SharePointCsomService svc,
    ILogger<Program> log,
    CancellationToken ct) =>
{
    var requestId = Guid.NewGuid().ToString("N");
    try
    {
        var result = await svc.SetLabelAsync(req.SiteUrl, req.LabelId, overwrite ?? false, ct);
        log.LogInformation("{RequestId} set-label site={Site} fastPath={FastPath}", requestId, req.SiteUrl, result.FastPath);
        return Results.Ok(new SetLabelResponse(req.SiteUrl, req.LabelId, result.FastPath));
    }
    catch (LabelConflictException ex)
    {
        log.LogInformation("{RequestId} set-label conflict site={Site}", requestId, req.SiteUrl);
        return Results.Json(new ErrorResponse(new ErrorBody("already_labeled", ex.Message, requestId)), statusCode: 409);
    }
    catch (Exception ex)
    {
        var code = ErrorClassifier.Classify(ex);
        log.LogWarning(ex, "{RequestId} set-label failed site={Site} code={Code}", requestId, req.SiteUrl, code);
        return Results.Json(new ErrorResponse(new ErrorBody(code, ex.Message, requestId)), statusCode: 502);
    }
});

app.MapPost("/v1/sites/label:read", async (
    [FromBody] ReadLabelRequest req,
    SharePointCsomService svc,
    ILogger<Program> log,
    CancellationToken ct) =>
{
    var requestId = Guid.NewGuid().ToString("N");
    try
    {
        var labelId = await svc.ReadLabelAsync(req.SiteUrl, ct);
        log.LogInformation("{RequestId} read-label site={Site} labelId={LabelId}", requestId, req.SiteUrl, labelId ?? "(none)");
        return Results.Ok(new ReadLabelResponse(req.SiteUrl, labelId));
    }
    catch (Exception ex)
    {
        var code = ErrorClassifier.Classify(ex);
        log.LogWarning(ex, "{RequestId} read-label failed site={Site} code={Code}", requestId, req.SiteUrl, code);
        return Results.Json(new ErrorResponse(new ErrorBody(code, ex.Message, requestId)), statusCode: 502);
    }
});

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

public sealed record BridgeStartupInfo(string CloudEnvironmentName, string CertThumbprint);

public partial class Program { }
