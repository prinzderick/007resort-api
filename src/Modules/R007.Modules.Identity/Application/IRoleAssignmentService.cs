using R007.Contracts.Identity;
using R007.SharedKernel.Results;

namespace R007.Modules.Identity.Application;

/// <summary>
/// Grants/revokes role assignments (architecture/06 §1). Both operations write an
/// <c>audit_log</c> row in the same transaction as the change (task's hard audit requirement)
/// and are exposed behind idempotency-key-protected endpoints (task scope item 7).
/// </summary>
public interface IRoleAssignmentService
{
    Task<Result<RoleAssignmentResponse>> GrantAsync(GrantRoleAssignmentRequest request, Guid grantedByStaffId, CancellationToken cancellationToken = default);

    Task<Result> RevokeAsync(Guid roleAssignmentId, Guid revokedByStaffId, string? reason, CancellationToken cancellationToken = default);
}
