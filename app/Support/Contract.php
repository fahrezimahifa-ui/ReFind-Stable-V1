<?php

namespace App\Support;

use App\Exceptions\ContractError;
use App\Foundation\WriteFailure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class Contract
{
    public static function headers(bool $json = true): array
    {
        $h = ['Cache-Control' => 'no-store, private', 'Pragma' => 'no-cache', 'X-Content-Type-Options' => 'nosniff', 'Referrer-Policy' => 'same-origin', 'X-Frame-Options' => 'DENY'];
        if (! $json) {
            $h['Content-Security-Policy'] = "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data: blob:; connect-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'";
        }

        return $h;
    }

    public static function json(array $data = [], int $status = 200)
    {
        return response()->json(['ok' => true] + $data, $status, self::headers(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function error(\Throwable $error, Request $request)
    {
        if ($error instanceof ValidationException) {
            $error = new ContractError('Please check the highlighted fields.', 422, 'validation_error', array_map(fn ($messages) => $messages[0], $error->errors()));
        } elseif ($error instanceof AuthenticationException) {
            $error = new ContractError('Please log in to continue.', 401, 'authentication_required');
        } elseif ($error instanceof AuthorizationException) {
            $error = new ContractError('This action is not available to your role.', 403, 'forbidden');
        }
        $status = $error instanceof ContractError ? $error->status : ($error instanceof HttpExceptionInterface ? $error->getStatusCode() : 500);
        $code = $error instanceof ContractError ? $error->errorCode : match ($status) {
            404 => 'not_found',405 => 'method_not_allowed',default => 'server_error'
        };
        $message = $status === 500 ? ($error instanceof WriteFailure && $error->uncertain ? 'The outcome needs verification. Reload before attempting another change.' : 'The request could not be completed. Please try again.') : ($error instanceof ContractError ? $error->getMessage() : match ($status) {
            404 => 'Page or resource not found.',405 => 'Method not allowed.',default => 'Request failed.'
        });
        $headers = $error instanceof ContractError ? $error->headers : ($error instanceof HttpExceptionInterface ? $error->getHeaders() : []);
        if ($request->is('api/*') && isset($headers['Allow'])) {
            $headers['Allow'] = implode(', ', array_values(array_diff(explode(', ', $headers['Allow']), ['HEAD'])));
        }
        if ($request->is('api/*')) {
            $data = ['ok' => false, 'code' => $code, 'message' => $message];
            if ($error instanceof ContractError && $error->fields) {
                $data['fields'] = $error->fields;
            }

            return response()->json($data, $status, $headers + self::headers());
        }

        return response()->view('message', ['TITLE' => 'Unable to open page', 'MESSAGE' => $message, 'BACK_URL' => '/index.html', 'BASE_PATH' => '/'], $status, $headers + self::headers(false));
    }

    public static function allowed(array $data, array $fields): void
    {
        if (array_diff(array_keys($data), $fields)) {
            throw new ContractError('Unsupported request field.', 400, 'invalid_request');
        }
    }

    public static function id(mixed $value): int
    {
        if ((! is_string($value) && ! is_int($value)) || ! preg_match('/^[1-9][0-9]*$/D', (string) $value) || (float) $value > 4294967295) {
            throw new ContractError('Invalid record ID.', 400, 'invalid_id');
        }

        return (int) $value;
    }

    public static function text(array $data, string $key, int $max, bool $required = true): string
    {
        $v = $data[$key] ?? '';
        if (! is_string($v) || ! mb_check_encoding($v, 'UTF-8')) {
            throw new ContractError('Invalid field: '.$key.'.');
        }$v = trim($v);
        if (($required && $v === '') || mb_strlen($v) > $max) {
            throw new ContractError('Check the '.$key.' field.');
        }

        return $v;
    }
}
