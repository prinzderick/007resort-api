namespace Otueke.SharedKernel.Results;

/// <summary>
/// A domain/application error. <see cref="Code"/> is a stable, machine-readable identifier
/// (e.g. "orders.not_found") that the API maps to RFC 7807 problem details.
/// </summary>
public sealed record Error(string Code, string Message)
{
    /// <summary>Sentinel used by successful results.</summary>
    public static readonly Error None = new(string.Empty, string.Empty);
}
