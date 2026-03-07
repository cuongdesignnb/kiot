<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class VerifyAttendanceAgentSignature
{
    public function handle(Request $request, Closure $next)
    {
        $key = config('services.attendance_agent.hmac_key', 'your-long-random-secret-here-change-me');
        $tolerance = (int) config('services.attendance_agent.timestamp_tolerance', 300);
        $deviceId = $request->header('X-Device-Id', '');
        $timestamp = $request->header('X-Timestamp', '');
        $signature = $request->header('X-Signature', '');

        // Debug log — xoá sau khi fix xong
        Log::channel('single')->info('HMAC Debug', [
            'key_first8'    => substr($key, 0, 8) . '...',
            'key_length'    => strlen($key),
            'device_id'     => $deviceId,
            'timestamp'     => $timestamp,
            'server_time'   => time(),
            'time_diff'     => abs(time() - (int) $timestamp),
            'signature'     => $signature,
            'has_body'      => !empty($request->getContent()),
            'body_length'   => strlen($request->getContent()),
            'body_first100' => substr($request->getContent(), 0, 100),
        ]);

        if (!$key || !$deviceId || !$timestamp || !$signature) {
            Log::channel('single')->warning('HMAC: Missing headers', [
                'has_key' => !empty($key),
                'has_device' => !empty($deviceId),
                'has_timestamp' => !empty($timestamp),
                'has_signature' => !empty($signature),
            ]);
            return response()->json(['error' => 'Missing auth headers'], 401);
        }

        // Chống replay attack — kiểm tra timestamp lệch
        if (abs(time() - (int) $timestamp) > $tolerance) {
            Log::channel('single')->warning('HMAC: Timestamp expired', [
                'diff' => abs(time() - (int) $timestamp),
                'tolerance' => $tolerance,
            ]);
            return response()->json(['error' => 'Timestamp expired'], 401);
        }

        // Lấy raw body
        $rawBody = $request->getContent();

        // Tính chữ ký mong đợi
        $payload = "{$timestamp}.{$deviceId}.{$rawBody}";
        $expected = hash_hmac('sha256', $payload, $key);

        Log::channel('single')->info('HMAC Verify', [
            'payload_first100' => substr($payload, 0, 100),
            'payload_length'   => strlen($payload),
            'expected_sig'     => $expected,
            'received_sig'     => $signature,
            'match'            => hash_equals($expected, $signature),
        ]);

        if (!hash_equals($expected, $signature)) {
            return response()->json([
                'error'          => 'Invalid signature',
                'debug_expected' => $expected,
                'debug_payload_len' => strlen($payload),
                'debug_key_hint' => substr($key, 0, 4) . '***',
            ], 401);
        }

        // Lưu device_id vào request để controller dùng
        $request->attributes->set('attendance_device_id', $deviceId);

        return $next($request);
    }
}
