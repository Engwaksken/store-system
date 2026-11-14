<?php

namespace App\Support;

use App\Models\PushSubscription;
use Illuminate\Support\Collection;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

/**
 * Sends Web Push messages to users' devices (VAPID). Subscriptions the push service reports as gone
 * (the user revoked permission or uninstalled the browser) are deleted. Never throws: push is best effort.
 */
class PushSender
{
    public static function configured(): bool
    {
        return filled(config('cms.push.public_key')) && filled(config('cms.push.private_key'));
    }

    /**
     * @param  Collection<int, PushSubscription>  $subscriptions
     * @param  array{title:string, body:string, url:string, tag?:string, kind?:string}  $payload
     * @return int number of devices the message was accepted for
     */
    public function send(Collection $subscriptions, array $payload): int
    {
        if (! self::configured() || $subscriptions->isEmpty()) {
            return 0;
        }
        try {
            $push = new WebPush(['VAPID' => [
                'subject' => config('cms.push.subject'),
                'publicKey' => config('cms.push.public_key'),
                'privateKey' => config('cms.push.private_key'),
            ]], ['TTL' => 86400, 'urgency' => 'normal'], 10);
            $push->setReuseVAPIDHeaders(true);

            $byEndpoint = $subscriptions->keyBy('endpoint');
            foreach ($subscriptions as $s) {
                $push->queueNotification(Subscription::create([
                    'endpoint' => $s->endpoint, 'publicKey' => $s->public_key, 'authToken' => $s->auth_token, 'contentEncoding' => $s->content_encoding,
                ]), json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }

            $ok = 0;
            foreach ($push->flush() as $report) {
                $sub = $byEndpoint->get($report->getEndpoint());
                if ($report->isSuccess()) {
                    $ok++;
                    $sub?->forceFill(['last_used_at' => now()])->save();
                } elseif ($report->isSubscriptionExpired()) {
                    $sub?->delete();
                } else {
                    report(new \RuntimeException('Web push failed: '.$report->getReason()));
                }
            }

            return $ok;
        } catch (Throwable $e) {
            report($e);

            return 0;
        }
    }
}
