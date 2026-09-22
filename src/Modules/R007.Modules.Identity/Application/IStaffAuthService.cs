using R007.Contracts.Identity;
using R007.SharedKernel.Results;

namespace R007.Modules.Identity.Application;

/// <summary>The Identity module's public entry point for authentication (architecture/14 §1).</summary>
public interface IStaffAuthService
{
    Task<Result<StaffLoginResponse>> LoginAsync(StaffLoginRequest request, string? clientIp, string? userAgent, CancellationToken cancellationToken = default);

    /// <summary>Re-verifies the current staff member's password for a sensitive-action
    /// "step-up" prompt (architecture/06 §4) without issuing a new session.</summary>
    Task<Result<StaffStepUpResponse>> StepUpAsync(Guid userAccountId, StaffStepUpRequest request, CancellationToken cancellationToken = default);

    /// <summary>Revokes a single session (sign out that device) — the same primitive that
    /// "sign out everywhere" iterates over.</summary>
    Task<Result> RevokeSessionAsync(Guid sessionId, Guid requestedByStaffId, string? reason, CancellationToken cancellationToken = default);
}
