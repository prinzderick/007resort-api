using Microsoft.Extensions.Logging;
using R007.Infrastructure.Migrations;

// Manual migration runner for environments that do NOT auto-apply migrations at API startup
// (Cloud, staging, production — see R007Options.MigrationsOptions and R007.Api/Program.cs for
// exactly which environments DO auto-apply). Run as a deploy step:
//
//   dotnet run --project tools/R007.MigrationRunner -- "<mysql-connection-string>" ["<scripts-path>"]
//
// The connection string is passed as an argument (or via the R007_MIGRATIONS_CONNECTION_STRING
// environment variable), never hardcoded — it comes from the deployment's own secret store.

using var loggerFactory = LoggerFactory.Create(builder => builder
    .SetMinimumLevel(LogLevel.Information)
    .AddSimpleConsole(options =>
    {
        options.SingleLine = true;
        options.TimestampFormat = "yyyy-MM-ddTHH:mm:ss.fffZ ";
    }));

var logger = loggerFactory.CreateLogger("R007.MigrationRunner");

var connectionString = args.Length > 0
    ? args[0]
    : Environment.GetEnvironmentVariable("R007_MIGRATIONS_CONNECTION_STRING");

if (string.IsNullOrWhiteSpace(connectionString))
{
    logger.LogError(
        "No connection string provided. Usage: dotnet run --project tools/R007.MigrationRunner -- \"<connection-string>\" [\"<scripts-path>\"], " +
        "or set R007_MIGRATIONS_CONNECTION_STRING.");
    return 1;
}

var scriptsPath = args.Length > 1 ? args[1] : ResolveDefaultScriptsPath();

try
{
    DbUpMigrationRunner.Run(connectionString, scriptsPath, logger);
    return 0;
}
catch (Exception ex)
{
    logger.LogError(ex, "Migration run failed.");
    return 1;
}

static string ResolveDefaultScriptsPath()
{
    var directory = new DirectoryInfo(AppContext.BaseDirectory);
    while (directory is not null)
    {
        var candidate = Path.Combine(directory.FullName, "db", "migrations");
        if (Directory.Exists(candidate))
        {
            return candidate;
        }

        directory = directory.Parent;
    }

    throw new DirectoryNotFoundException(
        "Could not locate db/migrations by walking up from the executable's directory. Pass the path explicitly as the second argument.");
}
