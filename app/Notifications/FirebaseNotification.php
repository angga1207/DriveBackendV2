<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Kutia\Larafirebase\Messages\FirebaseMessage;

class FirebaseNotification extends Notification
{
    use Queueable;

    public $userIds = [], $title, $message, $datas = [];

    public function __construct($userIds = [], $title, $message, $datas = [])
    {
        $this->userIds = $userIds;
        $this->title = $title;
        $this->message = $message;
        $this->datas = $datas;
    }

    public function via(object $notifiable): array
    {
        return ['database', 'firebase'];
    }

    public function toFirebase($notifiable)
    {
        $fcmTokens = User::whereIn('id', $this->userIds)->pluck('fcm_token')->toArray();
        $return = (new FirebaseMessage)
            ->withTitle($this->title)
            ->withBody($this->message)
            ->withAdditionalData([
                'title' => $this->title,
                'message' => $this->message,
                'datas' => $this->datas,
            ])
            ->withPriority('high')
            ->asNotification($fcmTokens);

        return $return;
    }

    public function toArray($notifiable)
    {
        return [
            'title' => $this->title,
            'message' => $this->message,
            'datas' => $this->datas,
        ];
    }
}
