<?php

namespace App\Support\Http;

use App\Support\Ids;
use App\Support\RequestContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\MalformedUrlException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Turns every exception into RFC 7807 problem+json with a stable `code` from the API contract's
 * ProblemCode enum (007resort-docs api/openapi/v1.yaml): unauthenticated, token_expired, invalid_credentials,
 * account_locked, permission_denied, validation_failed (`errors`: field => [messages]), not_found,
 * concurrency_conflict, idempotency_key_missing, idempotency_key_reused, rate_limited, server_error, ...
 * Domain code adds its own via ApiProblem. `correlationId` echoes X-Correlation-Id.
 */
final class ProblemRenderer
{
    public const CONTENT_TYPE = 'application/problem+json';

    public static function render(Throwable $e, Request $request): JsonResponse
    {
        $status = 500;
        $code = 'server_error';
        $title = 'Internal server error';
        $detail = 'An unexpected error occurred.';
        $extensions = [];
        $headers = [];

        if ($e instanceof ApiProblem) {
            [$status, $code, $title, $detail, $extensions, $headers] = [
                $e->status, $e->problemCode, $e->title ?? $e->problemCode, $e->getMessage(), $e->extensions, $e->headers,
            ];
        } elseif ($e instanceof ValidationException) {
            $status = 422;
            $code = 'validation_failed';
            $title = 'Validation failed';
            $detail = 'One or more fields are invalid.';
            $extensions = ['errors' => $e->errors()];
        } elseif ($e instanceof AuthenticationException) {
            $expired = RequestContext::get(RequestContext::TOKEN_EXPIRED) !== null;
            [$status, $code, $title, $detail] = [401, $expired ? 'token_expired' : 'unauthenticated', 'Unauthenticated', $expired ? 'The access token has expired. Refresh it.' : 'Authentication is required.'];
            $headers = ['WWW-Authenticate' => 'Bearer'];
        } elseif ($e instanceof AuthorizationException) {
            [$status, $code, $title, $detail] = [403, 'permission_denied', 'Forbidden', 'You are not allowed to perform this action.'];
        } elseif ($e instanceof ModelNotFoundException || $e instanceof NotFoundHttpException) {
            [$status, $code, $title, $detail] = [404, 'not_found', 'Not found', 'The requested resource was not found.'];
        } elseif ($e instanceof MethodNotAllowedHttpException) {
            [$status, $code, $title, $detail] = [405, 'method_not_allowed', 'Method not allowed', 'The HTTP method is not allowed for this resource.'];
            $headers = $e->getHeaders();
        } elseif ($e instanceof ThrottleRequestsException) {
            [$status, $code, $title, $detail] = [429, 'rate_limited', 'Too many requests', 'Too many requests. Please retry later.'];
            $headers = $e->getHeaders();
        } elseif ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            $code = match ($status) {
                400 => 'validation_failed', 401 => 'unauthenticated', 403 => 'permission_denied', 404 => 'not_found',
                409, 412, 428 => 'concurrency_conflict', 413 => 'validation_failed', 415 => 'validation_failed',
                422 => 'validation_failed', 429 => 'rate_limited', default => 'server_error',
            };
            $title = Response::$statusTexts[$status] ?? 'Error';
            $detail = $e->getMessage() !== '' ? $e->getMessage() : $title;
            $headers = $e->getHeaders();
        } elseif ($e instanceof MalformedUrlException || $e instanceof \JsonException) {
            [$status, $code, $title, $detail] = [400, 'validation_failed', 'Bad request', 'Malformed request.'];
        }

        $body = array_merge([
            'type' => 'urn:r007:problem:'.$code,
            'title' => $title,
            'status' => $status,
            'code' => $code,
            'detail' => $detail,
            'instance' => '/'.ltrim($request->path(), '/'),
            'correlationId' => $request->attributes->get('correlation_id') ?? $request->header('X-Correlation-Id') ?? Ids::uuid7(),
        ], $extensions);

        if ($status >= 500 && config('app.debug')) {
            $body['debug'] = ['exception' => $e::class, 'message' => $e->getMessage(), 'file' => $e->getFile().':'.$e->getLine()];
        }

        $response = new JsonResponse($body, $status, $headers);
        $response->headers->set('Content-Type', self::CONTENT_TYPE);
        $response->headers->set('X-Correlation-Id', (string) $body['correlationId']);

        return $response;
    }
}
