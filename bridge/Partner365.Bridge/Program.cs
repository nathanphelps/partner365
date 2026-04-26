using System.Text.Json;
using System.Text.Json.Serialization;
using Microsoft.AspNetCore.Mvc;
using Microsoft.Extensions.Logging.EventLog;
using Microsoft.Extensions.Options;
using Partner365.Bridge;
using Partner365.Bridge.Models;
using Partner365.Bridge.Services;

// Bridge flat BRIDGE_* env vars into the Bridge__* form .NET Config expects.
// Docker admins can keep their compose file as-is; no breaking change.
LegacyEnvVarMapper.Apply();

var builder = WebApplication.CreateBuilder(args);

// Windows Service support. No-op when process isn't launched by the SCM —
// same binary runs as a console on dev boxes and as a container on Linux.
builder.Host.UseWindowsService(o => o.ServiceName = "PartnerBridge");

// Pin the EventLog source name so Windows admins' `Get-WinEvent` filters in
// README.md / validate.md (ProviderName -like '*PartnerBridge*') actually match.
// Without this, the default source is the assembly name (Partner365.Bridge).
// EventLog is Windows-only; the Configure call is a no-op on Linux containers.
#pragma warning disable CA1416 // guarded by OperatingSystem.IsWindows() check
if (OperatingSystem.IsWindows())
{
    builder.Services.Configure<EventLogSettings>(s => s.SourceName = "PartnerBridge");
}
#pragma warning restore CA1416

// Bind BridgeOptions to the Bridge config section. Cross-field and DataAnnotations
// rules are bundled in IValidateOptions<BridgeOptions> so they run together any
// time options are validated — no fluent-chain a future caller can forget.
builder.Services.AddOptions<BridgeOptions>()
    .Bind(builder.Configuration.GetSection("Bridge"))
    .ValidateOnStart();
builder.Services.AddSingleton<IValidateOptions<BridgeOptions>, BridgeOptionsValidator>();

builder.Services.AddSingleton<CloudEnvironmentConfig>(sp =>
{
    var runtimeOpts = sp.GetRequiredService<IOptions<BridgeOptions>>().Value;
    return CloudEnvironmentConfig.For(runtimeOpts.CloudEnvironment, runtimeOpts.AdminSiteUrl);
});

// Load the bound options once for startup-only wiring (listen URL + cert load),
// and run the same validator against it NOW so failures surface at startup
// BEFORE we call UseUrls or CertificateLoader.Load with partial data.
//
// ValidateOnStart() still fires again when the host resolves IOptions<T> — so
// if Program.cs is refactored later and this manual call disappears, the DI
// path still catches the problem. Belt + suspenders.
var opts = builder.Configuration.GetSection("Bridge").Get<BridgeOptions>()
    ?? throw new InvalidOperationException(
        "Bridge configuration section missing. Set Bridge:* config keys " +
        "(appsettings.Production.json) or BRIDGE_* environment variables.");

var validationResult = new BridgeOptionsValidator().Validate(Options.DefaultName, opts);
if (validationResult.Failed)
{
    throw new OptionsValidationException(
        Options.DefaultName,
        typeof(BridgeOptions),
        validationResult.Failures ?? new[] { "Bridge options validation failed with no explanation." });
}

// Listen URL: explicit ASPNETCORE_URLS (from .NET Host) wins if set;
// otherwise we use the validated Bridge:ListenUrl.
if (string.IsNullOrWhiteSpace(Environment.GetEnvironmentVariable("ASPNETCORE_URLS")))
{
    builder.WebHost.UseUrls(opts.ListenUrl);
}

// The test harness (BridgeFactory) uses CertPath="__TEST__" as a sentinel so
// the host boots without a real cert, then injects a mock ICsomOperations.
// /health reports thumbprint as null rather than leaking the sentinel.
System.Security.Cryptography.X509Certificates.X509Certificate2? cert = null;
if (opts.CertPath != "__TEST__")
{
    // Wrap cert load with a bootstrap logger so SCM/event-log picks up the
    // actionable failure reason BEFORE the host crash surfaces as "Error 1053".
    // Also covers LoadFromStore on Linux and wrong-PFX-password scenarios.
    using var bootstrapLoggerFactory = LoggerFactory.Create(lb =>
    {
        lb.AddConsole();
#pragma warning disable CA1416 // guarded by OperatingSystem.IsWindows() check
        if (OperatingSystem.IsWindows())
        {
            lb.AddEventLog(s => s.SourceName = "PartnerBridge");
        }
#pragma warning restore CA1416
    });
    var bootstrapLog = bootstrapLoggerFactory.CreateLogger("Partner365.Bridge.Startup");

    try
    {
        cert = CertificateLoader.Load(opts);
    }
    catch (Exception ex)
    {
        bootstrapLog.LogCritical(ex,
            "Bridge failed to load certificate at startup. CertPath={CertPath}, CertThumbprint={CertThumbprint}. See the inner exception for the root cause.",
            opts.CertPath ?? "(null)",
            opts.CertThumbprint ?? "(null)");
        throw;
    }
}

builder.Services.AddSingleton(_ => new BridgeStartupInfo(
    CloudEnvironmentName: CloudEnvironmentConfig.For(opts.CloudEnvironment, opts.AdminSiteUrl).CloudEnvironmentName,
    CertThumbprint: cert?.Thumbprint));

if (cert is not null)
{
    builder.Services.AddSingleton<ICsomOperations>(sp =>
    {
        var cloudCfg = sp.GetRequiredService<CloudEnvironmentConfig>();
        var runtimeOpts = sp.GetRequiredService<IOptions<BridgeOptions>>().Value;
        return new PnPCsomOperations(cloudCfg, runtimeOpts.TenantId, runtimeOpts.ClientId, runtimeOpts.AdminSiteUrl, cert);
    });
}
// else: the test harness injects a mock ICsomOperations.

builder.Services.AddSingleton<SharePointCsomService>();

builder.Services.AddSingleton<TimeProvider>(_ => TimeProvider.System);
builder.Services.AddSingleton<IPowerShellRunner, PowerShellSdkRunner>();
builder.Services.AddSingleton<ILabelEnumerationService, LabelEnumerationService>();

builder.Services.ConfigureHttpJsonOptions(jsonOpts =>
{
    jsonOpts.SerializerOptions.PropertyNamingPolicy = JsonNamingPolicy.CamelCase;
    jsonOpts.SerializerOptions.DefaultIgnoreCondition = JsonIgnoreCondition.WhenWritingNull;
});

var app = builder.Build();

// Shared-secret middleware. Resolve SharedSecret through IOptions so tests
// (which rebind via the test host) see the same value.
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
    catch (ArgumentException ex)
    {
        // ArgumentException from service-layer validation — caller bug, not an upstream failure.
        log.LogInformation("{RequestId} set-label rejected: {Message}", requestId, ex.Message);
        return BadRequest(requestId, ex.Message);
    }
    catch (Exception ex)
    {
        return ClassifyServerError(ex, log, requestId, "set-label", req.SiteUrl);
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
    catch (ArgumentException ex)
    {
        log.LogInformation("{RequestId} read-label rejected: {Message}", requestId, ex.Message);
        return BadRequest(requestId, ex.Message);
    }
    catch (Exception ex)
    {
        return ClassifyServerError(ex, log, requestId, "read-label", req.SiteUrl);
    }
});

app.MapGet("/v1/labels", async (
    ILabelEnumerationService svc,
    ILogger<Program> log,
    CancellationToken ct) =>
{
    var requestId = Guid.NewGuid().ToString("N");
    try
    {
        var response = await svc.GetLabelsAsync(ct);
        log.LogInformation("{RequestId} list-labels count={Count} source={Source}",
            requestId, response.Labels.Count, response.Source);
        return Results.Ok(response);
    }
    catch (OperationCanceledException)
    {
        throw;
    }
    catch (Exception ex)
    {
        return ClassifyServerError(ex, log, requestId, "list-labels", siteUrl: "(n/a)");
    }
});

app.Run();

static IResult BadRequest(string requestId, string message) =>
    Results.Json(new ErrorResponse(new ErrorBody("bad_request", message, requestId)), statusCode: 400);

static string SanitizeMessage(Exception ex) =>
    $"Bridge operation failed ({ex.GetType().Name}). See bridge logs.";

// Classify 5xx failures: known upstream-origin exceptions return 502 with a
// category (auth/throttle/network/certificate). Anything unrecognized is a
// bridge-side bug and returns 500 "internal_error" so the caller's retry policy
// doesn't keep hammering a broken process.
static IResult ClassifyServerError(Exception ex, ILogger log, string requestId, string op, string siteUrl)
{
    var code = ErrorClassifier.Classify(ex);
    if (code == "unknown")
    {
        log.LogError(ex, "{RequestId} {Op} failed with unclassified exception site={Site}", requestId, op, siteUrl);
        return Results.Json(
            new ErrorResponse(new ErrorBody("internal_error", SanitizeMessage(ex), requestId)),
            statusCode: 500);
    }

    log.LogError(ex, "{RequestId} {Op} failed site={Site} code={Code}", requestId, op, siteUrl, code);
    return Results.Json(new ErrorResponse(new ErrorBody(code, SanitizeMessage(ex), requestId)), statusCode: 502);
}

public sealed record BridgeStartupInfo(string CloudEnvironmentName, string? CertThumbprint);

public partial class Program { }
