<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\OrderNotifier;
use App\Services\SettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Store settings the owner edits from the admin panel.
 *
 * Notifications live here because where an alert goes is a store decision, not
 * a deployment one: whoever is on duty this week changes without a deploy, and
 * putting the address in .env meant the person who can actually do the work
 * cannot change it.
 */
class SettingController extends Controller
{
    public function __construct(
        private readonly SettingService $settings,
        private readonly OrderNotifier $notifier,
    ) {}

    public function show(): JsonResponse
    {
        return response()->json([
            'settings' => [
                'notifications' => [
                    'order_enabled' => $this->settings->orderNotificationsEnabled(),
                    // The raw configured string, not the parsed list, so the
                    // form shows what is actually saved rather than a
                    // re-serialised approximation of it.
                    'order_emails' => $this->settings->get(SettingService::ORDER_NOTIFICATION_EMAILS) ?? '',
                    'order_recipients' => $this->settings->orderNotificationEmails(),
                ],
                'mail' => [
                    // Surfaced so an owner can see *why* alerts are not
                    // arriving: `MAIL_MAILER=log` is the default in this
                    // project, which silently writes mail to a log file instead
                    // of sending it.
                    'mailer' => config('mail.default'),
                    'from' => config('mail.from.address'),
                ],
            ],
            // Why an enabled toggle would still not send anything.
            'notifications_configured' => $this->notifier->isConfigured(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        // Read with data_get rather than array_key_exists on the validated
        // array: validate() returns nested keys, so a dot-path check would
        // never match and every save would silently do nothing.
        $request->validate([
            'notifications' => ['sometimes', 'array'],
            'notifications.order_emails' => ['nullable', 'string', 'max:1000'],
            'notifications.order_enabled' => ['nullable', 'boolean'],
        ]);

        $values = [];

        if ($request->has('notifications.order_emails')) {
            $input = (string) $request->input('notifications.order_emails');
            [$valid, $rejected] = $this->splitAddresses($input);

            // Rejected rather than quietly dropped. Silently discarding an
            // address the owner believes they saved is how an alert goes
            // missing for a month: the toggle is on, the panel shows an address,
            // and nothing arrives because that address was thrown away here.
            if ($rejected !== []) {
                // Also in the standard `errors` bag, which is what the SPA
                // client reads for field errors. Returning the addresses
                // only under a bespoke key would mean the panel had no way
                // to show which ones were wrong.
                return response()->json([
                    'message' => 'These addresses are not valid and were not saved.',
                    'invalid_emails' => $rejected,
                    'errors' => ['notifications.order_emails' => $rejected],
                ], 422);
            }

            $values[SettingService::ORDER_NOTIFICATION_EMAILS] = implode(', ', $valid);
        }

        if ($request->has('notifications.order_enabled')) {
            $values[SettingService::ORDER_NOTIFICATION_ENABLED] =
                $request->boolean('notifications.order_enabled') ? '1' : '0';
        }

        $this->settings->setMany($values);

        return $this->show();
    }

    /**
     * Split what the owner typed into valid addresses and rejected ones.
     *
     * The same text may use commas, semicolons, spaces or newlines as
     * separators, and the same paste should always end up as the same
     * recipients.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private function splitAddresses(string $input): array
    {
        $parts = collect(preg_split('/[,;\s]+/', trim($input)) ?: [])
            ->map(fn (string $email) => strtolower(trim($email)))
            ->filter(fn (string $email) => $email !== '')
            ->unique();

        return [
            $parts->filter(fn (string $email) => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)->values()->all(),
            $parts->reject(fn (string $email) => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)->values()->all(),
        ];
    }
}
