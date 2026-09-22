using System.Security.Cryptography;
using System.Text;
using Konscious.Security.Cryptography;

namespace R007.Infrastructure.Security;

/// <summary>
/// One-way hashing for both staff passwords and PINs (architecture/17-security-model.md §4:
/// "Passwords: one-way hashing (Argon2id). PINs: hashed, not stored reversibly.").
///
/// Parameters (OWASP-recommended Argon2id baseline for an interactive, latency-sensitive login
/// path — POS/tablet sign-in must stay responsive on modest on-site server hardware):
///   - Memory:      19 MiB  (19 * 1024 KiB) — OWASP's minimum recommended working set for Argon2id
///                  when parallelism is low; kept modest because a busy POS server may need to
///                  verify several concurrent logins (shift changes) without starving other work.
///   - Iterations:  2       — OWASP's minimum recommended time cost at this memory size.
///   - Parallelism: 1       — avoids over-subscribing CPU on shared site-server hardware; memory
///                  cost is the primary work factor here, not thread count.
///   - Salt:        16 random bytes per hash, generated with <see cref="RandomNumberGenerator"/>.
///   - Output:      32-byte derived key.
/// The salt, and all parameters, are encoded into the stored string (PHC-like custom format)
/// so they can be changed later without invalidating already-stored hashes of unrelated users,
/// and so verification never depends on out-of-band configuration matching what a hash was
/// created with.
/// </summary>
public sealed class Argon2IdHasher
{
    private const int SaltSizeBytes = 16;
    private const int HashSizeBytes = 32;
    private const int MemorySizeKib = 19 * 1024;
    private const int Iterations = 2;
    private const int DegreeOfParallelism = 1;

    /// <summary>Hashes <paramref name="secret"/> (a password or PIN) into a self-describing string.</summary>
    public string Hash(string secret)
    {
        ArgumentException.ThrowIfNullOrEmpty(secret);

        var salt = RandomNumberGenerator.GetBytes(SaltSizeBytes);
        var hash = ComputeHash(secret, salt, MemorySizeKib, Iterations, DegreeOfParallelism);

        return $"$argon2id$v=19$m={MemorySizeKib},t={Iterations},p={DegreeOfParallelism}${Convert.ToBase64String(salt)}${Convert.ToBase64String(hash)}";
    }

    /// <summary>Verifies <paramref name="secret"/> against a string produced by <see cref="Hash"/>.</summary>
    public bool Verify(string secret, string encodedHash)
    {
        ArgumentException.ThrowIfNullOrEmpty(secret);

        if (string.IsNullOrEmpty(encodedHash))
        {
            return false;
        }

        var parts = encodedHash.Split('$', StringSplitOptions.RemoveEmptyEntries);
        if (parts.Length != 5 || parts[0] != "argon2id")
        {
            return false;
        }

        var parameters = parts[2].Split(',');
        var memoryKib = int.Parse(parameters[0].Split('=')[1]);
        var iterations = int.Parse(parameters[1].Split('=')[1]);
        var parallelism = int.Parse(parameters[2].Split('=')[1]);
        var salt = Convert.FromBase64String(parts[3]);
        var expectedHash = Convert.FromBase64String(parts[4]);

        var actualHash = ComputeHash(secret, salt, memoryKib, iterations, parallelism, expectedHash.Length);

        return CryptographicOperations.FixedTimeEquals(actualHash, expectedHash);
    }

    private static byte[] ComputeHash(string secret, byte[] salt, int memoryKib, int iterations, int parallelism, int outputSize = HashSizeBytes)
    {
        using var argon2 = new Argon2id(Encoding.UTF8.GetBytes(secret))
        {
            Salt = salt,
            DegreeOfParallelism = parallelism,
            Iterations = iterations,
            MemorySize = memoryKib,
        };

        return argon2.GetBytes(outputSize);
    }
}
