<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Services\LicenseService;
use Illuminate\Support\Facades\Log;

class BlockAdminIfLicenseInvalid
{
    /**
     * Handle an incoming request.
     * Prevents access to sensitive admin routes when license is not valid.
     * Returns 403 Forbidden for API requests, redirects for web requests.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        try {
            $license = LicenseService::getStatus();
        } catch (\Throwable $e) {
            Log::error('License check failed in BlockAdminIfLicenseInvalid', [
                'error' => $e->getMessage(),
                'route' => $request->route()?->getName(),
            ]);
            $license = ['status' => 'error', 'message' => 'License check failed'];
        }

        // If license is not valid, deny access
        if (isset($license['status']) && $license['status'] !== 'valid') {
            $admin = optional($request->user('admin'))->id ?? 'unknown';
            $routeName = $request->route()?->getName() ?? 'unknown';
            
            Log::warning('Admin action blocked due to invalid license', [
                'admin_id' => $admin,
                'route' => $routeName,
                'method' => $request->getMethod(),
                'uri' => $request->getPathInfo(),
                'license_status' => $license['status'],
                'ip' => $request->ip(),
            ]);

            // Return 403 for API calls, redirect for web
            if ($request->expectsJson()) {
                return response()->json([
                    'error' => 'Access denied',
                    'message' => 'This action is disabled because the product license is not verified or is invalid.',
                    'license_status' => $license['status'],
                ], 403);
            }

            return redirect()->route('admin.dashboard')
                ->with('danger', 'This action is disabled because the product license is not verified or is invalid. License status: ' . ucfirst($license['status']));
        }

        return $next($request);
    }
}
