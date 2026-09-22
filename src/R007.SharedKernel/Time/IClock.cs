namespace R007.SharedKernel.Time;

/// <summary>
/// Abstraction over the current time. All timestamps in 007 Resort & Spa are UTC.
/// Inject this instead of calling <see cref="DateTimeOffset.UtcNow"/> directly so that
/// time-dependent rules (shifts, bookings, memberships, sync ordering) are testable.
/// </summary>
public interface IClock
{
    /// <summary>Gets the current instant in UTC.</summary>
    DateTimeOffset UtcNow { get; }
}
