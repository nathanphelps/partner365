using System.Text.Json;
using System.Text.Json.Serialization;
using Microsoft.AspNetCore.Mvc;
using Microsoft.Extensions.Options;
using Partner365.Bridge;
using Partner365.Bridge.Models;
using Partner365.Bridge.Services;

// 1) Bridge flat BRIDGE_* env vars into the Bridge__* form .NET Config expects.
//    Docker admins can keep their compose file as-is; no breaking change.
LegacyEnvVarMapper.Apply();

var builder = WebApplication.CreateBuilder(args);

// 2) Windows Service support. No-op when process isn't launched by the SCM —
//    same binary runs as a console on dev boxes and as a container on Linux.
builder.Host.UseWindowsService(o => o.ServiceName = "PartnerBridge");

// 3) Bind + validate BridgeOptions at startup. Missing fields fail `Start-Service`
//    and `docker compose up` identically.
builder.Services
    .AddOptions<BridgeOptions>()
    .Bind(builder.Configuration.GetSection("Bridge"))
    .ValidateDataAnnotations()
    .Validate(
        o => !o.ValidateCertSource().Any(),
        "Bridge:CertPath or Bridge:CertThumbprint must be set (see BridgeOptions.ValidateCertSource).")
    .ValidateOnStart();

// Resolve options for startup-only wiring (cert load, cloud env, listen URL).
// The bound options are also available via IOptions<BridgeOptions> at runtime.
var opts = builder.Configuration.GetSection("Bridge").Get<BridgeOptions>()
    ?? throw new InvalidOperationException("Bridge configuration section missing.");

// 4) Listen URL: explicit ASPNETCORE_URLS (from .NET Host) wins if set;
//    otherwise we use Bridge:ListenUrl from configuration.
if (string.IsNullOrWhiteSpace(Environment.GetEnvironmentVariable("ASPNETCORE_URLS")))
{
    builder.WebHost.UseUrls(opts.ListenUrl);
}

// 5) Cloud environment + cert.
var cloudCfg = CloudEnvironmentConfig.For(opts.CloudEnvironment, opts.AdminSiteUrl);

// The test harness (BridgeFactory) uses CertPath="__TEST__" as a sentinel so
// the host boots without a real cert, then injects a mock ICsomOperations.
// /health reports thumbprint as null rather than leaking the sentinel.
System.Security.Cryptography.X509Certificates.X509Certificate2? cert = null;
if (opts.CertPath != "__TEST__")
{
    cert = CertificateLoader.Load(opts);
}

builder.Services.AddSingleton(cloudCfg);
builder.Services.AddSingleton(_ => new BridgeStartupInfo(
    CloudEnvironmentName: cloudCfg.CloudEnvironmentName,
    CertThumbprint: cert?.Thumbprint));

if (cert is not null)
{
    builder.Services.AddSingleton<ICsomOperations>(
        _ => new PnPCsomOperations(cloudCfg, opts.TenantId, opts.ClientId, opts.AdminSiteUrl, cert));
}
// else: the test harness injects a mock ICsomOperations.

builder.Services.AddSingleton<SharePointCsomService>();

builder.Services.ConfigureHttpJsonOptions(jsonOpts =>
{
    jsonOpts.SerializerOptions.PropertyNamingPolicy = JsonNamingPolicy.CamelCase;
    jsonOpts.SerializerOptions.DefaultIgnoreCondition = JsonIgnoreCondition.WhenWritingNull;
});

var app = builder.Build();

// 6) Shared-secret middleware. Resolve the final SharedSecret through IOptions so
//    the tests (which rebind via the test host) see the same value.
var secret = app.Services.GetRequiredService<IOptions<BridgeOptions>>().Value.SharedSecret;
var middlewareLogger = app.Services.GetRequiredService<ILoggerFactory>().CreateLogger<SharedSecretMiddleware>();
app.Use(async (ctx, next) =>
{
    var mw = new SharedSecretMiddleware(_ => next(), secret, middlewareLogger);
    await mw.Invoke(ctx);
});

// 7) Endpoints.
app.MapGet("/health", (BridgeStartupInfo info) =>
    Results.Ok(new HealthResponse("ok", info.CloudEnvironmentName, info.CertThumbprint)));

app.MapPost("/v1/sites/label", async (
    [FromQuery] bool? overwrite,
    [FromBody] SetLabelRequest? req,
    SharePointCsomService svc,
    IOptions<BridgeOptions> bridgeOpts,
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
        SiteUrlValidator.EnsureInTenant(req.SiteUrl, bridgeOpts.Value.AdminSiteUrl);
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
        log.LogError(ex, "{RequestId} set-label failed site={Site} code={Code}", requestId, req.SiteUrl, code);
        return Results.Json(new ErrorResponse(new ErrorBody(code, SanitizeMessage(ex), requestId)), statusCode: 502);
    }
});

app.MapPost("/v1/sites/label:read", async (
    [FromBody] ReadLabelRequest? req,
    SharePointCsomService svc,
    IOptions<BridgeOptions> bridgeOpts,
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
        SiteUrlValidator.EnsureInTenant(req.SiteUrl, bridgeOpts.Value.AdminSiteUrl);
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

app.Run();

static IResult BadRequest(string requestId, string message) =>
    Results.Json(new ErrorResponse(new ErrorBody("bad_request", message, requestId)), statusCode: 400);

static string SanitizeMessage(Exception ex) =>
    $"Bridge operation failed ({ex.GetType().Name}). See bridge logs.";

public sealed record BridgeStartupInfo(string CloudEnvironmentName, string? CertThumbprint);

public partial class Program { }
