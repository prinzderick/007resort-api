using R007.Contracts.Audit;

namespace R007.Modules.Audit.Application;

/// <summary>
/// The Audit module's public entry point. Other modules call <see cref="RecordAsync"/> to append
/// a row to the hash-chained <c>audit_log</c> table — the only sanctioned way to write to it.
///
/// <b>Same-transaction guarantee.</b> <see cref="RecordAsync"/> stages the audit row on the
/// ambient EF Core change tracker of the shared <c>R007DbContext</c> injected into this service
/// (the same DbContext instance the calling module's own scoped services use, resolved once per
/// HTTP request/DI scope). It does not call <c>SaveChangesAsync</c> itself. The caller is
/// responsible for calling <c>SaveChangesAsync</c> exactly once, after both its own mutation and
/// this audit row are staged, so both are written to MySQL by the same transaction — the hard
/// requirement in architecture/17-security-model.md §5. If the caller instead needs the audit
/// row committed independently (rare), it must open an explicit
/// <c>IDbContextTransaction</c> spanning both writes itself.
///
/// This is a deliberate, synchronous, same-process call from Identity/Devices into Audit,
/// not the eventually-consistent integration-event/outbox pattern architecture/14 §1 describes
/// for most cross-module notifications: an audit row that might never arrive (or arrive after a
/// crash between the mutation and the event being processed) would fail the "written in the same
/// transaction" requirement. Audit therefore sits as a direct dependency of Identity and Devices
/// for this one synchronous write path, while still publishing nothing back the other way.
/// </summary>
public interface IAuditWriter
{
    /// <summary>
    /// Computes the next hash-chain link (<c>row_hash = SHA-256(prev_hash || canonical_json(row))</c>)
    /// against the current tail of the chain and stages the new row. Must be called with an
    /// active transaction (or as part of the caller's own upcoming <c>SaveChangesAsync</c>) so the
    /// chain read-then-append is atomic with respect to concurrent writers.
    /// </summary>
    Task RecordAsync(AuditEntry entry, CancellationToken cancellationToken = default);

    /// <summary>
    /// Stages a <c>security_event</c> row (not hash-chained — a lighter-weight, non-financial
    /// observability signal per architecture/17-security-model.md §5, e.g. login failures,
    /// permission denials, device revocations) on the same ambient change tracker as
    /// <see cref="RecordAsync"/>.
    /// </summary>
    Task RecordSecurityEventAsync(SecurityEventEntry entry, CancellationToken cancellationToken = default);
}
