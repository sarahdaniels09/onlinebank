<?php

namespace App\Http\Middleware;

use Closure;
use App\Models\Admin;
use App\Models\Settings;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use App\meta;
use App\Services\LicenseService;
use Illuminate\Support\Facades\View;
class EnsureIsAdmin
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $api = new meta();
        
        if (Auth::guard('admin')->check()) {
            // Share license status with admin views so a notice can be shown in the UI
            try {
                $license = LicenseService::getStatus();
            } catch (\Throwable $e) {
                $license = [
                    'status' => 'error',
                    'message' => 'License check failed',
                    'raw' => null,
                ];
            }

            // make available to all views for admin pages
            View::share('licenseStatus', $license);

            // If license is not valid, block dangerous admin actions
            $notAllowedWhenInvalid = [
                // settings
                'settings', 'updatesettings', 'updateasset', 'updatemarket', 'updatefee', 'appsettingshow', 'refsetshow', 'paymentview', 'subview',
                // payment methods
                'addpaymethod', 'updatewdmethod', 'editpaymethod', 'deletepaymethod', 'updatemethod', 'paypreference', 'updatecpd', 'updategateway', 'updatetransfer',
                // deposits & withdrawals
                'deldeposit', 'pdeposit', 'viewdepositimage', 'pwithdrawal', 'processwithdraw', 'mwithdrawals', 'mdeposits', 'editamount',
                // appearance/settings pages
                'appsettingshow', 'admin.appearance', 'admin.cards.settings'
            ];

            $currentRouteName = optional($request->route())->getName();

            // If license status is not "valid", prevent access to destructive/admin financial routes
            if (isset($license['status']) && $license['status'] !== 'valid') {
                if ($currentRouteName && in_array($currentRouteName, $notAllowedWhenInvalid)) {
                    // redirect to admin dashboard with a clear message
                    return redirect()->route('admin.dashboard')->with('danger', 'This action is disabled because the product license is not verified or is invalid.');
                }
            }

            return $next($request);
        } else {
            return redirect()->route('validate_admin');
        }
    }
}
