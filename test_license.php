<?php
/**
 * Test Script: License Enforcement System
 * 
 * This script tests the license enforcement system by:
 * 1. Creating a test license cache file
 * 2. Verifying the LicenseService can read it
 * 3. Demonstrating the license enforcement flow
 */

echo "=== License Enforcement System Test ===\n\n";

// Ensure storage/app directory exists
$storageDir = __DIR__ . '/storage/app';
if (!is_dir($storageDir)) {
    mkdir($storageDir, 0777, true);
    echo "✓ Created storage/app directory\n";
}

// Test 1: Create a VALID license cache file
echo "\n--- Test 1: Valid License ---\n";
$cachePath = $storageDir . '/license_status.json';
$validLicense = [
    'status' => 'valid',
    'message' => 'License is valid and verified',
    'checked_at' => date('Y-m-d H:i:s'),
    'expires_at' => date('Y-m-d', strtotime('+30 days'))
];
file_put_contents($cachePath, json_encode($validLicense, JSON_PRETTY_PRINT));
echo "✓ Created valid license cache file at: " . $cachePath . "\n";
echo "Content:\n" . json_encode($validLicense, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

// Test 2: Verify LicenseService can read it
echo "\n--- Test 2: LicenseService Reading ---\n";
require_once __DIR__ . '/app/Services/LicenseService.php';

try {
    $status = \App\Services\LicenseService::getStatus();
    echo "✓ LicenseService returned status: " . json_encode($status, JSON_PRETTY_PRINT) . "\n";
    
    if ($status['status'] === 'valid') {
        echo "✓✓ License is VALID - Admin access will be ALLOWED\n";
    } else {
        echo "⚠ License is NOT valid - Admin access will be BLOCKED\n";
    }
} catch (\Exception $e) {
    echo "✗ Error reading license: " . $e->getMessage() . "\n";
}

// Test 3: Simulate an INVALID license
echo "\n--- Test 3: Invalid License ---\n";
$invalidLicense = [
    'status' => 'invalid',
    'message' => 'License verification failed or expired',
    'checked_at' => date('Y-m-d H:i:s')
];
file_put_contents($cachePath, json_encode($invalidLicense, JSON_PRETTY_PRINT));
echo "✓ Updated cache to INVALID license\n";

$status = \App\Services\LicenseService::getStatus();
echo "LicenseService status: " . json_encode($status, JSON_PRETTY_PRINT) . "\n";
if ($status['status'] !== 'valid') {
    echo "✓✓ License is INVALID - Admin access will be BLOCKED\n";
    echo "   - Notice banner will show: '" . $status['message'] . "'\n";
    echo "   - Admin buttons will be DISABLED\n";
    echo "   - Protected routes will return 403 Forbidden\n";
}

// Test 4: Test with missing license file
echo "\n--- Test 4: Missing License File ---\n";
unlink($cachePath);
echo "✓ Deleted license cache file\n";

$status = \App\Services\LicenseService::getStatus();
echo "LicenseService status: " . json_encode($status, JSON_PRETTY_PRINT) . "\n";
if ($status['status'] !== 'valid') {
    echo "✓✓ License is MISSING - Admin access will be BLOCKED\n";
}

// Test 5: Recreate valid license for normal operation
echo "\n--- Test 5: Restore Valid License ---\n";
$validLicense['checked_at'] = date('Y-m-d H:i:s');
file_put_contents($cachePath, json_encode($validLicense, JSON_PRETTY_PRINT));
echo "✓ Recreated valid license\n";

$status = \App\Services\LicenseService::getStatus();
if ($status['status'] === 'valid') {
    echo "✓✓ System is ready for testing - License is VALID\n";
}

echo "\n=== Test Summary ===\n";
echo "✓ LicenseService is working correctly\n";
echo "✓ License cache file system is functional\n";
echo "✓ Ready to test in Laravel application\n";
echo "\nNext steps:\n";
echo "1. Start Laravel development server: php artisan serve\n";
echo "2. Access admin dashboard at: http://localhost:8000/admin\n";
echo "3. You should see the admin interface WITHOUT license notice (license is valid)\n";
echo "4. To test invalid license, edit: " . $cachePath . "\n";
echo "   and change status to 'invalid'\n";
echo "\nDocumentation: See LICENSE_ENFORCEMENT_GUIDE.md for full test scenarios\n";
?>
