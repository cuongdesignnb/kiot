<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware xác thực HMAC cho AttendanceBridge Agent
 * 
 * Headers required:
 * - X-Device-Id: string (agent device id, e.g. "ronaldjack-1")
 * - X-Timestamp: unix time seconds (UTC)
 * - X-Signature: hex lowercase SHA256 HMAC
 * 
 * Signature rule:
 * raw = "{timestamp}.{deviceId}.{body}"
 * X-Signature = hex_lowercase( HMAC_SHA256(key=HmacKey, data=raw) )
 * 
 * For GET requests, body is empty string.
 * Timestamp tolerance: ±300 seconds.
 */
class VerifyAttendanceAgentSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        // Kiểm tra HMAC secret đã được cấu hình chưa
        $secret = (string) config('services.attendance_agent.hmac_key', env('ATTENDANCE_AGENT_SECRET', ''));
        if ($secret === '') {
            return response()->json([
                'success' => false,
                'message' => 'Server chưa cấu hình ATTENDANCE_AGENT_SECRET',
            ], 500);
        }

        // Lấy headers theo format mới
        $deviceId = (string) $request->header('X-Device-Id', '');
        $timestamp = (string) $request->header('X-Timestamp', '');
        $signature = (string) $request->header('X-Signature', '');

        // Kiểm tra headers bắt buộc
        if ($deviceId === '' || $timestamp === '' || $signature === '') {
            return response()->json([
                'success' => false,
                'message' => 'Thiếu headers xác thực (X-Device-Id, X-Timestamp, X-Signature)',
                'debug' => [
                    'has_device_id' => $deviceId !== '',
                    'has_timestamp' => $timestamp !== '',
                    'has_signature' => $signature !== '',
                ],
            ], 401);
        }

        // Validate timestamp format (phải là số)
        if (!ctype_digit($timestamp)) {
            return response()->json([
                'success' => false,
                'message' => 'Timestamp không hợp lệ (phải là unix timestamp)',
            ], 401);
        }

        // Kiểm tra timestamp tolerance (±300 seconds = 5 phút)
        $ts = (int) $timestamp;
        $serverTime = time();
        $timeDiff = $serverTime - $ts;
        $tolerance = (int) config('services.attendance_agent.timestamp_tolerance', 300);

        if (abs($timeDiff) > $tolerance) {
            return response()->json([
                'success' => false,
                'message' => 'Chữ ký hết hạn (timestamp lệch quá 5 phút)',
                'debug' => [
                    'client_timestamp' => $ts,
                    'server_timestamp' => $serverTime,
                    'difference_seconds' => $timeDiff,
                    'server_time_utc' => gmdate('Y-m-d H:i:s', $serverTime),
                ],
            ], 401);
        }

        // ===== QUAN TRỌNG: Lấy raw body bytes =====
        // Thử php://input trước, nếu empty thì dùng $request->getContent()
        $phpInput = file_get_contents('php://input');
        $requestContent = $request->getContent();

        if ($phpInput !== false && $phpInput !== '') {
            $rawBody = $phpInput;
            $bodySource = 'php://input';
        } else {
            $rawBody = (string) $requestContent;
            $bodySource = 'request->getContent()';
        }

        // ===== Tạo signature string: "{timestamp}.{deviceId}.{body}" =====
        $signatureData = $timestamp . '.' . $deviceId . '.' . $rawBody;

        // ===== HMAC SHA256 với output hex lowercase =====
        // Key dùng nguyên chuỗi UTF-8 (KHÔNG hex2bin)
        $expected = hash_hmac('sha256', $signatureData, $secret);

        // So sánh signature (constant time comparison để tránh timing attack)
        $receivedSignature = strtolower($signature);

        if (!hash_equals($expected, $receivedSignature)) {
            Log::warning('AttendanceAgent: Signature mismatch', [
                'device_id' => $deviceId,
                'timestamp' => $timestamp,
                'body_source' => $bodySource,
                'body_length' => strlen($rawBody),
                'body_first_100' => substr($rawBody, 0, 100),
                'body_md5' => md5($rawBody),
                'signature_data_first_200' => substr($signatureData, 0, 200),
                'expected_signature' => $expected,
                'received_signature' => $receivedSignature,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Chữ ký không đúng',
                'debug' => [
                    'hint' => 'body_md5 khớp → HMAC key khác nhau giữa server và client',
                    'body_source' => $bodySource,
                    'body_length' => strlen($rawBody),
                    'body_md5' => md5($rawBody),
                    'received_device_id' => $deviceId,
                    'received_timestamp' => $timestamp,
                    'expected_signature' => $expected,
                    'received_signature' => $receivedSignature,
                    'server_hmac_key_length' => strlen($secret),
                    'server_hmac_key_md5' => md5($secret),
                    'server_hmac_key_prefix' => substr($secret, 0, 4) . '...',
                ],
            ], 401);
        }

        // Lưu device_id vào request để controller dùng
        $request->attributes->set('attendance_device_id', $deviceId);

        return $next($request);
    }
}
