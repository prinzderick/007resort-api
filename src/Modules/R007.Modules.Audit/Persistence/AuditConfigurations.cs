using Microsoft.EntityFrameworkCore;
using Microsoft.EntityFrameworkCore.Metadata.Builders;
using R007.Modules.Audit.Domain;

namespace R007.Modules.Audit.Persistence;

public sealed class AuditLogEntryConfiguration : IEntityTypeConfiguration<AuditLogEntry>
{
    public void Configure(EntityTypeBuilder<AuditLogEntry> builder)
    {
        builder.ToTable("audit_log");
        builder.HasKey(x => x.Seq);
        builder.Property(x => x.Seq).HasColumnName("seq").ValueGeneratedOnAdd();
        builder.Property(x => x.Id).HasColumnName("id");
        builder.HasIndex(x => x.Id).IsUnique();
        builder.Property(x => x.OccurredAt).HasColumnName("occurred_at");
        builder.Property(x => x.OrganizationId).HasColumnName("organization_id");
        builder.Property(x => x.SiteId).HasColumnName("site_id");
        builder.Property(x => x.ActorStaffId).HasColumnName("actor_staff_id");
        builder.Property(x => x.FacilityUnitId).HasColumnName("facility_unit_id");
        builder.Property(x => x.OperatingPointId).HasColumnName("operating_point_id");
        builder.Property(x => x.DeviceId).HasColumnName("device_id");
        builder.Property(x => x.Action).HasColumnName("action").HasMaxLength(64).IsRequired();
        builder.Property(x => x.EntityType).HasColumnName("entity_type").HasMaxLength(64).IsRequired();
        builder.Property(x => x.EntityId).HasColumnName("entity_id");
        builder.Property(x => x.OldValueJson).HasColumnName("old_value");
        builder.Property(x => x.NewValueJson).HasColumnName("new_value");
        builder.Property(x => x.ApprovalId).HasColumnName("approval_id");
        builder.Property(x => x.PrevHash).HasColumnName("prev_hash").HasMaxLength(64).IsRequired();
        builder.Property(x => x.RowHash).HasColumnName("row_hash").HasMaxLength(64).IsRequired();
        builder.HasIndex(x => new { x.EntityType, x.EntityId });
    }
}

public sealed class ApprovalConfiguration : IEntityTypeConfiguration<Approval>
{
    public void Configure(EntityTypeBuilder<Approval> builder)
    {
        builder.ToTable("approval");
        builder.HasKey(x => x.Id);
        builder.Property(x => x.Id).HasColumnName("id").ValueGeneratedNever();
        builder.Property(x => x.RequestedBy).HasColumnName("requested_by");
        builder.Property(x => x.ApprovedBy).HasColumnName("approved_by");
        builder.Property(x => x.Status).HasColumnName("status").HasMaxLength(16);
        builder.Property(x => x.Reason).HasColumnName("reason").HasMaxLength(255);
        builder.Property(x => x.CreatedAt).HasColumnName("created_at");
        builder.Property(x => x.DecidedAt).HasColumnName("decided_at");
    }
}

public sealed class SecurityEventConfiguration : IEntityTypeConfiguration<SecurityEvent>
{
    public void Configure(EntityTypeBuilder<SecurityEvent> builder)
    {
        builder.ToTable("security_event");
        builder.HasKey(x => x.Id);
        builder.Property(x => x.Id).HasColumnName("id").ValueGeneratedNever();
        builder.Property(x => x.OccurredAt).HasColumnName("occurred_at");
        builder.Property(x => x.EventType).HasColumnName("event_type").HasMaxLength(64).IsRequired();
        builder.Property(x => x.Severity).HasColumnName("severity").HasMaxLength(16);
        builder.Property(x => x.ActorStaffId).HasColumnName("actor_staff_id");
        builder.Property(x => x.DeviceId).HasColumnName("device_id");
        builder.Property(x => x.IpAddress).HasColumnName("ip_address").HasMaxLength(64);
        builder.Property(x => x.DetailsJson).HasColumnName("details");
        builder.Property(x => x.CreatedAt).HasColumnName("created_at");
    }
}
