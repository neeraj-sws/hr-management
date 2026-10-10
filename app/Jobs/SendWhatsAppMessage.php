<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends one WhatsApp message to one number through the configured provider
 * (Meta WhatsApp Cloud API, or a generic webhook for Twilio / Interakt / WATI
 * / Gupshup etc.). Never throws into the caller — a failure is logged only, so
 * attendance and leave are never blocked by WhatsApp being down.
 */
class SendWhatsAppMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $to, public string $text) {}

    public function handle(): void
    {
        $c = config('services.whatsapp');
        if (empty($c['enabled']) || $this->to === '') {
            return;
        }

        try {
            if (($c['provider'] ?? 'meta') === 'webhook') {
                $url = $c['webhook_url'] ?? null;
                if (! $url) {
                    return;
                }
                Http::timeout(8)->acceptJson()
                    ->when($c['webhook_token'] ?? null, fn ($h, $t) => $h->withToken($t))
                    ->post($url, ['to' => $this->to, 'text' => $this->text])->throw();

                return;
            }

            // Meta WhatsApp Cloud API
            $token = $c['token'] ?? null;
            $phoneId = $c['phone_id'] ?? null;
            if (! $token || ! $phoneId) {
                return;
            }
            $endpoint = "https://graph.facebook.com/v21.0/{$phoneId}/messages";
            if (! empty($c['template'])) {
                // A pre-approved template with one body parameter (the message text).
                // Template parameters may not contain newlines/tabs or 4+ spaces, so flatten to one line.
                $param = trim(preg_replace('/\s{2,}/', ' ', str_replace(["\r", "\n", "\t", '*'], [' ', ' - ', ' ', ''], $this->text)));
                $payload = ['messaging_product' => 'whatsapp', 'to' => $this->to, 'type' => 'template',
                    'template' => ['name' => $c['template'], 'language' => ['code' => $c['template_lang'] ?? 'en'],
                        'components' => [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => $param]]]]]];
            } else {
                $payload = ['messaging_product' => 'whatsapp', 'to' => $this->to, 'type' => 'text', 'text' => ['body' => $this->text]];
            }
            Http::withToken($token)->timeout(8)->acceptJson()->post($endpoint, $payload)->throw();
        } catch (\Throwable $e) {
            Log::warning('WhatsApp send failed', ['to' => $this->to, 'error' => $e->getMessage()]);
        }
    }
}
