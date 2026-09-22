using Microsoft.EntityFrameworkCore;
using Microsoft.EntityFrameworkCore.Metadata.Builders;
using R007.Modules.Identity.Domain;

namespace R007.Modules.Identity.Persistence;

public sealed class StaffConfiguration : IEntityTypeConfiguration<Staff>
{
    public void Configure(EntityTypeBuilder<Staff> builder)
    {
        builder.ToTable("staff");
        builder.HasKey(x => x.Id);
        builder.Property(x => x.Id).HasColumnName("id").ValueGeneratedNever();
        builder.Property(x => x.OrganizationId).HasColumnName("organization_id");
        builder.Property(x => x.SiteId).HasColumnName("site_id");
        builder.Property(x => x.StaffNumber).HasColumnName("staff_number").HasMaxLength(32).IsRequired();
        builder.Property(x => x.FirstName).HasColumnName("first_name").HasMaxLength(120).IsRequired();
        builder.Property(x => x.LastName).HasColumnName("last_name").HasMaxLength(120).IsRequired();
        builder.Property(x => x.Email).HasColumnName("email").HasMaxLength(255);
        builder.Property(x => x.Phone).HasColumnName("phone").HasMaxLength(32);
        builder.Property(x => x.IsActive).HasColumnName("is_active");
        builder.Property(x => x.DeletedAt).HasColumnName("deleted_at");
        builder.Property(x => x.RowVersion).HasColumnName("row_version").IsConcurrencyToken();
        builder.Property(x => x.CreatedAt).HasColumnName("created_at");
        builder.Property(x => x.UpdatedAt).HasColumnName("updated_at");
        builder.HasIndex(x => new { x.SiteId, x.StaffNumber }).IsUnique();
        builder.HasQueryFilter(x => x.DeletedAt == null);
    }
}

public sealed class UserAccountConfiguration : IEntityTypeConfiguration<UserAccount>
{
    public void Configure(EntityTypeBuilder<UserAccount> builder)
    {
        builder.ToTable("user_account");
        builder.HasKey(x => x.Id);
        builder.Property(x => x.Id).HasColumnName("id").ValueGeneratedNever();
        builder.Property(x => x.StaffId).HasColumnName("staff_id");
        builder.Property(x => x.Username).HasColumnName("username").HasMaxLength(100).IsRequired();
        builder.Property(x => x.IsActive).HasColumnName("is_active");
        builder.Property(x => x.FailedLoginCount).HasColumnName("failed_login_count");
        builder.Property(x => x.LockedUntil).HasColumnName("locked_until");
        builder.Property(x => x.RowVersion).HasColumnName("row_version").IsConcurrencyToken();
        builder.Property(x => x.CreatedAt).HasColumnName("created_at");
        builder.Property(x => x.UpdatedAt).HasColumnName("updated_at");
        builder.HasIndex(x => x.StaffId).IsUnique();
        builder.HasIndex(x => x.Username).IsUnique();
        builder.HasMany(x => x.Credentials).WithOne().HasForeignKey(x => x.UserAccountId);
    }
}

public sealed class CredentialConfiguration : IEntityTypeConfiguration<Credential>
{
    public void Configure(EntityTypeBuilder<Credential> builder)
    {
        builder.ToTable("credential");
        builder.HasKey(x => x.Id);
        builder.Property(x => x.Id).HasColumnName("id").ValueGeneratedNever();
        builder.Property(x => x.UserAccountId).HasColumnName("user_account_id");
        builder.Property(x => x.CredentialType).HasColumnName("credential_type").HasConversion<string>().HasMaxLength(16);
        builder.Property(x => x.CredentialHash).HasColumnName("credential_hash").HasMaxLength(512).IsRequired();
        builder.Property(x => x.Algorithm).HasColumnName("algorithm").HasMaxLength(32);
        builder.Property(x => x.IsActive).HasColumnName("is_active");
        builder.Property(x => x.LastUsedAt).HasColumnName("last_used_at");
        builder.Property(x => x.RowVersion).HasColumnName("row_version").IsConcurrencyToken();
        builder.Property(x => x.CreatedAt).HasColumnName("created_at");
        builder.Property(x => x.UpdatedAt).HasColumnName("updated_at");
        builder.HasIndex(x => new { x.UserAccountId, x.CredentialType, x.IsActive });
    }
}

public sealed class RoleConfiguration : IEntityTypeConfiguration<Role>
{
    public void Configure(EntityTypeBuilder<Role> builder)
    {
        builder.ToTable("role");
        builder.HasKey(x => x.Id);
        builder.Property(x => x.Id).HasColumnName("id").ValueGeneratedOnAdd();
        builder.Property(x => x.Code).HasColumnName("code").HasMaxLength(64).IsRequired();
        builder.Property(x => x.Name).HasColumnName("name").HasMaxLength(120).IsRequired();
        builder.Property(x => x.Description).HasColumnName("description").HasMaxLength(255);
        builder.Property(x => x.CreatedAt).HasColumnName("created_at");
        builder.Property(x => x.UpdatedAt).HasColumnName("updated_at");
        builder.HasIndex(x => x.Code).IsUnique();
    }
}

public sealed class PermissionConfiguration : IEntityTypeConfiguration<Permission>
{
    public void Configure(EntityTypeBuilder<Permission> builder)
    {
        builder.ToTable("permission");
        builder.HasKey(x => x.Id);
        builder.Property(x => x.Id).HasColumnName("id").ValueGeneratedOnAdd();
        builder.Property(x => x.Code).HasColumnName("code").HasMaxLength(96).IsRequired();
        builder.Property(x => x.Description).HasColumnName("description").HasMaxLength(255);
        builder.Property(x => x.CreatedAt).HasColumnName("created_at");
        builder.HasIndex(x => x.Code).IsUnique();
    }
}

public sealed class RolePermissionConfiguration : IEntityTypeConfiguration<RolePermission>
{
    public void Configure(EntityTypeBuilder<RolePermission> builder)
    {
        builder.ToTable("role_permission");
        builder.HasKey(x => new { x.RoleId, x.PermissionId });
        builder.Property(x => x.RoleId).HasColumnName("role_id");
        builder.Property(x => x.PermissionId).HasColumnName("permission_id");
        builder.Property(x => x.RequiresApproval).HasColumnName("requires_approval");
    }
}

public sealed class RoleAssignmentConfiguration : IEntityTypeConfiguration<RoleAssignment>
{
    public void Configure(EntityTypeBuilder<RoleAssignment> builder)
    {
        builder.ToTable("role_assignment");
        builder.HasKey(x => x.Id);
        builder.Property(x => x.Id).HasColumnName("id").ValueGeneratedNever();
        builder.Property(x => x.StaffId).HasColumnName("staff_id");
        builder.Property(x => x.RoleId).HasColumnName("role_id");
        builder.Property(x => x.ScopeLevel).HasColumnName("scope_level").HasConversion<string>().HasMaxLength(16);
        builder.Property(x => x.OrganizationId).HasColumnName("organization_id");
        builder.Property(x => x.SiteId).HasColumnName("site_id");
        builder.Property(x => x.FacilityUnitId).HasColumnName("facility_unit_id");
        builder.Property(x => x.IsActive).HasColumnName("is_active");
        builder.Property(x => x.DeletedAt).HasColumnName("deleted_at");
        builder.Property(x => x.GrantedBy).HasColumnName("granted_by");
        builder.Property(x => x.GrantedAt).HasColumnName("granted_at");
        builder.Property(x => x.RowVersion).HasColumnName("row_version").IsConcurrencyToken();
        builder.Property(x => x.CreatedAt).HasColumnName("created_at");
        builder.Property(x => x.UpdatedAt).HasColumnName("updated_at");
        builder.HasIndex(x => new { x.StaffId, x.IsActive });
        builder.HasQueryFilter(x => x.DeletedAt == null);
    }
}

public sealed class SessionConfiguration : IEntityTypeConfiguration<Session>
{
    public void Configure(EntityTypeBuilder<Session> builder)
    {
        builder.ToTable("session");
        builder.HasKey(x => x.Id);
        builder.Property(x => x.Id).HasColumnName("id").ValueGeneratedNever();
        builder.Property(x => x.UserAccountId).HasColumnName("user_account_id");
        builder.Property(x => x.DeviceId).HasColumnName("device_id");
        builder.Property(x => x.RefreshTokenHash).HasColumnName("refresh_token_hash").HasMaxLength(64).IsRequired();
        builder.Property(x => x.IssuedAt).HasColumnName("issued_at");
        builder.Property(x => x.ExpiresAt).HasColumnName("expires_at");
        builder.Property(x => x.RevokedAt).HasColumnName("revoked_at");
        builder.Property(x => x.RevokedReason).HasColumnName("revoked_reason").HasMaxLength(255);
        builder.Property(x => x.ReplacedBySessionId).HasColumnName("replaced_by_session_id");
        builder.Property(x => x.CreatedIp).HasColumnName("created_ip").HasMaxLength(64);
        builder.Property(x => x.UserAgent).HasColumnName("user_agent").HasMaxLength(255);
        builder.Property(x => x.RowVersion).HasColumnName("row_version").IsConcurrencyToken();
        builder.Property(x => x.CreatedAt).HasColumnName("created_at");
        builder.Property(x => x.UpdatedAt).HasColumnName("updated_at");
        builder.HasIndex(x => x.RefreshTokenHash).IsUnique();
        builder.HasIndex(x => new { x.UserAccountId, x.RevokedAt, x.ExpiresAt });
    }
}
