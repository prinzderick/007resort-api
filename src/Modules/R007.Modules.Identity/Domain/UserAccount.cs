namespace R007.Modules.Identity.Domain;

public sealed class UserAccount
{
    public Guid Id { get; set; }

    public Guid StaffId { get; set; }

    public required string Username { get; set; }

    public bool IsActive { get; set; } = true;

    public int FailedLoginCount { get; set; }

    public DateTimeOffset? LockedUntil { get; set; }

    public int RowVersion { get; set; } = 1;

    public DateTimeOffset CreatedAt { get; set; }

    public DateTimeOffset UpdatedAt { get; set; }

    public List<Credential> Credentials { get; set; } = [];
}

/// <summary>
/// A one-way-hashed authentication factor. <see cref="CredentialType.Password"/> is the only
/// type Phase 1 issues; the schema and this enum are ready for <see cref="CredentialType.Pin"/>,
/// <see cref="CredentialType.NfcCard"/> and <see cref="CredentialType.Totp"/> without a migration
/// (architecture/17 §1, spec requirement for NFC+PIN on fixed POS terminals).
/// </summary>
public enum CredentialType
{
    Password,
    Pin,
    NfcCard,
    Totp,
}

public sealed class Credential
{
    public Guid Id { get; set; }

    public Guid UserAccountId { get; set; }

    public CredentialType CredentialType { get; set; }

    /// <summary>Argon2id-encoded hash string (never a reversible secret) — see
    /// <see cref="R007.Infrastructure.Security.Argon2IdHasher"/>.</summary>
    public required string CredentialHash { get; set; }

    public string Algorithm { get; set; } = "ARGON2ID";

    public bool IsActive { get; set; } = true;

    public DateTimeOffset? LastUsedAt { get; set; }

    public int RowVersion { get; set; } = 1;

    public DateTimeOffset CreatedAt { get; set; }

    public DateTimeOffset UpdatedAt { get; set; }
}
