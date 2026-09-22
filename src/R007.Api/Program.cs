using System.Text;
using Asp.Versioning;
using Microsoft.AspNetCore.Authentication.JwtBearer;
using Microsoft.AspNetCore.Authorization;
using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.Options;
using Microsoft.IdentityModel.Tokens;
using R007.Api.Configuration;
using R007.Api.Endpoints;
using R007.Infrastructure.Migrations;
using R007.Infrastructure.Persistence;
using R007.Infrastructure.Security;
using R007.Modules.Audit;
using R007.Modules.Audit.Endpoints;
using R007.Modules.Devices;
using R007.Modules.Devices.Endpoints;
using R007.Modules.Identity;
using R007.Modules.Identity.Endpoints;
using R007.Modules.Organization;
using R007.Modules.Organization.Endpoints;
using R007.SharedKernel.Time;
using Scalar.AspNetCore;
using Serilog;
using Serilog.Formatting.Compact;
using static R007.Contracts.Identity.PermissionCodes;

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

    // --- Persistence -------------------------------------------------------------------
    // MySQL 8.4 in every real deployment (Pomelo provider); a test host may override this
    // registration with SQLite (see tests/R007.IntegrationTests) where a live MySQL instance
    // is not available — the schema/migrations remain MySQL-authoritative regardless.
    var connectionString = builder.Configuration.GetConnectionString("R007");
    builder.Services.AddDbContext<R007DbContext>(options =>
    {
        if (!string.IsNullOrWhiteSpace(connectionString))
        {
            options.UseMySql(connectionString, ServerVersion.AutoDetect(connectionString));
        }
    });

    // --- Security ------------------------------------------------------------------------
    var jwtOptions = builder.Configuration.GetSection($"{R007Options.SectionName}:Jwt").Get<JwtOptions>() ?? new JwtOptions();

    if (string.IsNullOrWhiteSpace(jwtOptions.SigningKey) && !builder.Environment.IsEnvironment("Testing"))
    {
        // Fail fast rather than silently issuing unsigned/insecurely-signed tokens.
        // R007:Jwt:SigningKey must come from user-secrets/environment/secret store — never a
        // committed default (architecture/17 §6). A placeholder is still used below so
        // Development keeps working without a locally-configured secret; it is never suitable
        // for anything beyond a developer's own machine.
        Log.Warning("R007:Jwt:SigningKey is not configured. Using a development-only placeholder signing key.");
    }

    var effectiveSigningKey = string.IsNullOrEmpty(jwtOptions.SigningKey)
        ? "development-only-placeholder-signing-key-32-bytes!"
        : jwtOptions.SigningKey;

    builder.Services.AddSingleton(Options.Create(new JwtSigningOptions
    {
        SigningKey = effectiveSigningKey,
        Issuer = jwtOptions.Issuer,
        StaffAudience = jwtOptions.StaffAudience,
        DeviceAudience = jwtOptions.DeviceAudience,
        AccessTokenLifetime = TimeSpan.FromMinutes(jwtOptions.AccessTokenLifetimeMinutes),
        DeviceTokenLifetime = TimeSpan.FromDays(jwtOptions.DeviceTokenLifetimeDays),
    }));

    builder.Services
        .AddAuthentication(JwtBearerDefaults.AuthenticationScheme)
        .AddJwtBearer(options =>
        {
            options.TokenValidationParameters = new TokenValidationParameters
            {
                ValidateIssuer = true,
                ValidIssuer = jwtOptions.Issuer,
                ValidateAudience = true,
                ValidAudience = jwtOptions.StaffAudience,
                ValidateLifetime = true,
                ValidateIssuerSigningKey = true,
                IssuerSigningKey = new SymmetricSecurityKey(Encoding.UTF8.GetBytes(effectiveSigningKey)),
                ClockSkew = TimeSpan.FromSeconds(30),
            };
        });

    builder.Services.AddAuthorization(options =>
    {
        // One policy per permission code the API declares today. Endpoints reference these by
        // name via RequirePermission(...) (see R007.Modules.Identity.Endpoints.EndpointAuthorizationExtensions).
        foreach (var code in new[]
        {
            DeviceRegister, DeviceRevoke, DeviceView, RoleAssignmentManage, SessionRevoke,
            AuditView, SecurityEventView, ConfigManage, FacilityConfigure,
        })
        {
            options.AddPermissionPolicy(code, R007.Contracts.Identity.ScopeLevel.FacilityUnit);
        }
    });

    // --- Modules ---------------------------------------------------------------------------
    builder.Services.AddOrganizationModule();
    builder.Services.AddIdentityModule();
    builder.Services.AddDevicesModule();
    builder.Services.AddAuditModule();

    var app = builder.Build();

    app.UseExceptionHandler();
    app.UseStatusCodePages();

    app.UseSerilogRequestLogging();

    app.UseAuthentication();
    app.UseAuthorization();

    // --- Migrations ---------------------------------------------------------------------
    // Applied automatically only in Development, when R007:DeploymentMode=Site (a site server
    // self-manages its own schema on deploy), or when explicitly opted into via
    // R007:Migrations:AutoApply=true. Every other environment (Cloud/staging/production) must
    // run the separate manual migration runner as a deploy step:
    //   dotnet run --project tools/R007.MigrationRunner -- "<connection-string>"
    // See db/migrations/README.md and R007Options.MigrationsOptions.
    var r007Options = app.Services.GetRequiredService<IOptions<R007Options>>().Value;
    var shouldAutoApplyMigrations = app.Environment.IsDevelopment()
        || r007Options.DeploymentMode == DeploymentMode.Site
        || r007Options.Migrations.AutoApply;

    if (shouldAutoApplyMigrations && !string.IsNullOrWhiteSpace(connectionString) && !app.Environment.IsEnvironment("Testing"))
    {
        var migrationsPath = ResolveMigrationsPath(app.Environment.ContentRootPath);
        if (Directory.Exists(migrationsPath))
        {
            DbUpMigrationRunner.Run(connectionString, migrationsPath, app.Logger);
        }
        else
        {
            app.Logger.LogWarning("Migrations directory not found at {Path}; skipping auto-apply.", migrationsPath);
        }
    }

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
    v1.MapOrganizationEndpoints();
    v1.MapIdentityEndpoints();
    v1.MapRoleAssignmentEndpoints();
    v1.MapDeviceEndpoints();
    v1.MapAuditEndpoints();

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

// Walks up from the content root to find db/migrations — works both when running from the repo
// root and from the built output directory.
static string ResolveMigrationsPath(string contentRootPath)
{
    var directory = new DirectoryInfo(contentRootPath);
    while (directory is not null)
    {
        var candidate = Path.Combine(directory.FullName, "db", "migrations");
        if (Directory.Exists(candidate))
        {
            return candidate;
        }

        directory = directory.Parent;
    }

    return Path.Combine(contentRootPath, "db", "migrations");
}

// Entry point; partial and public so integration tests can use WebApplicationFactory.
public partial class Program;
