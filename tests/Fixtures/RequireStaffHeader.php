<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Tests\Fixtures;

use Closure;
use Illuminate\Http\Request;

/** Stands in for an app's own guard (auth, a role check) on the signed PDF route. */
class RequireStaffHeader
{
    public function handle(Request $request, Closure $next): mixed
    {
        abort_unless($request->header('X-Staff') === 'yes', 401);

        return $next($request);
    }
}
