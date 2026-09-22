using System.Text.Json;
using Microsoft.EntityFrameworkCore;
using R007.Contracts.Audit;
using R007.Contracts.Identity;
using R007.Infrastructure.Persistence;
using R007.Modules.Audit.Application;
using R007.Modules.Identity.Domain;
using R007.SharedKernel.Results;
using R007.SharedKernel.Time;

namespace R007.Modules.Identity.Application;

public sealed class RoleAssignmentService(R007DbContext db, IAuditWriter auditWriter, IClock clock) : IRoleAssignmentService
{
    public static readonly Error RoleNotFound = new("identity.role.not_found", "Role code was not found.");
    public static readonly Error StaffNotFound = new("identity.staff.not_found", "Staff member was not found.");
    public static readonly Error AssignmentNotFound = new("identity.role_assignment.not_found", "Role assignment was not found.");

    public async Task<Result<RoleAssignmentResponse>> GrantAsync(GrantRoleAssignmentRequest request, Guid grantedByStaffId, CancellationToken cancellationToken = default)
    {
        var role = await db.Set<Role>().FirstOrDefaultAsync(r => r.Code == request.RoleCode, cancellationToken);
        if (role is null)
        {
            return Result.Failure<RoleAssignmentResponse>(RoleNotFound);
        }

        var staffExists = await db.Set<Staff>().AnyAsync(s => s.Id == request.StaffId, cancellationToken);
        if (!staffExists)
        {
            return Result.Failure<RoleAssignmentResponse>(StaffNotFound);
        }

        await using var transaction = await db.Database.BeginTransactionAsync(cancellationToken);

        var now = clock.UtcNow;
        var assignment = new RoleAssignment
        {
            Id = Guid.CreateVersion7(),
            StaffId = request.StaffId,
            RoleId = role.Id,
            ScopeLevel = (Domain.ScopeLevel)request.ScopeLevel,
            OrganizationId = request.OrganizationId,
            SiteId = request.SiteId,
            FacilityUnitId = request.FacilityUnitId,
            GrantedBy = grantedByStaffId,
            GrantedAt = now,
            CreatedAt = now,
            UpdatedAt = now,
        };
        db.Set<RoleAssignment>().Add(assignment);

        await auditWriter.RecordAsync(new AuditEntry(
            OrganizationId: request.OrganizationId,
            SiteId: request.SiteId ?? Guid.Empty,
            Action: "role_assignment.grant",
            EntityType: "RoleAssignment",
            EntityId: assignment.Id,
            ActorStaffId: grantedByStaffId,
            FacilityUnitId: request.FacilityUnitId,
            NewValueJson: JsonSerializer.Serialize(new
            {
                assignment.Id,
                StaffId = request.StaffId,
                RoleCode = request.RoleCode,
                ScopeLevel = request.ScopeLevel.ToString(),
                request.OrganizationId,
                request.SiteId,
                request.FacilityUnitId,
            })), cancellationToken);

        await db.SaveChangesAsync(cancellationToken);
        await transaction.CommitAsync(cancellationToken);

        return Result.Success(new RoleAssignmentResponse(
            assignment.Id, assignment.StaffId, role.Code, request.ScopeLevel,
            assignment.OrganizationId, assignment.SiteId, assignment.FacilityUnitId, assignment.IsActive));
    }

    public async Task<Result> RevokeAsync(Guid roleAssignmentId, Guid revokedByStaffId, string? reason, CancellationToken cancellationToken = default)
    {
        var assignment = await db.Set<RoleAssignment>().FirstOrDefaultAsync(a => a.Id == roleAssignmentId, cancellationToken);
        if (assignment is null)
        {
            return Result.Failure(AssignmentNotFound);
        }

        await using var transaction = await db.Database.BeginTransactionAsync(cancellationToken);

        var oldValue = JsonSerializer.Serialize(new { assignment.Id, assignment.IsActive });
        assignment.IsActive = false;
        assignment.UpdatedAt = clock.UtcNow;

        await auditWriter.RecordAsync(new AuditEntry(
            OrganizationId: assignment.OrganizationId,
            SiteId: assignment.SiteId ?? Guid.Empty,
            Action: "role_assignment.revoke",
            EntityType: "RoleAssignment",
            EntityId: assignment.Id,
            ActorStaffId: revokedByStaffId,
            FacilityUnitId: assignment.FacilityUnitId,
            OldValueJson: oldValue,
            NewValueJson: JsonSerializer.Serialize(new { assignment.Id, IsActive = false, reason })), cancellationToken);

        await db.SaveChangesAsync(cancellationToken);
        await transaction.CommitAsync(cancellationToken);

        return Result.Success();
    }
}
