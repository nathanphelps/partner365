<?php

namespace App\Notifications;

use App\Models\LabelSweepRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SweepAbortedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly LabelSweepRun $run) {}

    public function via(mixed $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Sensitivity label sweep aborted')
            ->line("Sweep run #{$this->run->id} was aborted due to systemic failures.")
            ->line($this->run->error_message ?? 'See history for details.')
            ->action('View run', url('/sensitivity-labels/sweep/history/'.$this->run->id));
    }

    public function toArray(mixed $notifiable): array
    {
        return [
            'run_id' => $this->run->id,
            'error_message' => $this->run->error_message,
        ];
    }
}
