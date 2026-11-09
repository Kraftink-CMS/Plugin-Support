<?php

namespace KraftInk\Plugins\Support\Http\Middleware;

use App\Http\Middleware\RequireAdminTwoFactor;
use Closure;
use Illuminate\Http\Request;
use KraftInk\Plugins\Support\Services\SupportAccess;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class RequireTicketAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (!app(SupportAccess::class)->canAccessTickets($user)) {
            throw new HttpException(403);
        }

        return $user->hasPermission('support.view')
            ? $next($request)
            : app(RequireAdminTwoFactor::class)->enforce($request, $next);
    }
}
