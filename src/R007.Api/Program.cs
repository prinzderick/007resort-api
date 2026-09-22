using Asp.Versioning;
using R007.Api.Configuration;
using R007.Api.Endpoints;
using R007.SharedKernel.Time;
using Scalar.AspNetCore;
using Serilog;
using Serilog.Formatting.Compact;

// Bootstrap logger so that start-up failures are captured in structured form too.
Log.Logger = new LoggerConfiguration()
    .WriteTo.Console(new RenderedCompactJsonFormatter())
    .CreateBootstrapLogger();

try
{
    var builder = WebApplication.CreateBuilder(args);

    // Structured JSON logging. Never log secrets, connection strings, PINs, card/NFC payloads or tokens.
    builder.Services.AddSerilog((services, configuration) => configuration
        .ReadFrom.Configuration(builder.Configuration)
        .ReadFrom.Services(services)
        .Enrich.FromLogContext()
        .Enrich.WithProperty("service", SystemEndpoints.ServiceName)
        .WriteTo.Console(new RenderedCompactJsonFormatter()));

    builder.Services
        .AddOptions<R007Options>()
        .Bind(builder.Configuration.GetSection(R007Options.SectionName))
        .ValidateOnStart();

    builder.Services.AddSingleton<IClock, SystemClock>();

    // RFC 7807 problem details for every error response.
    builder.Services.AddProblemDetails();

    builder.Services
        .AddApiVersioning(options =>
        {
            options.DefaultApiVersion = new ApiVersion(1);
            options.ReportApiVersions = true;
            options.ApiVersionReader = new UrlSegmentApiVersionReader();
        })
        .AddApiExplorer(options =>
        {
            options.GroupNameFormat = "'v'V";
            options.SubstituteApiVersionInUrl = true;
        })
        .AddOpenApi(); // one OpenAPI document per API version (e.g. /openapi/v1.json)

    builder.Services.AddR007HealthChecks();

    var app = builder.Build();

    app.UseExceptionHandler();
    app.UseStatusCodePages();

    app.UseSerilogRequestLogging();

    if (app.Environment.IsDevelopment())
    {
        app.MapOpenApi().WithDocumentPerVersion();
        app.MapScalarApiReference();
    }

    app.MapR007HealthEndpoints();

    var versionSet = app.NewApiVersionSet()
        .HasApiVersion(new ApiVersion(1))
        .ReportApiVersions()
        .Build();

    var v1 = app.MapGroup("/api/v{version:apiVersion}")
        .WithApiVersionSet(versionSet)
        .MapToApiVersion(new ApiVersion(1));

    v1.MapSystemEndpoints();

    await app.RunAsync();
}
catch (Exception ex) when (ex is not HostAbortedException)
{
    Log.Fatal(ex, "007 Resort & Spa API terminated unexpectedly");
    throw;
}
finally
{
    await Log.CloseAndFlushAsync();
}

/// <summary>Entry point; partial and public so integration tests can use WebApplicationFactory.</summary>
public partial class Program;
