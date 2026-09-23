<?php

namespace App\Support\Http;

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
 * Turns every exception into RFC 7807 problem+json with a stable `code`.
 * Stable codes: validation_failed(422) unauthenticated(401) invalid_credentials(401)
 * forbidden/permission_denied(403) not_found(404) method_not_allowed(405) conflict(409)
 * too_many_requests(429) internal_error(500). Domain code adds its own via ApiProblem.
 */
final class ProblemRenderer
{
    public const CONTENT_TYPE = 'application/problem+json';

    public static function render(Throwable $e, Request $request): JsonResponse
    {
        $status = 500;
        $code = 'internal_error';
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
            $failed = $e->validator->failed();
            $errors = [];
            foreach ($e->errors() as $field => $messages) {
                $rules = array_keys($failed[$field] ?? []);
                foreach ($messages as $i => $message) {
                    $errors[] = [
                        'field' => $field,
                        'code' => isset($rules[$i]) ? strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $rules[$i])) : 'invalid',
                        'message' => $message,
                    ];
                }
            }
            $extensions = ['errors' => $errors];
        } elseif ($e instanceof AuthenticationException) {
            [$status, $code, $title, $detail] = [401, 'unauthenticated', 'Unauthenticated', 'Authentication is required.'];
            $headers = ['WWW-Authenticate' => 'Bearer'];
        } elseif ($e instanceof AuthorizationException) {
            [$status, $code, $title, $detail] = [403, 'forbidden', 'Forbidden', 'You are not allowed to perform this action.'];
        } elseif ($e instanceof ModelNotFoundException || $e instanceof NotFoundHttpException) {
            [$status, $code, $title, $detail] = [404, 'not_found', 'Not found', 'The requested resource was not found.'];
        } elseif ($e instanceof MethodNotAllowedHttpException) {
            [$status, $code, $title, $detail] = [405, 'method_not_allowed', 'Method not allowed', 'The HTTP method is not allowed for this resource.'];
            $headers = $e->getHeaders();
        } elseif ($e instanceof ThrottleRequestsException) {
            [$status, $code, $title, $detail] = [429, 'too_many_requests', 'Too many requests', 'Too many requests. Please retry later.'];
            $headers = $e->getHeaders();
        } elseif ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            $code = match ($status) {
                400 => 'bad_request', 401 => 'unauthenticated', 403 => 'forbidden', 404 => 'not_found', 409 => 'conflict',
                413 => 'payload_too_large', 415 => 'unsupported_media_type', 422 => 'unprocessable', 429 => 'too_many_requests',
                503 => 'service_unavailable', default => 'http_error',
            };
            $title = Response::$statusTexts[$status] ?? 'Error';
            $detail = $e->getMessage() !== '' ? $e->getMessage() : $title;
            $headers = $e->getHeaders();
        } elseif ($e instanceof MalformedUrlException || $e instanceof \JsonException) {
            [$status, $code, $title, $detail] = [400, 'bad_request', 'Bad request', 'Malformed request.'];
        }

        $body = array_merge([
            'type' => 'urn:r007:problem:'.$code,
            'title' => $title,
            'status' => $status,
            'code' => $code,
            'detail' => $detail,
            'instance' => '/'.ltrim($request->path(), '/'),
        ], $extensions);

        if ($status >= 500 && config('app.debug')) {
            $body['debug'] = ['exception' => $e::class, 'message' => $e->getMessage(), 'file' => $e->getFile().':'.$e->getLine()];
        }

        $response = new JsonResponse($body, $status, $headers);
        $response->headers->set('Content-Type', self::CONTENT_TYPE);

        return $response;
    }
}
