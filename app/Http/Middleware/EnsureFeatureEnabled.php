<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Routes of a feature kept for later answer 404 while it is switched off (`config/brevon.php` › features; D-164). */
class EnsureFeatureEnabled
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        abort_unless((bool) config("brevon.features.{$feature}", false), 404);

        return $next($request);
    }
}
