# Architecture & Flow Diagrams

## System Architecture Overview

```
┌─────────────────────────────────────────────────────────────────────┐
│                         Admin Dashboard                             │
├─────────────────────────────────────────────────────────────────────┤
│                                                                       │
│  ┌──────────────────────────────────┐                               │
│  │   Admin Topbar (topmenu)          │                               │
│  │  ┌────────────────────────────┐   │                               │
│  │  │ 🟡 License Notice Banner   │   │  ← Includes _license_notice   │
│  │  │ "License is invalid..."    │   │                               │
│  │  └────────────────────────────┘   │                               │
│  └──────────────────────────────────┘                               │
│                                                                       │
│  ┌──────────────────────────────────┐                               │
│  │  Settings / Payment / Deposits    │                               │
│  │  Pages                            │                               │
│  │  ┌────────────────────────────┐   │                               │
│  │  │ ◀ Save Settings (Disabled) │   │  ← Blade checks $license      │
│  │  │ 🔒 License invalid         │   │     Status & disables button  │
│  │  └────────────────────────────┘   │                               │
│  └──────────────────────────────────┘                               │
│                                                                       │
└─────────────────────────────────────────────────────────────────────┘
```

## Request Flow Diagram

```
                        HTTP Request
                             │
                             ↓
                    ┌────────────────────┐
                    │   Route Dispatcher  │
                    └────────────────────┘
                             │
                    ─────────┼─────────
                    │        │        │
        ┌───────────┴──┐     │   ┌────┴──────────┐
        │ isadmin:     │     │   │ Not Protected  │
        │ Public Route │     │   │ Route          │
        └──────────────┘     │   └────────────────┘
                             │
            ┌────────────────┴─────────────────┐
            │ Protected Route (sensitive admin) │
            └─────────────────────────────────┘
                             │
                             ↓
            ┌────────────────────────────────┐
            │ Middleware: isadmin            │
            │ ┌──────────────────────────┐  │
            │ │ Check: Auth::guard('admin')  │
            │ └──────────────────────────┘  │
            │ ┌──────────────────────────┐  │
            │ │ Share: $licenseStatus    │  │ ← Read from JSON
            │ │ to all admin views       │  │
            │ └──────────────────────────┘  │
            └────────────────────────────────┘
                             │
                    ┌────────┴──────────┐
                    │ Not Admin?        │ Yes
                    │ Redirect to login │ → (Request blocked)
                    │                   │
                    No                  
                    ↓
            ┌────────────────────────────────┐
            │ Middleware: blockinvalidlicense │
            │ ┌──────────────────────────┐  │
            │ │ Read: license_status.    │  │ ← From storage/app/
            │ │ json                     │  │
            │ └──────────────────────────┘  │
            │ ┌──────────────────────────┐  │
            │ │ Check: status == 'valid' │  │
            │ └──────────────────────────┘  │
            │ ┌──────────────────────────┐  │
            │ │ Log: All blocked attempts│  │ → to Laravel log
            │ └──────────────────────────┘  │
            └────────────────────────────────┘
                    ┌────────┬──────────┐
         Status=    │        │          │ Status≠Valid
         Valid?     │ Yes    │          │
                    ↓        ↓          ↓
             Continue   ┌─────────┐  Is JSON?
                        │ Block!  │
                    ┌───┴────┬────┴──┐
                    │ Yes    │ No    │
                    ↓        ↓       
              Return     Redirect 
              403 JSON   to dashboard
              (API)      with message
                         (Web)
                    │        │
                    └────┬───┘
                         │
                         ↓
         ┌────────────────────────────────┐
         │ Route Handler / View Renderer  │
         │                                │
         │ Pass: $licenseStatus           │
         └────────────────────────────────┘
                         │
                         ↓
         ┌────────────────────────────────┐
         │ Blade Template Processing      │
         │ @php                           │
         │  $isInvalid = status !== valid │
         │ @endphp                        │
         │ <button @disabled($isInvalid)> │
         │   Save                         │
         │ </button>                      │
         │ @if($isInvalid)                │
         │   🔒 Disabled: License invalid │
         │ @endif                         │
         └────────────────────────────────┘
                         │
                         ↓
         ┌────────────────────────────────┐
         │ HTML Response Sent to Browser  │
         │ • Notice banner in topbar      │
         │ • Buttons disabled or enabled  │
         │ • Lock icons where applicable  │
         └────────────────────────────────┘
                         │
                         ↓
                  Admin Sees Page
```

## License Status State Machine

```
                    ┌─────────────────────────┐
                    │                         │
                    ↓                         │
            ┌──────────────────┐             │
            │   MISSING        │             │
            │ (No cache file)  │             │
            └──────────────────┘             │
                    │                        │
         (verification middleware runs)      │
         (POSTs purchase code to server)     │
                    │                        │
         ┌──────────┴──────────┐             │
         │                     │             │
         ↓                     ↓             │
    ┌─────────┐          ┌─────────┐        │
    │ VALID   │          │ INVALID │        │
    │ (1,000+ │          │ (Envato │        │
    │  uses)  │          │  API    │        │
    └─────────┘          │  error) │        │
         │                └─────────┘        │
         │ (Admin tries                      │
         │  protected                        │
         │  route)                           │
         │ License check: OK                 │
         ↓                                   │
    ┌─────────────────────┐                 │
    │ Route Handler       │                 │
    │ Processes normally  │                 │
    │ (Request allowed)   │                 │
    │                     │                 │
    │ Blade renders with: │                 │
    │ status = 'valid'    │                 │
    │ buttons = enabled   │                 │
    │ no lock icon        │                 │
    └─────────────────────┘                 │
                                            │
         (Admin tries                       │
         protected route)                   │
         │                                  │
         └──────────────────────────────────┘
              License check: FAILED
                   ↓
           ┌──────────────────┐
           │ BlockMiddleware  │
           │ Intercepts req   │
           │                  │
           │ Is JSON req?     │
           │   Yes: return 403│
           │   No: redirect   │
           │   + flash msg    │
           └──────────────────┘
                   │
                   ↓
        ┌────────────────────┐
        │ Admin sees:        │
        │ • Redirected to    │
        │   dashboard        │
        │ • Error message    │
        │ • If GET was logged│
        │   to log file      │
        └────────────────────┘


         (Can also happen if Envato API down)
              Status = ERROR/UNVERIFIED
                      ↓
           ┌──────────────────┐
           │ BlockMiddleware  │
           │ Treats as        │
           │ INVALID          │
           │ (safe default)   │
           │                  │
           │ Blocks request   │
           └──────────────────┘
```

## Middleware Stack (Protected Admin Route)

```
Request to: /admin/dashboard/settings/app-settings
(GET or POST)
│
├─ Global Middleware (web group)
│  ├─ EncryptCookies
│  ├─ StartSession
│  ├─ VerifyCsrfToken
│  ├─ BlockIpAddressMiddleware
│  ├─ AppearanceSettingsMiddleware
│  └─ VerifyEnvatoLicense ← Checks license every 7 days
│
├─ Route Middleware: 'isadmin'
│  ├─ EnsureIsAdmin.php
│  │  ├─ Check: Auth::guard('admin')->check()
│  │  ├─ If not auth → Redirect to /admin/validate_admin
│  │  ├─ If auth → Read license status from JSON
│  │  └─ Share: View::share('licenseStatus', $license)
│  └─ Continue
│
├─ Route Middleware: '2fa'
│  ├─ Check two-factor authentication
│  └─ Continue
│
├─ Route Middleware (NEW): 'blockinvalidlicense'
│  ├─ BlockAdminIfLicenseInvalid.php
│  ├─ Read: storage/app/license_status.json
│  ├─ If status != 'valid':
│  │  ├─ Log: blocked attempt
│  │  ├─ If JSON → 403 JSON response
│  │  └─ If web → Redirect to admin.dashboard
│  ├─ If status == 'valid':
│  │  └─ Continue to route handler
│  └─ Route Handler (allowed to run)
│
└─ View Rendered
   ├─ Receives: $licenseStatus from EnsureIsAdmin
   ├─ Template checks: isset($licenseStatus) && status != 'valid'
   ├─ If invalid:
   │  ├─ Disable: Save button (HTML disabled attr)
   │  ├─ Show: 🔒 Icon
   │  └─ Show: "Disabled: License invalid" message
   └─ If valid:
      ├─ Enable: Save button
      ├─ Hide: lock icon
      └─ Hide: message
```

## File System Layout (License Cache)

```
project/
├─ storage/
│  └─ app/
│     ├─ license_status.json ← Updated by VerifyEnvatoLicense
│     │                        Used by BlockAdminIfLicenseInvalid
│     │                        Read by EnsureIsAdmin
│     │
│     └─ Example valid file:
│        {
│          "status": "valid",
│          "message": "OK",
│          "checked_at": "2025-11-29T10:30:00+00:00",
│          "checked_by": "VerifyEnvatoLicense"
│        }
│
│     └─ Example invalid file:
│        {
│          "status": "invalid",
│          "message": "Purchase code not verified",
│          "checked_at": "2025-11-29T10:30:00+00:00",
│          "error_details": "Code not found in Envato database"
│        }
│
└─ logs/
   └─ laravel.log ← Blocked attempts logged here
```

## Class Interaction Diagram

```
┌──────────────────────────────────────────────────────────────────┐
│                     Request Processing                           │
└──────────────────────────────────────────────────────────────────┘

                    ┌─────────────────────────┐
                    │  EnsureIsAdmin          │
                    │  (Middleware)           │
                    │                         │
                    │  handle(Request, Next)  │
                    │  ├─ Check auth         │
                    │  ├─ Share $license     │
                    │  └─ Call Next()        │
                    └────────────┬────────────┘
                                 │
                    ┌────────────┴─────────────┐
                    │                          │
                    ↓                          ↓
        ┌──────────────────────┐  ┌──────────────────────────┐
        │ LicenseService       │  │ BlockAdminIfLicenseInvalid
        │ (Helper Class)       │  │ (Middleware)             │
        │                      │  │                          │
        │ getStatus()          │  │ handle(Request, Next)    │
        │ ├─ File exists?      │  │ ├─ Call LicenseService  │
        │ ├─ Read JSON         │  │ ├─ Check status         │
        │ ├─ Decode            │  │ ├─ Log if blocked       │
        │ └─ Normalize         │  │ ├─ Return 403 or...     │
        │   status             │  │ ├─ Redirect or...       │
        │                      │  │ └─ Call Next()          │
        └──────────────────────┘  │                          │
                   ▲               └──────────────────────────┘
                   │                          │
                   └──────────────┬───────────┘
                                  │ Calls
                       ┌──────────┴──────────┐
                       │                     │
                       ↓                     ↓
              Both methods use:
              • storage_path()
              • file_exists()
              • file_get_contents()
              • json_decode()
              • Log::warning()
              • View::share()


Blade Templates:
├─ resources/views/admin/_license_notice.blade.php
│  └─ Displays: $licenseStatus (shared by EnsureIsAdmin)
│
├─ resources/views/admin/Settings/PaymentSettings/transfers.blade.php
│  └─ Checks: isset($licenseStatus) && status != 'valid'
│     Disables: Save button
│
└─ (Similar for other 4 settings pages)
```

## Error Handling Flow

```
Protected Route Request
│
├─ Is User Admin?
│  ├─ No → Return 302 Redirect to /admin/validate_admin
│  └─ Yes → Continue
│
├─ Can Read License Cache?
│  ├─ File missing → Treat as 'missing' status
│  ├─ File corrupt → Treat as 'error' status  
│  ├─ Parse fails → Treat as 'error' status
│  └─ Success → Use actual status
│
├─ Is License Valid?
│  ├─ status == 'valid' → Allow request to continue
│  └─ status != 'valid' → Block
│
└─ If Blocked:
   ├─ Log attempt: admin_id, route, ip, method, uri, status
   ├─ Is JSON request?
   │  ├─ Yes → Return HTTP 403 with JSON:
   │  │        {
   │  │          "error": "Access denied",
   │  │          "message": "...",
   │  │          "license_status": "invalid"
   │  │        }
   │  └─ No → Return 302 Redirect to /admin/dashboard
   │         with flash: "This action is disabled..."
   │
   └─ View receives $licenseStatus (even on blocked routes
      that somehow render)
      ├─ Blade checks: isset($licenseStatus) 
      ├─ If invalid: Disable buttons, show lock icons
      └─ If valid: Enable buttons, hide icons
```

## Logging Output Example

When admin tries to access blocked route:

```
[2025-11-29 10:30:45] local.WARNING: Admin action blocked due to invalid license {
  "admin_id": "1",
  "route": "appsettingshow",
  "method": "GET",
  "uri": "/admin/dashboard/settings/app-settings",
  "license_status": "invalid",
  "ip": "192.168.1.100"
}

[2025-11-29 10:31:12] local.WARNING: Admin action blocked due to invalid license {
  "admin_id": "2",
  "route": "addpaymethod",
  "method": "POST",
  "uri": "/admin/dashboard/addwdmethod",
  "license_status": "invalid",
  "ip": "192.168.1.100"
}
```

---

These diagrams help visualize:
- Overall architecture and components
- Request/response flow through middleware stack
- State transitions for license status
- Class interactions and data flow
- Error handling paths
- Logging output examples

Reference these diagrams when:
- Debugging license enforcement issues
- Onboarding new developers
- Extending the system with new protected routes
- Understanding middleware execution order
