<?php

namespace App\Jobs;

use App\Models\ActivityLog;
use App\Models\Setting;
use App\Services\Syslog\CefFormatter;
use App\Services\Syslog\SyslogTransport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ForwardToSyslog implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 5;

    public function __construct(
        public ActivityLog $activityLog,
    ) {}

    public function handle(): void
    {
        if (Setting::get('syslog', 'enabled', 'false') !== 'true') {
            return;
        }

        $host = Setting::get('syslog', 'host');
        $port = (int) Setting::get('syslog', 'port', '514');
        $protocol = Setting::get('syslog', 'transport', 'udp');
        $facility = (int) Setting::get('syslog', 'facility', '16');

        if (! $host || ! SyslogTransport::validateConfig($host, $port, $protocol)) {
            return;
        }

        $this->activityLog->loadMissing('user');

        $formatter = new CefFormatter();
        $cefMessage = $formatter->format($this->activityLog);
        $severity = $formatter->severity($this->activityLog->action);

        $transport = new SyslogTransport($host, $port, $protocol, $facility);
        $transport->send($cefMessage, $severity);
    }
}
