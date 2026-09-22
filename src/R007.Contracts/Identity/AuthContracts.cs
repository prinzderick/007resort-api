namespace R007.Contracts.Identity;

public sealed record StaffLoginRequest(string Username, string Password, Guid? DeviceId = null);

public sealed record StaffLoginResponse(
    string AccessToken,
    DateTimeOffset AccessTokenExpiresAt,
    string RefreshToken,
    DateTimeOffset RefreshTokenExpiresAt,
    Guid SessionId,
    Guid StaffId,
    string DisplayName);

public sealed record StaffStepUpRequest(string Password);

public sealed record StaffStepUpResponse(bool Verified, DateTimeOffset VerifiedAt);

public sealed record RevokeSessionRequest(string? Reason = null);
