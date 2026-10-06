<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsAdmin
{
    /**
     * Abort with a 403 response unless the authenticated user is an active admin.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            abort(403);
        }

        $deniedReason = $user->adminAccessDeniedReason();

        if ($deniedReason !== null) {
            abort(403, $deniedReason);
        }

        return $next($request);
    }
}
