using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using Microsoft.AspNetCore.Builder;
using Microsoft.AspNetCore.Http;
using Microsoft.AspNetCore.Http.HttpResults;
using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.DependencyInjection;
using R007.Infrastructure.Persistence;
using R007.SharedKernel.Time;

namespace R007.Infrastructure.Idempotency;

/// <summary>
/// Reusable idempotency guard for non-idempotent mutating endpoints (architecture/15 §1:
/// "Idempotency-Key header required on every non-idempotent mutating request"). Required on
/// device registration and role-assignment changes (task scope); deliberately NOT applied to
/// login (a fresh authentication attempt each time, not a retryable resource creation).
///
/// How it works:
///  1. Requires an <c>Idempotency-Key</c> header; missing it is a <c>400</c>.
///  2. Hashes the endpoint's already-bound arguments (<see cref="EndpointFilterInvocationContext.Arguments"/>)
///     as the "request" fingerprint — simpler and more reliable than re-reading the raw request
///     body, and equivalent for JSON-bodied minimal API endpoints.
///  3. On a first sighting of <c>(scope, key)</c>, invokes the endpoint, snapshots its
///     status code + body, and stores both under <c>(scope, key, requestHash)</c>.
///  4. On a repeat sighting with the SAME request fingerprint, replays the stored response
///     without invoking the endpoint again — the mandatory "duplicate key + duplicate request
///     returns the original response without double-applying the effect" behaviour.
///  5. On a repeat sighting with a DIFFERENT fingerprint (the key was reused for an unrelated
///     request), returns <c>409</c> — this is a client misuse, not a safe replay.
///
/// Known limitation (documented rather than silently accepted): the idempotency record is
/// written in its own <c>SaveChangesAsync</c> call *after* the wrapped endpoint's own
/// transaction has already committed, so a crash in the narrow window between the two could
/// allow a duplicate effect on retry. Folding both into one transaction (e.g. by having the
/// wrapped handler accept an ambient transaction the filter opens) is a Phase 2 hardening item.
/// </summary>
public sealed class IdempotencyEndpointFilter(string scope) : IEndpointFilter
{
    private const string HeaderName = "Idempotency-Key";

    public async ValueTask<object?> InvokeAsync(EndpointFilterInvocationContext context, EndpointFilterDelegate next)
    {
        var http = context.HttpContext;

        if (!http.Request.Headers.TryGetValue(HeaderName, out var keyValues) || string.IsNullOrWhiteSpace(keyValues.ToString()))
        {
            return Results.Problem(
                title: "idempotency_key_required",
                detail: $"The '{HeaderName}' header is required for this request.",
                statusCode: StatusCodes.Status400BadRequest,
                extensions: new Dictionary<string, object?> { ["code"] = "idempotency_key_required" });
        }

        var key = keyValues.ToString();
        var db = http.RequestServices.GetRequiredService<R007DbContext>();
        var clock = http.RequestServices.GetRequiredService<IClock>();

        var requestHash = ComputeRequestHash(context.Arguments);

        var existing = await db.Set<IdempotencyRecord>()
            .FirstOrDefaultAsync(r => r.Scope == scope && r.Key == key, http.RequestAborted);

        if (existing is not null)
        {
            if (existing.RequestHash != requestHash)
            {
                return Results.Problem(
                    title: "idempotency_key_conflict",
                    detail: "This Idempotency-Key was already used for a different request.",
                    statusCode: StatusCodes.Status409Conflict,
                    extensions: new Dictionary<string, object?> { ["code"] = "idempotency_key_conflict" });
            }

            var element = JsonSerializer.Deserialize<JsonElement>(existing.ResponseBodyJson);
            return Results.Json(element, statusCode: existing.ResponseStatus);
        }

        var result = await next(context);
        var (status, bodyJson) = ExtractResponse(result);

        db.Set<IdempotencyRecord>().Add(new IdempotencyRecord
        {
            Scope = scope,
            Key = key,
            RequestHash = requestHash,
            ResponseStatus = status,
            ResponseBodyJson = bodyJson,
            CreatedAt = clock.UtcNow,
        });
        await db.SaveChangesAsync(http.RequestAborted);

        return result;
    }

    private static string ComputeRequestHash(IList<object?> arguments)
    {
        var serializable = arguments.Where(a => a is not System.Threading.CancellationToken && a is not HttpContext).ToArray();
        var json = JsonSerializer.Serialize(serializable);
        return Convert.ToHexStringLower(SHA256.HashData(Encoding.UTF8.GetBytes(json)));
    }

    private static (int Status, string BodyJson) ExtractResponse(object? result)
    {
        if (result is IValueHttpResult valueResult && result is IStatusCodeHttpResult statusResult)
        {
            return (statusResult.StatusCode ?? StatusCodes.Status200OK, JsonSerializer.Serialize(valueResult.Value));
        }

        if (result is IStatusCodeHttpResult statusOnly)
        {
            return (statusOnly.StatusCode ?? StatusCodes.Status204NoContent, "null");
        }

        return (StatusCodes.Status200OK, JsonSerializer.Serialize(result));
    }
}

public static class IdempotencyEndpointFilterExtensions
{
    /// <summary>Applies the idempotency guard to an endpoint, scoped by name (e.g.
    /// <c>"device.register"</c>, <c>"role_assignment.manage"</c>) so keys from one endpoint can
    /// never collide with another's.</summary>
    public static TBuilder RequireIdempotencyKey<TBuilder>(this TBuilder builder, string scope)
        where TBuilder : IEndpointConventionBuilder
    {
        builder.AddEndpointFilter(new IdempotencyEndpointFilter(scope));
        return builder;
    }
}
