using Microsoft.EntityFrameworkCore;
using R007.Contracts.Audit;
using R007.Infrastructure.Persistence;
using R007.Modules.Audit.Domain;

namespace R007.Modules.Audit.Application;

public sealed class AuditReader(R007DbContext db) : IAuditReader
{
    public async Task<IReadOnlyList<AuditLogEntryResponse>> QueryAsync(string? entityType, Guid? entityId, int limit, CancellationToken cancellationToken = default)
    {
        var query = db.Set<AuditLogEntry>().AsNoTracking().AsQueryable();

        if (!string.IsNullOrWhiteSpace(entityType))
        {
            query = query.Where(a => a.EntityType == entityType);
        }

        if (entityId.HasValue)
        {
            query = query.Where(a => a.EntityId == entityId.Value);
        }

        var rows = await query
            .OrderByDescending(a => a.Seq)
            .Take(Math.Clamp(limit, 1, 200))
            .ToListAsync(cancellationToken);

        return rows.Select(a => new AuditLogEntryResponse(
            a.Id, a.OccurredAt, a.ActorStaffId, a.Action, a.EntityType, a.EntityId,
            a.OldValueJson, a.NewValueJson, a.PrevHash, a.RowHash)).ToList();
    }

    public async Task<AuditChainVerificationResult> VerifyChainAsync(CancellationToken cancellationToken = default)
    {
        var rows = await db.Set<AuditLogEntry>().AsNoTracking()
            .OrderBy(a => a.Seq)
            .ToListAsync(cancellationToken);

        var expectedPrev = AuditWriter.GenesisHash;

        foreach (var row in rows)
        {
            if (row.PrevHash != expectedPrev)
            {
                return new AuditChainVerificationResult(false, row.Seq);
            }

            var canonical = AuditWriter.CanonicalJson(
                row.Id, row.OccurredAt, row.OrganizationId, row.SiteId, row.ActorStaffId,
                row.FacilityUnitId, row.OperatingPointId, row.DeviceId, row.Action, row.EntityType,
                row.EntityId, row.OldValueJson, row.NewValueJson, row.ApprovalId, row.PrevHash);

            var expectedHash = Convert.ToHexStringLower(
                System.Security.Cryptography.SHA256.HashData(System.Text.Encoding.UTF8.GetBytes(row.PrevHash + canonical)));

            if (row.RowHash != expectedHash)
            {
                return new AuditChainVerificationResult(false, row.Seq);
            }

            expectedPrev = row.RowHash;
        }

        return new AuditChainVerificationResult(true, null);
    }
}
