# License Enforcement Implementation - Testing & Setup Guide

## Overview
This document guides you through testing the complete license enforcement system which includes:
1. **Dedicated Middleware (`BlockAdminIfLicenseInvalid`)** - Blocks sensitive admin routes when license is invalid
2. **Route Grouping** - Sensitive routes (payments, deposits, withdrawals, settings) wrapped with license check
3. **UI Enhancements** - Disabled buttons and lock icons in admin Blade templates
4. **Admin Notice** - Yellow warning banner in admin topbar when license is invalid
5. **Audit Logging** - All blocked attempts are logged to Laravel logs

## Architecture

### Files Created/Modified

#### New Files
- `app/Services/LicenseService.php` - Helper to read and normalize cached license status JSON
- `app/Http/Middleware/BlockAdminIfLicenseInvalid.php` - Dedicated middleware to forbid sensitive admin actions
- `resources/views/admin/_license_notice.blade.php` - Blade partial for the license warning banner

#### Modified Files
- `app/Http/Kernel.php` - Registered `blockinvalidlicense` route middleware
- `app/Http/Middleware/EnsureIsAdmin.php` - Enhanced to share license status with views
- `routes/admin.php` - Wrapped sensitive routes with `middleware('blockinvalidlicense')`
- `resources/views/admin/topmenu.blade.php` - Included license notice banner
- `resources/views/admin/Settings/PaymentSettings/transfers.blade.php` - Disabled Save button when license invalid
- `resources/views/admin/Settings/PaymentSettings/gateway.blade.php` - Disabled Save button when license invalid
- `resources/views/admin/appearance/index.blade.php` - Disabled Save button when license invalid
- `resources/views/admin/cards/settings.blade.php` - Disabled Save button when license invalid
- `resources/views/admin/irs-refunds/settings.blade.php` - Disabled Save button when license invalid

### Protected Routes

The following route groups now require a valid license:

**Deposit/Withdrawal Operations:**
- `dashboard/deldeposit/{id}`
- `dashboard/pdeposit/{id}`
- `dashboard/viewimage/{id}`
- `dashboard/pwithdrawal`
- `dashboard/process-withdrawal-request/{id}`

**Settings Updates:**
- `dashboard/updatesettings`, `dashboard/updateasset`, `dashboard/updatemarket`, `dashboard/updatefee`
- `dashboard/updatertransfercodes`
- `dashboard/updatewebinfo`, `dashboard/updatepreference`, `dashboard/updateemail`
- `dashboard/update-bonus`, `dashboard/other-bonus`

**Payment Settings:**
- `dashboard/updatesubfee`
- `dashboard/addwdmethod`, `dashboard/updatewdmethod`, `dashboard/edit-method/{id}`, `dashboard/delete-method/{id}`
- `dashboard/update-method`, `dashboard/paypreference`, `dashboard/updatecpd`, `dashboard/updategateway`
- `dashboard/update-transfer-settings`

**Settings View Routes:**
- `dashboard/settings/app-settings`
- `dashboard/settings/referral-settings`
- `dashboard/settings/payment-settings`
- `dashboard/settings/subscription-settings`
- `dashboard/editamount`

**Virtual Cards Management:**
- `/admin/cards` and all card routes

**IRS Refund Management:**
- `/admin/irs-refunds/*` (all IRS routes)

**Appearance Settings:**
- `/admin/appearance` and related routes

## Testing Guide

### Prerequisites
1. Admin user is logged in
2. Laravel app is running
3. `storage/app/` directory is writable

### Test Scenario 1: Invalid License (Middleware Blocks Route)

**Setup:**
```bash
# Create cache file with invalid license
mkdir -p storage/app
echo '{"status":"invalid","message":"Purchase code not verified"}' > storage/app/license_status.json
```

**Test Steps:**
1. Log in to admin dashboard
2. Observe: Yellow "License notice" banner appears at top of page
3. Try to navigate to `/admin/dashboard/settings/app-settings`
4. **Expected:** Redirected to admin dashboard with error message: "This action is disabled because the product license is not verified or is invalid. License status: Invalid"
5. Check Laravel log: `storage/logs/laravel.log` should contain:
   ```
   [TIMESTAMP] local.WARNING: Admin action blocked due to invalid license {"admin_id":"X","route":"appsettingshow",...}
   ```

### Test Scenario 2: Valid License (Routes Accessible)

**Setup:**
```bash
# Create cache file with valid license
echo '{"status":"valid","message":"OK"}' > storage/app/license_status.json
```

**Test Steps:**
1. Refresh admin dashboard
2. Observe: Yellow banner is gone
3. Navigate to `/admin/dashboard/settings/app-settings`
4. **Expected:** Page loads normally, no redirect
5. Observe: Save Settings button is enabled
6. Observe: No lock icon or disabled state

### Test Scenario 3: Missing License (Unverified)

**Setup:**
```bash
# Remove license cache file to simulate unverified state
rm -f storage/app/license_status.json
```

**Test Steps:**
1. Refresh admin dashboard
2. Observe: Yellow banner appears with "License not verified"
3. Try to access settings page
4. **Expected:** Redirected to admin dashboard with message
5. Check logs for blocked attempt

### Test Scenario 4: UI Disabled State (Blade Template)

**Setup:**
Ensure license cache shows invalid: 
```bash
echo '{"status":"invalid","message":"License expired"}' > storage/app/license_status.json
```

**Test Steps:**
1. Navigate to a settings page that loads (e.g., by allowing GET but checking POST):
   - Go to `/admin/dashboard/settings/payment-settings`
2. Observe: "Save Settings" button is grayed out (disabled)
3. Observe: Red lock icon with message "Disabled: Product license is invalid"
4. Try to click Save button
5. **Expected:** Button is non-functional (HTML disabled attribute prevents submit)

### Test Scenario 5: API Request Response (JSON)

**Setup:**
Invalid license cache file in place.

**Test Steps:**
1. Make a POST request to a blocked route with `Accept: application/json` header:
   ```bash
   curl -X POST https://yoursite.com/admin/dashboard/updatesettings \
     -H "Accept: application/json" \
     -H "X-Requested-With: XMLHttpRequest" \
     --cookie "admin_session=..." \
     -d "data=test"
   ```
2. **Expected Response (HTTP 403):**
   ```json
   {
     "error": "Access denied",
     "message": "This action is disabled because the product license is not verified or is invalid.",
     "license_status": "invalid"
   }
   ```
3. Check logs: Blocked attempt recorded with method "POST"

### Test Scenario 6: Notice Banner Appearance

**Setup:**
Invalid license cache file in place.

**Test Steps:**
1. Log in to admin
2. Open admin dashboard
3. Observe topbar (under logo)
4. **Expected:** Yellow dismissible alert box appears:
   - Title: "License notice:"
   - Message: (from cache file, e.g., "Purchase code not verified")
   - Close button (X) to dismiss
5. Click X to dismiss
6. **Expected:** Banner closes for this session

### Test Scenario 7: License Service Helper

**Test via Tinker:**
```bash
php artisan tinker
>>> use App\Services\LicenseService;
>>> LicenseService::getStatus()
=> [
  "status" => "invalid",
  "message" => "Purchase code not verified",
  "raw" => [...]
]
```

### Test Scenario 8: Middleware Ordering

**Test:**
Admin must still be authenticated to see the license check (license check comes after `isadmin` middleware):

**Test Steps:**
1. Log out admin user
2. Try to access `/admin/dashboard/settings/app-settings`
3. **Expected:** Redirect to `/admin/validate_admin` (not blocked by license middleware, because `isadmin` middleware redirects first)

## How It Works (Flow Diagram)

```
Admin Request to Protected Route
    ↓
middleware: isadmin (EnsureIsAdmin)
    ↓ (Auth check)
    ├─ Not authenticated → Redirect to /admin/validate_admin
    ├─ Authenticated → Continue, Share $licenseStatus to views
    ↓
middleware: blockinvalidlicense (BlockAdminIfLicenseInvalid)
    ↓ (License check)
    ├─ status == 'valid' → Continue to route handler
    ├─ status != 'valid' → 
    │   ├─ JSON request → Return 403 JSON
    │   └─ Web request → Redirect to admin.dashboard with flash message
    ↓
Route Handler
    ↓
Return View (with $licenseStatus shared by EnsureIsAdmin)
    ↓
Blade Template checks $licenseStatus to disable/enable buttons
```

## Logging

All blocked attempts are logged to `storage/logs/laravel.log`:

```json
{
  "message": "Admin action blocked due to invalid license",
  "context": {
    "admin_id": "1",
    "route": "appsettingshow",
    "method": "GET",
    "uri": "/admin/dashboard/settings/app-settings",
    "license_status": "invalid",
    "ip": "192.168.1.1"
  }
}
```

## License Status Cache File

Location: `storage/app/license_status.json`

**Valid License Example:**
```json
{
  "status": "valid",
  "message": "OK",
  "checked_at": "2025-11-29T10:30:00+00:00",
  "checked_by": "VerifyEnvatoLicense"
}
```

**Invalid License Example:**
```json
{
  "status": "invalid",
  "message": "Purchase code not verified",
  "checked_at": "2025-11-29T10:30:00+00:00",
  "error_details": "Code not found in Envato database"
}
```

**Unverified License (Missing File):**
When file doesn't exist, `LicenseService::getStatus()` returns:
```php
[
  'status' => 'missing',
  'message' => 'License not verified',
  'raw' => null
]
```

## Extending / Customizing

### Add More Routes to License Check

To add a route to the license-protected group, edit `routes/admin.php` and place the route inside one of these middleware groups:

```php
Route::middleware('blockinvalidlicense')->group(function () {
    Route::post('dashboard/mynewroute', [MyController::class, 'myaction'])->name('myroute');
});
```

### Change Blocking Behavior

To modify what happens when license is invalid, edit `app/Http/Middleware/BlockAdminIfLicenseInvalid.php` and adjust the logic in the `handle()` method.

### Customize UI Locked State

Update any Blade file to show/hide/disable UI based on `$licenseStatus`:

```blade
@php
    $isLicenseInvalid = isset($licenseStatus) && $licenseStatus['status'] !== 'valid';
@endphp

<button type="submit" @if($isLicenseInvalid) disabled @endif>
    Save
</button>

@if($isLicenseInvalid)
    <span class="text-danger">
        <i class="fa fa-lock"></i> Disabled: License invalid
    </span>
@endif
```

## Troubleshooting

### Notice Banner Not Appearing
1. Check that `resources/views/admin/topmenu.blade.php` includes `_license_notice.blade.php`
2. Verify admin layout uses `topmenu` partial
3. Check that license cache file exists at `storage/app/license_status.json`

### Routes Still Accessible When Should Be Blocked
1. Clear route cache: `php artisan route:cache`
2. Verify middleware is registered in `app/Http/Kernel.php`
3. Verify route is wrapped with `middleware('blockinvalidlicense')`
4. Check license cache file status

### Middleware Not Running
1. Verify `blockinvalidlicense` is registered in `app/Http/Kernel.php`
2. Check route group in `routes/admin.php` is inside the `isadmin` group
3. Clear cache: `php artisan config:clear && php artisan route:cache`

### Buttons Not Disabled
1. Verify `$licenseStatus` is being passed to Blade view
2. Check Blade syntax: ensure `isset($licenseStatus)` check is present
3. Verify license cache file contains `status != 'valid'`

## Summary

✅ **What You Have:**
- Dedicated middleware blocking sensitive admin routes
- Admin UI disables critical buttons when license invalid
- Yellow warning banner visible on all admin pages
- Audit logging of all blocked attempts
- Proper HTTP 403 responses for API requests
- Clean separation of concerns (middleware vs UI vs logging)

✅ **User Experience:**
- Admins see clear notice when license is invalid
- Attempts to access restricted areas are prevented at middleware level
- UI provides visual feedback (disabled buttons, lock icons)
- All actions are logged for security/compliance

## Questions?

Refer to the middleware implementations:
- `app/Http/Middleware/BlockAdminIfLicenseInvalid.php` - Route-level enforcement
- `app/Http/Middleware/EnsureIsAdmin.php` - View sharing logic
- `app/Services/LicenseService.php` - License status reading
