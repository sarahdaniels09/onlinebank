<?php

namespace App\Services;

class LicenseService
{
    /**
     * Read cached license status from storage and return a normalized array.
     * Returns: [ 'status' => 'valid'|'invalid'|'unverified'|'error'|'missing', 'message' => string, 'raw' => array|null ]
     */
    public static function getStatus()
    {
        try {
            $path = storage_path('app/license_status.json');
            if (!file_exists($path)) {
                return [
                    'status' => 'missing',
                    'message' => 'License not verified',
                    'raw' => null,
                ];
            }

            $json = @file_get_contents($path);
            if (!$json) {
                return [
                    'status' => 'error',
                    'message' => 'Unable to read license status cache',
                    'raw' => null,
                ];
            }

            $data = json_decode($json, true);
            if (!is_array($data) || empty($data['status'])) {
                return [
                    'status' => 'unverified',
                    'message' => 'License not verified',
                    'raw' => $data,
                ];
            }

            $status = strtolower($data['status']);
            $message = isset($data['message']) ? $data['message'] : '';

            return [
                'status' => $status,
                'message' => $message,
                'raw' => $data,
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'message' => 'Error reading license status: ' . $e->getMessage(),
                'raw' => null,
            ];
        }
    }
}
