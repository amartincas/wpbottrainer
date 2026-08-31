<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CheckTenantSetup
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        // Verification for cronjob
        if (app()->runningInConsole()) {
            return $next($request);
        }

       // Skip checks for WhatsApp webhook routes
        if ($request->is('api/whatsapp/webhook/*')) {
            return $next($request);
        }

        // Only check for authenticated users accessing Filament
        if (Auth::check() && $request->path() !== 'yes/tenant-settings') {
            $user = Auth::user();

            // Check if user's tenant has wa_access_token configured
            if ($user->tenant && empty($user->tenant->wa_access_token)) {
                // Save the incomplete setup flag in session
                session(['tenant_setup_incomplete' => true]);
            } else {
                session(['tenant_setup_incomplete' => false]);
            }
        }

        return $next($request);
    }
}
