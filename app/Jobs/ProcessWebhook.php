<?php

namespace App\Jobs;

use App\Models\WebhookConfiguration;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use App\Enums\WebhookType;

class ProcessWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  array<mixed>|string|null  $data
     */
    public function __construct(
        private WebhookConfiguration $webhookConfiguration,
        private string $eventName,
        private array|string|null $data = null
    ) {}

    public function handle(): void
    {
        $payload = is_array($this->data) ? $this->data : (json_decode($this->data, true) ?? []);
        $payload['event'] = $this->webhookConfiguration->transformClassName($this->eventName);

        if ($this->webhookConfiguration->type === WebhookType::Discord) {
            $payload = $this->convertToDiscord($payload);
        }

        try {
            $customHeaders = $this->webhookConfiguration->headers ?: [];
            $headers = [];
            foreach ($customHeaders as $key => $value) {
                $headers[$key] = $this->webhookConfiguration->replaceVars($payload, $value);
            }

            Http::withHeaders($headers)->post($this->webhookConfiguration->endpoint, $payload)->throw();
            $successful = now();
        } catch (Exception $exception) {
            report($exception->getMessage());
            $successful = null;
        }

        $this->logWebhookCall($payload, $successful);
    }

    private function logWebhookCall(array $payload, Carbon $success): void
    {
        $this->webhookConfiguration->webhooks()->create([
            'payload' => $payload,
            'successful_at' => $success,
            'event' => $this->eventName,
            'endpoint' => $this->webhookConfiguration->endpoint,
        ]);
    }

    /**
     * @param  mixed  $data
     * @return array
     */
    public function convertToDiscord(mixed $data): array
    {
        $payload = json_encode($this->webhookConfiguration->payload);
        $tmp = $this->webhookConfiguration->replaceVars($data, $payload);
        $data = json_decode($tmp, true);

        $embeds = data_get($data, 'embeds');
        if ($embeds) {
            // copied from previous, is the & needed?
            foreach ($embeds as &$embed) {
                if (data_get($embed, 'has_timestamp')) {
                    $embed['timestamp'] = Carbon::now();
                    unset($embed['has_timestamp']);
                }
            }
            $data['embeds'] = $embeds;
        }
        return $data;
    }
}
