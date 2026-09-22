using System.Globalization;
using System.Security.Cryptography;
using System.Text;
using Microsoft.EntityFrameworkCore;
using Microsoft.EntityFrameworkCore.Storage;
using R007.Contracts.Audit;
using R007.Infrastructure.Persistence;
using R007.Modules.Audit.Domain;
using R007.SharedKernel.Time;

namespace R007.Modules.Audit.Application;

public sealed class AuditWriter(R007DbContext db, IClock clock) : IAuditWriter
{
    /// <summary><c>prev_hash</c> of the very first row in the chain (architecture/04 §3.5).</summary>
    /// <summary>64 zero characters — sized to the <c>audit_log.prev_hash CHAR(64)</c> column.</summary>
    public static readonly string GenesisHash = new('0', 64);

    public async Task RecordAsync(AuditEntry entry, CancellationToken cancellationToken = default)
    {
        var prevHash = await ReadTailHashWithLockAsync(cancellationToken);

        var id = Guid.CreateVersion7();
        var occurredAt = clock.UtcNow;

        var canonical = CanonicalJson(
            id, occurredAt, entry.OrganizationId, entry.SiteId, entry.ActorStaffId, entry.FacilityUnitId,
            entry.OperatingPointId, entry.DeviceId, entry.Action, entry.EntityType, entry.EntityId,
            entry.OldValueJson, entry.NewValueJson, entry.ApprovalId, prevHash);

        var rowHash = Sha256Hex(prevHash + canonical);

        db.Set<AuditLogEntry>().Add(new AuditLogEntry
        {
            Id = id,
            OccurredAt = occurredAt,
            OrganizationId = entry.OrganizationId,
            SiteId = entry.SiteId,
            ActorStaffId = entry.ActorStaffId,
            FacilityUnitId = entry.FacilityUnitId,
            OperatingPointId = entry.OperatingPointId,
            DeviceId = entry.DeviceId,
            Action = entry.Action,
            EntityType = entry.EntityType,
            EntityId = entry.EntityId,
            OldValueJson = entry.OldValueJson,
            NewValueJson = entry.NewValueJson,
            ApprovalId = entry.ApprovalId,
            PrevHash = prevHash,
            RowHash = rowHash,
        });
    }

    public Task RecordSecurityEventAsync(SecurityEventEntry entry, CancellationToken cancellationToken = default)
    {
        db.Set<SecurityEvent>().Add(new SecurityEvent
        {
            Id = Guid.CreateVersion7(),
            OccurredAt = clock.UtcNow,
            EventType = entry.EventType,
            Severity = entry.Severity,
            ActorStaffId = entry.ActorStaffId,
            DeviceId = entry.DeviceId,
            IpAddress = entry.IpAddress,
            DetailsJson = entry.DetailsJson,
            CreatedAt = clock.UtcNow,
        });

        return Task.CompletedTask;
    }

    /// <summary>
    /// Reads the current tail's <c>row_hash</c> (or the genesis hash if the table is empty).
    /// On MySQL this takes a row lock (<c>SELECT ... FOR UPDATE</c>) so concurrent writers append
    /// to the chain one at a time instead of racing to compute the same <c>prev_hash</c>; SQLite
    /// (used in this repository's non-MySQL test runs) does not support that clause and does not
    /// need it — its own transaction serialization already prevents the race.
    /// </summary>
    private async Task<string> ReadTailHashWithLockAsync(CancellationToken cancellationToken)
    {
        var isMySql = db.Database.ProviderName?.Contains("MySql", StringComparison.OrdinalIgnoreCase) == true;
        var sql = isMySql
            ? "SELECT row_hash FROM audit_log ORDER BY seq DESC LIMIT 1 FOR UPDATE"
            : "SELECT row_hash FROM audit_log ORDER BY seq DESC LIMIT 1";

        await using var command = db.Database.GetDbConnection().CreateCommand();
        command.CommandText = sql;
        if (db.Database.CurrentTransaction is not null)
        {
            command.Transaction = db.Database.CurrentTransaction.GetDbTransaction();
        }

        if (command.Connection!.State != System.Data.ConnectionState.Open)
        {
            await command.Connection.OpenAsync(cancellationToken);
        }

        await using var reader = await command.ExecuteReaderAsync(cancellationToken);
        return await reader.ReadAsync(cancellationToken) ? reader.GetString(0) : GenesisHash;
    }

    /// <summary>
    /// Deterministic, field-order-fixed JSON used as the chain's hash input
    /// (<c>row_hash = SHA256(prev_hash || canonical_json(row))</c>). Fixed field order and
    /// invariant formatting make the function reproducible independent of culture or a JSON
    /// serializer's own key-ordering behaviour, which matters because the chain must verify
    /// identically wherever it is checked.
    /// </summary>
    internal static string CanonicalJson(
        Guid id, DateTimeOffset occurredAt, Guid organizationId, Guid siteId, Guid? actorStaffId,
        Guid? facilityUnitId, Guid? operatingPointId, Guid? deviceId, string action, string entityType,
        Guid entityId, string? oldValueJson, string? newValueJson, Guid? approvalId, string prevHash)
    {
        var sb = new StringBuilder();
        sb.Append('{');
        AppendField(sb, "id", id.ToString(), first: true);
        AppendField(sb, "occurredAt", occurredAt.UtcDateTime.ToString("O", CultureInfo.InvariantCulture));
        AppendField(sb, "organizationId", organizationId.ToString());
        AppendField(sb, "siteId", siteId.ToString());
        AppendField(sb, "actorStaffId", actorStaffId?.ToString());
        AppendField(sb, "facilityUnitId", facilityUnitId?.ToString());
        AppendField(sb, "operatingPointId", operatingPointId?.ToString());
        AppendField(sb, "deviceId", deviceId?.ToString());
        AppendField(sb, "action", action);
        AppendField(sb, "entityType", entityType);
        AppendField(sb, "entityId", entityId.ToString());
        AppendField(sb, "oldValue", oldValueJson);
        AppendField(sb, "newValue", newValueJson);
        AppendField(sb, "approvalId", approvalId?.ToString());
        AppendField(sb, "prevHash", prevHash);
        sb.Append('}');
        return sb.ToString();
    }

    private static void AppendField(StringBuilder sb, string name, string? value, bool first = false)
    {
        if (!first)
        {
            sb.Append(',');
        }

        sb.Append('"').Append(name).Append("\":");
        sb.Append(value is null ? "null" : $"\"{value.Replace("\\", "\\\\").Replace("\"", "\\\"")}\"");
    }

    private static string Sha256Hex(string input)
    {
        var bytes = SHA256.HashData(Encoding.UTF8.GetBytes(input));
        return Convert.ToHexStringLower(bytes);
    }
}
