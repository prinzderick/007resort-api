using Microsoft.EntityFrameworkCore;
using Microsoft.EntityFrameworkCore.Metadata.Builders;
using R007.Modules.Devices.Domain;

namespace R007.Modules.Devices.Persistence;

public sealed class DeviceConfiguration : IEntityTypeConfiguration<Device>
{
    public void Configure(EntityTypeBuilder<Device> builder)
    {
        builder.ToTable("device");
        builder.HasKey(x => x.Id);
        builder.Property(x => x.Id).HasColumnName("id").ValueGeneratedNever();
        builder.Property(x => x.OrganizationId).HasColumnName("organization_id");
        builder.Property(x => x.SiteId).HasColumnName("site_id");
        builder.Property(x => x.FacilityUnitId).HasColumnName("facility_unit_id");
        builder.Property(x => x.DeviceType).HasColumnName("device_type").HasConversion<string>().HasMaxLength(24);
        builder.Property(x => x.Name).HasColumnName("name").HasMaxLength(200).IsRequired();
        builder.Property(x => x.IsActive).HasColumnName("is_active");
        builder.Property(x => x.IsRevoked).HasColumnName("is_revoked");
        builder.Property(x => x.RevokedAt).HasColumnName("revoked_at");
        builder.Property(x => x.RowVersion).HasColumnName("row_version").IsConcurrencyToken();
        builder.Property(x => x.CreatedAt).HasColumnName("created_at");
        builder.Property(x => x.UpdatedAt).HasColumnName("updated_at");
    }
}

public sealed class DeviceRegistrationConfiguration : IEntityTypeConfiguration<DeviceRegistration>
{
    public void Configure(EntityTypeBuilder<DeviceRegistration> builder)
    {
        builder.ToTable("device_registration");
        builder.HasKey(x => x.Id);
        builder.Property(x => x.Id).HasColumnName("id").ValueGeneratedNever();
        builder.Property(x => x.DeviceId).HasColumnName("device_id");
        builder.Property(x => x.TokenId).HasColumnName("token_id");
        builder.Property(x => x.RegisteredByStaffId).HasColumnName("registered_by_staff_id");
        builder.Property(x => x.IssuedAt).HasColumnName("issued_at");
        builder.Property(x => x.ExpiresAt).HasColumnName("expires_at");
        builder.Property(x => x.RevokedAt).HasColumnName("revoked_at");
        builder.Property(x => x.RevokedReason).HasColumnName("revoked_reason").HasMaxLength(255);
        builder.Property(x => x.CreatedAt).HasColumnName("created_at");
        builder.HasIndex(x => x.TokenId).IsUnique();
        builder.HasIndex(x => new { x.DeviceId, x.RevokedAt, x.ExpiresAt });
    }
}

public sealed class DeviceBindingConfiguration : IEntityTypeConfiguration<DeviceBinding>
{
    public void Configure(EntityTypeBuilder<DeviceBinding> builder)
    {
        builder.ToTable("device_binding");
        builder.HasKey(x => x.Id);
        builder.Property(x => x.Id).HasColumnName("id").ValueGeneratedNever();
        builder.Property(x => x.DeviceId).HasColumnName("device_id");
        builder.Property(x => x.FacilityUnitId).HasColumnName("facility_unit_id");
        builder.Property(x => x.OperatingPointLabel).HasColumnName("operating_point_label").HasMaxLength(120);
        builder.Property(x => x.BoundAt).HasColumnName("bound_at");
        builder.Property(x => x.UnboundAt).HasColumnName("unbound_at");
        builder.Property(x => x.CreatedAt).HasColumnName("created_at");
        builder.HasIndex(x => new { x.DeviceId, x.UnboundAt });
    }
}
