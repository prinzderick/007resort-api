using R007.Contracts.Audit;

namespace R007.Modules.Audit.Application;

public interface IAuditReader
{
    Task<IReadOnlyList<AuditLogEntryResponse>> QueryAsync(string? entityType, Guid? entityId, int limit, CancellationToken cancellationToken = default);

    /// <summary>Walks the chain (optionally restricted to one entity) and verifies every
    /// <c>row_hash</c> reproduces from its own fields and the previous row's hash.</summary>
    Task<AuditChainVerificationResult> VerifyChainAsync(CancellationToken cancellationToken = default);
}
