using Microsoft.EntityFrameworkCore;
using Microsoft.EntityFrameworkCore.Metadata.Builders;

namespace R007.Infrastructure.Idempotency;

/// <summary>Mirrors the <c>idempotency_record</c> table (architecture/04 §1, architecture/15 §1).</summary>
public sealed class IdempotencyRecord
{
    public required string Scope { get; set; }

    public required string Key { get; set; }

    public required string RequestHash { get; set; }

    public int ResponseStatus { get; set; }

    public required string ResponseBodyJson { get; set; }

    public DateTimeOffset CreatedAt { get; set; }
}

public sealed class IdempotencyRecordConfiguration : IEntityTypeConfiguration<IdempotencyRecord>
{
    public void Configure(EntityTypeBuilder<IdempotencyRecord> builder)
    {
        builder.ToTable("idempotency_record");
        builder.HasKey(x => new { x.Scope, x.Key });
        builder.Property(x => x.Scope).HasColumnName("scope").HasMaxLength(64);
        builder.Property(x => x.Key).HasColumnName("idempotency_key").HasMaxLength(128);
        builder.Property(x => x.RequestHash).HasColumnName("request_hash").HasMaxLength(64).IsRequired();
        builder.Property(x => x.ResponseStatus).HasColumnName("response_status");
        builder.Property(x => x.ResponseBodyJson).HasColumnName("response_body").IsRequired();
        builder.Property(x => x.CreatedAt).HasColumnName("created_at");
    }
}
