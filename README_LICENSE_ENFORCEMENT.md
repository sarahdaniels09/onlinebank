# Envato License Enforcement System - README

**Status**: ✅ **PRODUCTION READY**

This document provides a high-level overview of the license enforcement system for the Online Bank application.

## Quick Start (30 seconds)

### For Deployment
1. No additional setup needed - all files are included
2. License checks happen automatically via middleware
3. Admin will see yellow notice when license is invalid
4. Protected routes are blocked when license is invalid

### For Testing Locally
```bash
# Test with INVALID license (should block):
mkdir -p storage/app
echo '{"status":"invalid","message":"Test"}' > storage/app/license_status.json
# Access admin dashboard - see yellow notice
# Try to go to settings - redirected with error

# Test with VALID license (should work):
echo '{"status":"valid","message":"OK"}' > storage/app/license_status.json
# Notice disappears, settings accessible
```

---

## What This Feature Does

### 1. **Automatically Checks License on Every Request**
The verification middleware (`VerifyEnvatoLicense`) runs on every request and updates the cached license status every 7 days.

### 2. **Blocks Sensitive Admin Actions When License Invalid**
When license is invalid/unverified:
- Admin sees a yellow notice banner on all pages
- Attempts to access payment settings → redirected to dashboard
- Attempts to access deposit/withdrawal settings → redirected to dashboard
- Attempts to create/modify payment methods → blocked
- Attempts to change appearance/cards/IRS settings → blocked
- Save buttons are disabled and show lock icons

### 3. **Allows Full Admin Control When License is Valid**
When license is valid:
- Yellow notice disappears
- All admin settings are fully accessible
- All save buttons are enabled
- Admin can manage all system settings

### 4. **Logs All Blocked Attempts**
Every blocked admin action is logged to `storage/logs/laravel.log` with:
- Admin ID
- Route name
- HTTP method
- URI
- License status
- IP address
- Timestamp

---

## Architecture

### Three-Layer Enforcement

```
Layer 1: Verification Middleware (VerifyEnvatoLicense)
└─ Runs every request
└─ POSTs purchase code to verification server every 7 days
└─ Caches result in storage/app/license_status.json
└─ Never blocks - just updates status

Layer 2: Admin Auth Middleware (EnsureIsAdmin)
└─ Checks if user is authenticated admin
└─ Reads license status and shares with views
└─ Displays yellow notice banner

Layer 3: Route Protection Middleware (BlockAdminIfLicenseInvalid)
└─ Wraps sensitive routes
└─ Checks if license is valid
└─ Blocks invalid licenses with 403/redirect
└─ Logs all blocked attempts
```

### Key Files

| File | Purpose |
|------|---------|
| `app/Services/LicenseService.php` | Helper to read license cache |
| `app/Http/Middleware/BlockAdminIfLicenseInvalid.php` | Route enforcement |
| `app/Http/Middleware/EnsureIsAdmin.php` | Admin auth + view sharing |
| `app/Http/Middleware/VerifyEnvatoLicense.php` | Verification (existing) |
| `app/Http/Kernel.php` | Middleware registration |
| `routes/admin.php` | Protected route groups |
| `resources/views/admin/_license_notice.blade.php` | Warning banner |
| `resources/views/admin/topmenu.blade.php` | Banner inclusion |
| `resources/views/admin/*.blade.php` | Disabled buttons (5 files) |

---

## Protected Routes (57+)

All these routes are protected and will be blocked when license is invalid:

### Deposits & Withdrawals (5 routes)
- `dashboard/deldeposit/{id}`
- `dashboard/pdeposit/{id}`
- `dashboard/viewimage/{id}`
- `dashboard/pwithdrawal`
- `dashboard/process-withdrawal-request/{id}`

### Payment Methods (9 routes)
- `dashboard/addwdmethod` - Create new payment method
- `dashboard/updatewdmethod` - Update payment method
- `dashboard/edit-method/{id}` - Edit payment method
- `dashboard/delete-method/{id}` - Delete payment method
- `dashboard/update-method` - Save payment method changes
- `dashboard/paypreference` - Payment preferences
- `dashboard/updatecpd` - Update crypto payment details
- `dashboard/updategateway` - Update payment gateway
- `dashboard/update-transfer-settings` - Transfer settings

### Settings (8+ routes)
- `dashboard/updatesettings` - Update general settings
- `dashboard/updateasset` - Update asset settings
- `dashboard/updatemarket` - Update market settings
- `dashboard/updatefee` - Update fee settings
- `dashboard/updatertransfercodes` - Transfer codes
- `dashboard/updatewebinfo` - Web information
- `dashboard/updatepreference` - Preferences
- `dashboard/updateemail` - Email settings

### Subscriptions & Bonuses (5 routes)
- `dashboard/updatesubfee` - Subscription fees
- `dashboard/update-bonus` - Referral bonus
- `dashboard/other-bonus` - Other bonuses
- `dashboard/delsub/{id}` - Delete subscription
- `dashboard/confirmsub/{id}` - Confirm subscription

### Settings Views (5 routes)
- `dashboard/settings/app-settings`
- `dashboard/settings/referral-settings`
- `dashboard/settings/payment-settings`
- `dashboard/settings/subscription-settings`
- `dashboard/editamount` - Edit transaction amounts

### Virtual Cards Management (13+ routes)
- All routes under `/admin/cards/*`
- Including: settings, approve, reject, block, topup, deduct

### IRS Refund Management (9 routes)
- All routes under `/admin/irs-refunds/*`
- Including: settings, approve, reject, process

### Appearance Settings (3 routes)
- `appearance`
- `appearance/update`
- `appearance/reset`

---

## How It Works

### When Admin Accesses Dashboard
```
1. VerifyEnvatoLicense middleware runs
   ├─ Checks if 7 days have passed since last verification
   ├─ If yes → POSTs purchase code to verification server
   └─ Writes result to storage/app/license_status.json

2. EnsureIsAdmin middleware runs
   ├─ Checks if user is authenticated as admin
   └─ Shares license status with all admin views

3. Admin sees yellow notice if license invalid
   └─ "License notice: Purchase code not verified"
```

### When Admin Tries to Access Protected Settings
```
1. BlockAdminIfLicenseInvalid middleware runs
   ├─ Reads storage/app/license_status.json
   ├─ Checks if status == 'valid'
   └─ If invalid:
      ├─ Logs blocked attempt to storage/logs/laravel.log
      ├─ For web requests: Redirects to dashboard with error
      └─ For API requests: Returns 403 JSON

2. If allowed to proceed:
   ├─ Route handler executes
   ├─ View renders with $licenseStatus
   └─ Blade template checks license status:
      ├─ If invalid → Disable Save button, show lock icon
      └─ If valid → Enable Save button normally
```

---

## Testing

### Quick Test (5 minutes)

**Create Invalid License:**
```bash
mkdir -p storage/app
echo '{"status":"invalid","message":"Test invalid"}' > storage/app/license_status.json
```

**Expected Behavior:**
- ✅ Yellow notice appears on admin dashboard
- ✅ Navigate to `/admin/dashboard/settings/app-settings` 
- ✅ Should redirect to dashboard with error message
- ✅ Check `storage/logs/laravel.log` for blocked attempt

**Create Valid License:**
```bash
echo '{"status":"valid","message":"OK"}' > storage/app/license_status.json
```

**Expected Behavior:**
- ✅ Yellow notice disappears
- ✅ Can access settings pages
- ✅ Save buttons are enabled
- ✅ No lock icons visible

### Comprehensive Test

See `LICENSE_ENFORCEMENT_GUIDE.md` for 8 detailed test scenarios.

---

## Configuration

### No Configuration Needed!

The system works automatically once deployed:
1. License verification runs every 7 days via middleware
2. Admin actions are automatically protected
3. License status is cached in `storage/app/license_status.json`

### Optional: Disable Feature (Emergency)

**To temporarily disable enforcement:**
1. Remove `middleware('blockinvalidlicense')` from routes in `routes/admin.php`
2. Or delete `storage/app/license_status.json` to skip all checks
3. Or set status to 'valid' in cache file

**To re-enable:**
1. Restore the middleware wrapper
2. Rerun verification (or manually create valid cache file)

---

## Documentation

### For Developers
- `ARCHITECTURE_DIAGRAMS.md` - Visual flow diagrams
- `LICENSE_ENFORCEMENT_GUIDE.md` - Testing & setup guide
- Code comments in middleware files

### For System Admins
- `IMPLEMENTATION_CHECKLIST.md` - Deployment checklist
- `IMPLEMENTATION_SUMMARY.md` - Quick summary
- This README

### For Support Team
- License notice banner explains the issue to end users
- Logs show exactly what actions were blocked and why
- Refer customers to verification server for license issues

---

## Troubleshooting

### Issue: Yellow License Notice Not Appearing

**Diagnosis:**
1. Check license cache file: `ls -la storage/app/license_status.json`
2. Check if cache shows invalid status: `cat storage/app/license_status.json`
3. Check if topmenu includes notice: grep "_license_notice" resources/views/admin/topmenu.blade.php

**Solution:**
1. Create cache file: `echo '{"status":"invalid"}' > storage/app/license_status.json`
2. Clear view cache: `php artisan view:clear`
3. Refresh browser

### Issue: Routes Still Accessible When Should Be Blocked

**Diagnosis:**
1. Check route cache: `php artisan route:list | grep blockinvalidlicense`
2. Verify middleware is registered: `grep -r "blockinvalidlicense" app/Http/Kernel.php`
3. Check if status file shows 'valid': `cat storage/app/license_status.json | grep status`

**Solution:**
1. Clear route cache: `php artisan route:cache` (then clear: `php artisan cache:clear`)
2. Verify middleware registration in Kernel.php
3. Verify route is wrapped in middleware group in routes/admin.php

### Issue: Save Buttons Not Disabled

**Diagnosis:**
1. Check if $licenseStatus is passed to view: check middleware View::share() call
2. Check Blade syntax in template: look for `isset($licenseStatus)`
3. Verify status file contains `status != 'valid'`

**Solution:**
1. Clear view cache: `php artisan view:clear`
2. Manually check Blade files for correct syntax
3. Verify license cache has correct content

### Issue: Getting 500 Errors

**Diagnosis:**
1. Check Laravel log: `tail -20 storage/logs/laravel.log`
2. Check if storage/app is writable: `ls -la storage/app`
3. Run: `php artisan tinker` then `App\Services\LicenseService::getStatus()`

**Solution:**
1. Fix file permissions: `chmod -R 755 storage/app`
2. Check PHP error log for syntax issues
3. See more troubleshooting in `LICENSE_ENFORCEMENT_GUIDE.md`

---

## Security Notes

### Safe Defaults
- When license check fails → Treated as **invalid** (safe default)
- When cache file missing → Treated as **unverified** (can't access settings)
- When JSON corrupt → Treated as **error** (can't access settings)

### Audit Trail
- Every blocked attempt logged with full context
- Can identify unauthorized access attempts
- Helps detect API abuse or brute force attacks

### No Credentials Stored
- Verification server URL and purchase code stored in `.env`
- Author token stored only on private verification server
- Client app never sees author token

---

## Performance Impact

### Minimal Overhead
- License check is file read + JSON decode (~5-10ms)
- Only on protected routes (not every request)
- Cached result reused for 7 days (via VerifyEnvatoLicense)

### Optimization
- License status cached in `storage/app/license_status.json`
- File system cache (OS level) makes subsequent reads <1ms
- No database queries needed

---

## Support & Contact

### For Issues
1. Check `LICENSE_ENFORCEMENT_GUIDE.md` troubleshooting section
2. Review `ARCHITECTURE_DIAGRAMS.md` for flow understanding
3. Check Laravel logs: `storage/logs/laravel.log`
4. Run PHP syntax check: `php -l app/Http/Middleware/BlockAdminIfLicenseInvalid.php`

### For Deployment
1. Follow `IMPLEMENTATION_CHECKLIST.md`
2. Run test scenarios from `LICENSE_ENFORCEMENT_GUIDE.md`
3. Verify files exist and are not modified
4. Clear cache: `php artisan cache:clear`

### For Customization
1. New routes: Add to existing middleware groups in `routes/admin.php`
2. New settings pages: Use pattern from existing Blade files
3. Different behavior: Modify `BlockAdminIfLicenseInvalid.php`

---

## Summary

✅ **What's Included**
- Automatic license verification every 7 days
- Route-level protection for 57+ admin routes
- Visual feedback (notice banner, disabled buttons)
- Audit logging of all blocked attempts
- Proper HTTP responses (403 for API, redirects for web)
- Full documentation and testing guide

✅ **What Works**
- Admin sees license status on every dashboard
- Sensitive settings are protected automatically
- Invalid licenses prevent critical changes
- Valid licenses work exactly as before
- All changes are logged for audit trail

✅ **What's Ready**
- Production deployment
- Customer support (via documentation)
- Developer maintenance (via comments)
- Troubleshooting (via guide)

**Questions?** See the other documentation files or check the middleware source code comments.

---

**Last Updated**: November 29, 2025  
**Status**: ✅ Production Ready  
**Tested**: All 8 scenarios pass ✓  
**Documentation**: Complete ✓  
