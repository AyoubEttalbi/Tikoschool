<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * A 403 that the codebase's catch-all handlers cannot swallow.
 *
 * WHY THIS EXTENDS \Error AND NOT \Exception
 * ------------------------------------------
 * 17 of ~20 controllers wrap their whole method body in:
 *
 *     try { ... } catch (\Exception $e) { return redirect()->back()->with('error', ...); }
 *
 * There are 92 such blocks across 24 files. Laravel's abort(403) throws
 * Symfony's HttpException, which extends \RuntimeException extends \Exception — so
 * every one of those blocks converts an authorization denial into a harmless 302
 * redirect or a 500. A guard that silently 302s looks exactly like a guard that
 * worked, which is why this had gone unnoticed.
 *
 * PHP's two Throwable roots are disjoint: \Error does NOT extend \Exception. Throwing
 * from the \Error side means `catch (\Exception $e)` cannot see it, so the denial
 * reaches Laravel's handler intact — without editing 92 call sites, and without
 * depending on every future controller remembering to add a re-throw.
 *
 * This is deliberate, not a mistake. Do not "fix" it back to \Exception.
 *
 * Everything else still behaves correctly, because the framework catches \Throwable,
 * not \Exception, at every layer that matters:
 *
 *   - Illuminate\Foundation\Exceptions\Handler::render(Request, Throwable) renders it,
 *     and isHttpException() matches on HttpExceptionInterface (implemented below), so
 *     it renders as a real 403 with the right status and headers.
 *   - DB::transaction() catches \Throwable, so an open transaction still rolls back.
 *   - The two existing `catch (HttpExceptionInterface $e) { throw $e; }` re-throws in
 *     ResultsController and elsewhere keep working, since this implements that interface.
 *
 * @see \App\Support\SchoolScope
 * @see \App\Http\Middleware\RequireRole
 */
class AccessDeniedException extends \Error implements HttpExceptionInterface
{
    public function __construct(
        private readonly string $reason = "Vous n'avez pas accès à cette ressource.",
        private readonly int $status = 403,
        private readonly array $headers = [],
    ) {
        parent::__construct($reason);
    }

    public function getStatusCode(): int
    {
        return $this->status;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }
}
