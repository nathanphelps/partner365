<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ActivityAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateSyslogSettingsRequest;
use App\Models\Setting;
use App\Services\ActivityLogService;
use App\Services\Syslog\SyslogTransport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminSyslogController extends Controller
{
    public function __construct(
        private ActivityLogService $activityLog,
    ) {}

    public function edit(): Response
    {
        return Inertia::render('admin/Syslog', [
            'settings' => [
                'enabled' => Setting::get('syslog', 'enabled', 'false') === 'true',
                'host' => Setting::get('syslog', 'host', ''),
                'port' => (int) Setting::get('syslog', 'port', '514'),
                'transport' => Setting::get('syslog', 'transport', 'udp'),
                'facility' => (int) Setting::get('syslog', 'facility', '16'),
            ],
        ]);
    }

    public function update(UpdateSyslogSettingsRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        Setting::set('syslog', 'enabled', $validated['enabled'] ? 'true' : 'false');
        Setting::set('syslog', 'host', $validated['host'] ?? '');
        Setting::set('syslog', 'port', (string) $validated['port']);
        Setting::set('syslog', 'transport', $validated['transport']);
        Setting::set('syslog', 'facility', (string) $validated['facility']);

        $this->activityLog->log($request->user(), ActivityAction::SettingsUpdated, null, [
            'group' => 'syslog',
        ]);

        return redirect()->back()->with('success', 'SIEM settings updated.');
    }

    public function test(Request $request): JsonResponse
    {
        $host = Setting::get('syslog', 'host');
        $port = (int) Setting::get('syslog', 'port', '514');
        $protocol = Setting::get('syslog', 'transport', 'udp');
        $facility = (int) Setting::get('syslog', 'facility', '16');

        if (! $host || ! SyslogTransport::validateConfig($host, $port, $protocol)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid syslog configuration. Save settings first.',
            ]);
        }

        $transport = new SyslogTransport($host, $port, $protocol, $facility);
        $result = $transport->test();

        return response()->json($result);
    }
}
