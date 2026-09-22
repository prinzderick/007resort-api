namespace R007.Modules.Identity.Domain;

/// <summary>
/// Server-side refresh-token record (architecture/17 §3). The opaque refresh token itself is
/// never persisted — only its SHA-256 hash (<see cref="RefreshTokenHash"/>) — so revoking a row
/// here immediately invalidates "sign out everywhere" without needing the original token value.
/// </summary>
public sealed class Session
{
    public Guid Id { get; set; }

    public Guid UserAccountId { get; set; }

    public Guid? DeviceId { get; set; }

    public required string RefreshTokenHash { get; set; }

    public DateTimeOffset IssuedAt { get; set; }

    public DateTimeOffset ExpiresAt { get; set; }

    public DateTimeOffset? RevokedAt { get; set; }

    public string? RevokedReason { get; set; }

    public Guid? ReplacedBySessionId { get; set; }

    public string? CreatedIp { get; set; }

    public string? UserAgent { get; set; }

    public int RowVersion { get; set; } = 1;

    public DateTimeOffset CreatedAt { get; set; }

    public DateTimeOffset UpdatedAt { get; set; }

    public bool IsActive(DateTimeOffset now) => RevokedAt is null && ExpiresAt > now;
}
