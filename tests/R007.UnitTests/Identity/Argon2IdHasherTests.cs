using R007.Infrastructure.Security;

namespace R007.UnitTests.Identity;

public sealed class Argon2IdHasherTests
{
    private readonly Argon2IdHasher _hasher = new();

    [Fact]
    public void Hash_ThenVerify_WithCorrectSecret_Succeeds()
    {
        var hash = _hasher.Hash("correct horse battery staple");

        Assert.True(_hasher.Verify("correct horse battery staple", hash));
    }

    [Fact]
    public void Verify_WithWrongSecret_Fails()
    {
        var hash = _hasher.Hash("correct horse battery staple");

        Assert.False(_hasher.Verify("wrong secret", hash));
    }

    [Fact]
    public void Hash_ProducesADifferentStringEachTime_BecauseOfRandomSalt()
    {
        var first = _hasher.Hash("same-input");
        var second = _hasher.Hash("same-input");

        Assert.NotEqual(first, second);
        Assert.True(_hasher.Verify("same-input", first));
        Assert.True(_hasher.Verify("same-input", second));
    }

    [Fact]
    public void Hash_EncodesTheArgon2idAlgorithmTag()
    {
        var hash = _hasher.Hash("a pin or a password, both go through here");

        Assert.StartsWith("$argon2id$", hash);
    }

    [Fact]
    public void Verify_WithGarbageEncodedHash_ReturnsFalse_DoesNotThrow()
    {
        Assert.False(_hasher.Verify("anything", "not-a-real-hash"));
    }
}
