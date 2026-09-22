using R007.SharedKernel.Results;

namespace R007.UnitTests;

public sealed class ResultTests
{
    [Fact]
    public void Success_HasNoError()
    {
        var result = Result.Success(42);

        Assert.True(result.IsSuccess);
        Assert.Equal(42, result.Value);
        Assert.Equal(Error.None, result.Error);
    }

    [Fact]
    public void Failure_CarriesErrorAndHidesValue()
    {
        var error = new Error("orders.not_found", "Order was not found.");

        var result = Result.Failure<int>(error);

        Assert.True(result.IsFailure);
        Assert.Equal(error, result.Error);
        Assert.Throws<InvalidOperationException>(() => result.Value);
    }

    [Fact]
    public void Failure_WithoutError_IsRejected()
    {
        Assert.Throws<ArgumentException>(() => Result.Failure(Error.None));
    }
}
