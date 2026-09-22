namespace Otueke.SharedKernel.Time;

/// <summary>Production <see cref="IClock"/> backed by <see cref="TimeProvider.System"/>.</summary>
public sealed class SystemClock(TimeProvider timeProvider) : IClock
{
    public SystemClock()
        : this(TimeProvider.System)
    {
    }

    public DateTimeOffset UtcNow => timeProvider.GetUtcNow();
}
