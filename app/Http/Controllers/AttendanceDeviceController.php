<?php

namespace App\Http\Controllers;

use App\Models\AttendanceDevice;
use Illuminate\Http\Request;

class AttendanceDeviceController extends Controller
{
    public function index(Request $request)
    {
        $query = AttendanceDevice::query()->with(['branch:id,name']);

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->integer('branch_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        return response()->json(['success' => true, 'data' => $query->orderByDesc('id')->get()]);
    }

    public function show(AttendanceDevice $device)
    {
        $device->load(['branch:id,name']);
        return response()->json(['success' => true, 'data' => $device]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'branch_id' => ['nullable', 'integer'],
            'name' => ['required', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'serial_number' => ['nullable', 'string', 'max:255'],
            'ip_address' => ['required', 'ip'],
            'tcp_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'comm_key' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'status' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string'],
        ]);

        $device = AttendanceDevice::create([
            'branch_id' => $data['branch_id'] ?? null,
            'name' => $data['name'],
            'model' => $data['model'] ?? null,
            'serial_number' => $data['serial_number'] ?? null,
            'ip_address' => $data['ip_address'],
            'tcp_port' => $data['tcp_port'] ?? 4370,
            'comm_key' => $data['comm_key'] ?? 0,
            'status' => $data['status'] ?? 'active',
            'notes' => $data['notes'] ?? null,
        ]);

        $device->load(['branch:id,name']);
        return response()->json(['success' => true, 'message' => 'Tạo máy chấm công thành công', 'data' => $device]);
    }

    public function update(Request $request, AttendanceDevice $device)
    {
        $data = $request->validate([
            'branch_id' => ['nullable', 'integer'],
            'name' => ['required', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'serial_number' => ['nullable', 'string', 'max:255'],
            'ip_address' => ['required', 'ip'],
            'tcp_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'comm_key' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'status' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string'],
        ]);

        $device->update([
            'branch_id' => $data['branch_id'] ?? null,
            'name' => $data['name'],
            'model' => $data['model'] ?? null,
            'serial_number' => $data['serial_number'] ?? null,
            'ip_address' => $data['ip_address'],
            'tcp_port' => $data['tcp_port'] ?? 4370,
            'comm_key' => $data['comm_key'] ?? 0,
            'status' => $data['status'] ?? $device->status,
            'notes' => $data['notes'] ?? null,
        ]);

        $device->load(['branch:id,name']);
        return response()->json(['success' => true, 'message' => 'Cập nhật máy chấm công thành công', 'data' => $device]);
    }

    public function destroy(AttendanceDevice $device)
    {
        $device->delete();
        return response()->json(['success' => true, 'message' => 'Xóa máy chấm công thành công']);
    }

    public function testConnection(AttendanceDevice $device)
    {
        $timeoutSeconds = 3;

        $tcp = $this->probeTcp($device->ip_address, (int) $device->tcp_port, $timeoutSeconds);
        if ($tcp['ok']) {
            return response()->json([
                'success' => true,
                'message' => 'Kết nối TCP thành công',
                'data' => ['protocol' => 'tcp', 'ip' => $device->ip_address, 'port' => (int) $device->tcp_port],
            ]);
        }

        $udp = $this->probeUdp($device->ip_address, (int) $device->tcp_port, $timeoutSeconds);
        if ($udp['ok']) {
            return response()->json([
                'success' => true,
                'message' => 'TCP thất bại nhưng UDP có thể truy cập (thiết bị có thể dùng UDP).',
                'data' => ['protocol' => 'udp', 'ip' => $device->ip_address, 'port' => (int) $device->tcp_port],
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Không kết nối được (TCP/UDP). Vui lòng kiểm tra IP/Port/Firewall/mạng LAN.',
            'error' => ['tcp' => $tcp['error'] ?? null, 'udp' => $udp['error'] ?? null],
        ], 422);
    }

    private function probeTcp(string $ip, int $port, int $timeoutSeconds): array
    {
        $errno = 0;
        $errstr = '';
        $socket = @fsockopen($ip, $port, $errno, $errstr, $timeoutSeconds);

        if (!$socket) {
            return ['ok' => false, 'error' => ['code' => $errno, 'detail' => $errstr]];
        }

        fclose($socket);
        return ['ok' => true];
    }

    private function probeUdp(string $ip, int $port, int $timeoutSeconds): array
    {
        $errno = 0;
        $errstr = '';
        $sock = @stream_socket_client(
            sprintf('udp://%s:%d', $ip, $port),
            $errno,
            $errstr,
            $timeoutSeconds,
            STREAM_CLIENT_CONNECT
        );

        if (!$sock) {
            return ['ok' => false, 'error' => ['code' => $errno, 'detail' => $errstr]];
        }

        stream_set_timeout($sock, $timeoutSeconds);
        @fwrite($sock, "\0");
        fclose($sock);
        return ['ok' => true];
    }
}
