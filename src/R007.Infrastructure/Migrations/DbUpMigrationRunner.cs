using DbUp;
using DbUp.Engine;
using DbUp.Engine.Output;
using Microsoft.Extensions.Logging;

namespace R007.Infrastructure.Migrations;

/// <summary>
/// Applies the versioned SQL scripts in <c>db/migrations</c> using DbUp
/// (<see href="https://dbup.readthedocs.io/"/>, <c>dbup-mysql</c> provider).
///
/// Why DbUp over a bespoke runner (ADR-0004 / architecture/20 Q3): it is a small, transparent
/// library that does exactly one thing — run ordered, forward-only SQL scripts against MySQL and
/// record which ones have already run in a journal table (<c>SchemaVersions</c>, created and
/// managed automatically). That matches the "hand-written, reviewable SQL is the schema" decision
/// in ADR-0004 with minimal extra ceremony: no generated migration classes, no ORM-specific
/// migration DSL, and the journal table gives an auditable list of exactly what has been applied
/// to a given database, which a bespoke runner would have to reimplement from scratch anyway.
/// </summary>
public static class DbUpMigrationRunner
{
    /// <summary>
    /// Runs every not-yet-applied script embedded from <paramref name="scriptsPath"/> against
    /// <paramref name="connectionString"/>, in filename order. Throws on failure — a site or
    /// cloud instance must not start serving traffic against a half-migrated schema.
    /// </summary>
    public static void Run(string connectionString, string scriptsPath, ILogger logger)
    {
        ArgumentException.ThrowIfNullOrWhiteSpace(connectionString);
        ArgumentException.ThrowIfNullOrWhiteSpace(scriptsPath);

        if (!Directory.Exists(scriptsPath))
        {
            throw new DirectoryNotFoundException($"Migration scripts directory not found: {scriptsPath}");
        }

        var upgrader = DeployChanges.To
            .MySqlDatabase(connectionString)
            .WithScriptsFromFileSystem(scriptsPath)
            .LogTo(new MicrosoftLoggingUpgradeLog(logger))
            .WithTransactionPerScript()
            .Build();

        var result = upgrader.PerformUpgrade();

        if (!result.Successful)
        {
            throw new InvalidOperationException(
                $"Database migration failed while applying '{result.ErrorScript?.Name}'.", result.Error);
        }

        logger.LogInformation(
            "Database migrations up to date. {Count} script(s) applied this run.",
            result.Scripts.Count());
    }

    /// <summary>Bridges DbUp's own logging interface to <see cref="ILogger"/> so migration output
    /// flows into the same structured Serilog pipeline as the rest of the API.</summary>
    private sealed class MicrosoftLoggingUpgradeLog(ILogger logger) : IUpgradeLog
    {
        public void LogTrace(string format, params object[] args) => logger.LogTrace(format, args);

        public void LogDebug(string format, params object[] args) => logger.LogDebug(format, args);

        public void LogInformation(string format, params object[] args) => logger.LogInformation(format, args);

        public void LogWarning(string format, params object[] args) => logger.LogWarning(format, args);

        public void LogError(string format, params object[] args) => logger.LogError(format, args);

        public void LogError(Exception ex, string format, params object[] args) => logger.LogError(ex, format, args);
    }
}
