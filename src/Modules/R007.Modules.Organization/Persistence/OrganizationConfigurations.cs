using Microsoft.EntityFrameworkCore;
using Microsoft.EntityFrameworkCore.Metadata.Builders;
using R007.Modules.Organization.Domain;

namespace R007.Modules.Organization.Persistence;

public sealed class OrganizationEntityConfiguration : IEntityTypeConfiguration<OrganizationEntity>
{
    public void Configure(EntityTypeBuilder<OrganizationEntity> builder)
    {
        builder.ToTable("organization");
        builder.HasKey(x => x.Id);
        builder.Property(x => x.Id).HasColumnName("id").ValueGeneratedNever();
        builder.Property(x => x.Name).HasColumnName("name").HasMaxLength(200).IsRequired();
        builder.Property(x => x.CreatedAt).HasColumnName("created_at");
        builder.Property(x => x.UpdatedAt).HasColumnName("updated_at");
    }
}

public sealed class SiteConfiguration : IEntityTypeConfiguration<Site>
{
    public void Configure(EntityTypeBuilder<Site> builder)
    {
        builder.ToTable("site");
        builder.HasKey(x => x.Id);
        builder.Property(x => x.Id).HasColumnName("id").ValueGeneratedNever();
        builder.Property(x => x.OrganizationId).HasColumnName("organization_id");
        builder.Property(x => x.Name).HasColumnName("name").HasMaxLength(200).IsRequired();
        builder.Property(x => x.TimeZone).HasColumnName("time_zone").HasMaxLength(64);
        builder.Property(x => x.CreatedAt).HasColumnName("created_at");
        builder.Property(x => x.UpdatedAt).HasColumnName("updated_at");
    }
}

public sealed class FacilityUnitConfiguration : IEntityTypeConfiguration<FacilityUnit>
{
    public void Configure(EntityTypeBuilder<FacilityUnit> builder)
    {
        builder.ToTable("facility_unit");
        builder.HasKey(x => x.Id);
        builder.Property(x => x.Id).HasColumnName("id").ValueGeneratedNever();
        builder.Property(x => x.OrganizationId).HasColumnName("organization_id");
        builder.Property(x => x.SiteId).HasColumnName("site_id");
        builder.Property(x => x.ParentId).HasColumnName("parent_id");
        builder.Property(x => x.Code).HasColumnName("code").HasMaxLength(64).IsRequired();
        builder.Property(x => x.Name).HasColumnName("name").HasMaxLength(200).IsRequired();
        builder.Property(x => x.IsActive).HasColumnName("is_active");
        builder.Property(x => x.DeletedAt).HasColumnName("deleted_at");
        builder.Property(x => x.RowVersion).HasColumnName("row_version").IsConcurrencyToken();
        builder.Property(x => x.CreatedAt).HasColumnName("created_at");
        builder.Property(x => x.UpdatedAt).HasColumnName("updated_at");
        builder.HasIndex(x => new { x.SiteId, x.Code }).IsUnique();
        builder.HasQueryFilter(x => x.DeletedAt == null);
        builder.HasMany(x => x.Capabilities).WithOne().HasForeignKey(x => x.FacilityUnitId);
    }
}

public sealed class CapabilityTypeConfiguration : IEntityTypeConfiguration<CapabilityType>
{
    public void Configure(EntityTypeBuilder<CapabilityType> builder)
    {
        builder.ToTable("capability_type");
        builder.HasKey(x => x.Code);
        builder.Property(x => x.Code).HasColumnName("code").HasMaxLength(48);
        builder.Property(x => x.Description).HasColumnName("description").HasMaxLength(255).IsRequired();
    }
}

public sealed class FacilityCapabilityConfiguration : IEntityTypeConfiguration<FacilityCapability>
{
    public void Configure(EntityTypeBuilder<FacilityCapability> builder)
    {
        builder.ToTable("facility_capability");
        builder.HasKey(x => x.Id);
        builder.Property(x => x.Id).HasColumnName("id").ValueGeneratedNever();
        builder.Property(x => x.FacilityUnitId).HasColumnName("facility_unit_id");
        builder.Property(x => x.CapabilityCode).HasColumnName("capability_code").HasMaxLength(48).IsRequired();
        builder.Property(x => x.IsEnabled).HasColumnName("is_enabled");
        builder.Property(x => x.CreatedAt).HasColumnName("created_at");
        builder.Property(x => x.UpdatedAt).HasColumnName("updated_at");
        builder.HasIndex(x => new { x.FacilityUnitId, x.CapabilityCode }).IsUnique();
        builder.HasMany(x => x.Rules).WithOne().HasForeignKey(x => x.FacilityCapabilityId);
    }
}

public sealed class OperatingRuleConfiguration : IEntityTypeConfiguration<OperatingRule>
{
    public void Configure(EntityTypeBuilder<OperatingRule> builder)
    {
        builder.ToTable("operating_rule");
        builder.HasKey(x => x.Id);
        builder.Property(x => x.Id).HasColumnName("id").ValueGeneratedNever();
        builder.Property(x => x.FacilityCapabilityId).HasColumnName("facility_capability_id");
        builder.Property(x => x.RuleKey).HasColumnName("rule_key").HasMaxLength(64).IsRequired();
        builder.Property(x => x.RuleValue).HasColumnName("rule_value").HasMaxLength(255).IsRequired();
        builder.Property(x => x.CreatedAt).HasColumnName("created_at");
        builder.Property(x => x.UpdatedAt).HasColumnName("updated_at");
        builder.HasIndex(x => new { x.FacilityCapabilityId, x.RuleKey }).IsUnique();
    }
}
