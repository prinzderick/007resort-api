using System.Security.Cryptography;
using System.Text.Json;
using Microsoft.EntityFrameworkCore;
using R007.Contracts.Audit;
using R007.Contracts.Identity;
using R007.Infrastructure.Persistence;
using R007.Infrastructure.Security;
using R007.Modules.Audit.Application;
using R007.Modules.Identity.Domain;
using R007.SharedKernel.Results;
using R007.SharedKernel.Time;

namespace R007.Modules.Identity.Application;

public sealed class StaffAuthService(
    R007DbContext db,
    Argon2IdHasher hasher,
    JwtTokenService jwtTokenService,
    IAuditWriter auditWriter,
    IClock clock) : IStaffAuthService
{
    public static readonly Error InvalidCredentials = new("identity.login.invalid_credentials", "Invalid username or password.");
    public static readonly Error AccountLocked = new("identity.login.locked", "This account is temporarily locked.");
    public static readonly Error AccountInactive = new("identity.login.inactive", "This account is inactive.");
    public static readonly Error SessionNotFound = new("identity.session.not_found", "Session was not found.");

    private const int MaxFailedLogins = 5;
    private static readonly TimeSpan LockoutDuration = TimeSpan.FromMinutes(15);

    public async Task<Result<StaffLoginResponse>> LoginAsync(StaffLoginRequest request, string? clientIp, string? userAgent, CancellationToken cancellationToken = default)
    {
        var account = await db.Set<UserAccount>()
            .Include(a => a.Credentials)
            .FirstOrDefaultAsync(a => a.Username == request.Username, cancellationToken);

        var now = clock.UtcNow;

        if (account is null)
        {
            // Do not reveal whether the username exists.
            return Result.Failure<StaffLoginResponse>(InvalidCredentials);
        }

        if (account.LockedUntil is { } lockedUntil && lockedUntil > now)
        {
            return Result.Failure<StaffLoginResponse>(AccountLocked);
        }

        if (!account.IsActive)
        {
            return Result.Failure<StaffLoginResponse>(AccountInactive);
        }

        var credential = account.Credentials
            .Where(c => c.CredentialType == CredentialType.Password && c.IsActive)
            .OrderByDescending(c => c.CreatedAt)
            .FirstOrDefault();

        var passwordOk = credential is not null && hasher.Verify(request.Password, credential.CredentialHash);

        if (!passwordOk)
        {
            account.FailedLoginCount++;
            if (account.FailedLoginCount >= MaxFailedLogins)
            {
                account.LockedUntil = now.Add(LockoutDuration);
            }

            await auditWriter.RecordSecurityEventAsync(new SecurityEventEntry(
                EventType: "LOGIN_FAILURE",
                Severity: account.LockedUntil is not null ? "WARNING" : "INFO",
                IpAddress: clientIp,
                DetailsJson: JsonSerializer.Serialize(new { username = request.Username, failedCount = account.FailedLoginCount })), cancellationToken);
            await db.SaveChangesAsync(cancellationToken);

            return Result.Failure<StaffLoginResponse>(InvalidCredentials);
        }

        var staff = await db.Set<Staff>().FirstAsync(s => s.Id == account.StaffId, cancellationToken);

        await using var transaction = await db.Database.BeginTransactionAsync(cancellationToken);

        account.FailedLoginCount = 0;
        account.LockedUntil = null;
        credential!.LastUsedAt = now;

        var refreshToken = GenerateOpaqueToken();
        var session = new Session
        {
            Id = Guid.CreateVersion7(),
            UserAccountId = account.Id,
            DeviceId = request.DeviceId,
            RefreshTokenHash = Sha256Hex(refreshToken),
            IssuedAt = now,
            ExpiresAt = now.AddDays(30),
            CreatedIp = clientIp,
            UserAgent = userAgent,
            CreatedAt = now,
            UpdatedAt = now,
        };
        db.Set<Session>().Add(session);

        var accessToken = jwtTokenService.IssueStaffAccessToken(account.Id, staff.Id, session.Id);

        await auditWriter.RecordAsync(new AuditEntry(
            OrganizationId: staff.OrganizationId,
            SiteId: staff.SiteId,
            Action: "staff.login",
            EntityType: "Session",
            EntityId: session.Id,
            ActorStaffId: staff.Id,
            DeviceId: request.DeviceId,
            NewValueJson: JsonSerializer.Serialize(new { session.Id, account.Username, IssuedAt = now })), cancellationToken);

        await db.SaveChangesAsync(cancellationToken);
        await transaction.CommitAsync(cancellationToken);

        return Result.Success(new StaffLoginResponse(
            accessToken.Token,
            accessToken.ExpiresAt,
            refreshToken,
            session.ExpiresAt,
            session.Id,
            staff.Id,
            $"{staff.FirstName} {staff.LastName}"));
    }

    public async Task<Result<StaffStepUpResponse>> StepUpAsync(Guid userAccountId, StaffStepUpRequest request, CancellationToken cancellationToken = default)
    {
        var account = await db.Set<UserAccount>()
            .Include(a => a.Credentials)
            .FirstOrDefaultAsync(a => a.Id == userAccountId, cancellationToken);

        if (account is null)
        {
            return Result.Failure<StaffStepUpResponse>(InvalidCredentials);
        }

        var credential = account.Credentials
            .Where(c => c.CredentialType == CredentialType.Password && c.IsActive)
            .OrderByDescending(c => c.CreatedAt)
            .FirstOrDefault();

        var verified = credential is not null && hasher.Verify(request.Password, credential.CredentialHash);

        return verified
            ? Result.Success(new StaffStepUpResponse(true, clock.UtcNow))
            : Result.Failure<StaffStepUpResponse>(InvalidCredentials);
    }

    public async Task<Result> RevokeSessionAsync(Guid sessionId, Guid requestedByStaffId, string? reason, CancellationToken cancellationToken = default)
    {
        var session = await db.Set<Session>().FirstOrDefaultAsync(s => s.Id == sessionId, cancellationToken);
        if (session is null)
        {
            return Result.Failure(SessionNotFound);
        }

        var staff = await db.Set<Staff>().FirstOrDefaultAsync(s => s.Id == requestedByStaffId, cancellationToken);

        await using var transaction = await db.Database.BeginTransactionAsync(cancellationToken);

        session.RevokedAt = clock.UtcNow;
        session.RevokedReason = reason ?? "revoked";

        await auditWriter.RecordAsync(new AuditEntry(
            OrganizationId: staff?.OrganizationId ?? Guid.Empty,
            SiteId: staff?.SiteId ?? Guid.Empty,
            Action: "session.revoke",
            EntityType: "Session",
            EntityId: session.Id,
            ActorStaffId: requestedByStaffId,
            NewValueJson: JsonSerializer.Serialize(new { session.Id, RevokedAt = session.RevokedAt, reason })), cancellationToken);

        await db.SaveChangesAsync(cancellationToken);
        await transaction.CommitAsync(cancellationToken);

        return Result.Success();
    }

    private static string GenerateOpaqueToken() => Convert.ToBase64String(RandomNumberGenerator.GetBytes(32))
        .Replace('+', '-').Replace('/', '_').TrimEnd('=');

    private static string Sha256Hex(string input) =>
        Convert.ToHexStringLower(SHA256.HashData(System.Text.Encoding.UTF8.GetBytes(input)));
}
