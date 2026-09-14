<?php

declare(strict_types=1);

namespace Rajdhani\Services;

use Rajdhani\Helpers\ApiError;
use Rajdhani\Helpers\Pagination;
use Rajdhani\Mail\NewsletterWelcomeMail;
use Rajdhani\Repositories\NewsletterSubscriberRepository;
use Rajdhani\Repositories\SiteProfileRepository;
use Rajdhani\Services\Concerns\ValidatesInput;
use Rajdhani\Support\Mailer;

/**
 * Newsletter subscription and unsubscribe (doc §2, §8.8, §9.6, §9.10, §11;
 * RTPP-31).
 *
 * **The DoD's "re-subscribing an existing address does not create a
 * duplicate or error the visitor"** is why `subscribe()` branches on the
 * row's *current* state rather than always inserting: `email` is unique
 * (`uq_newsletter_email`), so a second `INSERT` for the same address would
 * be a constraint violation, not a duplicate row — but surfacing that as an
 * error to a visitor re-subscribing is exactly the wrong behaviour the DoD
 * calls out. An address already actively subscribed is a silent no-op; one
 * that had previously unsubscribed is reactivated in place, keeping its
 * existing `unsubscribe_token` rather than issuing a new one — the token
 * identifies the row, not a specific subscription period.
 *
 * **The welcome email (§14.4) is wired in from RTPP-33 onward**, sent only
 * on the two branches that actually change something (a brand-new row, or
 * reactivating a previously-unsubscribed one) — never on the silent no-op
 * branch, since re-sending a "welcome" to someone already subscribed would
 * be exactly the noise the DoD's "does not... error the visitor" line is
 * about avoiding on the *request* side, applied here to the *inbox* side
 * too. `newsletter_notify_emails` is unrelated to this — it is the admin
 * notification list §14.4 does not actually name for this event; the
 * welcome email's only recipient is the subscriber themselves.
 */
final class NewsletterService
{
    use ValidatesInput;

    public function __construct(
        private readonly NewsletterSubscriberRepository $subscribers = new NewsletterSubscriberRepository(),
        private readonly SiteProfileRepository $profiles = new SiteProfileRepository(),
        private readonly Mailer $mailer = new Mailer(),
    ) {
    }

    /**
     * `POST /public/newsletter/subscribe` (doc §9.6).
     *
     * @param array<string,mixed> $input
     *
     * @return array{email:string,subscribed:bool}
     */
    public function subscribe(array $input): array
    {
        $email = $this->requiredEmail($input);
        $source = $this->optionalText($input, 'source', 64);

        $existing = $this->subscribers->findByEmail($email);

        if ($existing === null) {
            $token = bin2hex(random_bytes(32));
            $this->subscribers->create($email, $token, $source);
            $this->sendWelcome($email, $token);
        } elseif (!(bool) $existing['is_subscribed']) {
            $this->subscribers->reactivate((string) $existing['id'], $source);
            $this->sendWelcome($email, (string) $existing['unsubscribe_token']);
        }
        // else: already actively subscribed — a silent no-op, per the DoD,
        // on both the database row and the inbox.

        return ['email' => $email, 'subscribed' => true];
    }

    /** `Mailer::send()` never throws, so no try/catch is needed at this call site either. */
    private function sendWelcome(string $email, string $unsubscribeToken): void
    {
        $profile = $this->profiles->find();
        $siteName = $profile !== null ? (string) $profile['name'] : 'our newsletter';
        $unsubscribeUrl = rtrim((string) config('app.url'), '/') . '/public/newsletter/unsubscribe/' . $unsubscribeToken;

        $mail = new NewsletterWelcomeMail($siteName, $unsubscribeUrl);
        $this->mailer->send([$email], $mail->subject(), $mail->html());
    }

    /**
     * `GET /public/newsletter/unsubscribe/{token}` (doc §9.6). No
     * authentication — the 64-character random token (`bin2hex(random_bytes(32))`)
     * is what makes the link "not guessable" (the DoD's own wording), not a
     * signed-in session. Idempotent: unsubscribing an already-unsubscribed
     * token still succeeds, the same as every other delete/state-change in
     * this codebase.
     *
     * @return array{email:string,subscribed:bool}
     */
    public function unsubscribe(string $token): array
    {
        $subscriber = $this->subscribers->findByToken($token);

        if ($subscriber === null) {
            throw ApiError::notFound('No such subscription');
        }

        if ((bool) $subscriber['is_subscribed']) {
            $this->subscribers->unsubscribe((string) $subscriber['id']);
        }

        return ['email' => (string) $subscriber['email'], 'subscribed' => false];
    }

    /**
     * @param array<string,mixed> $query
     *
     * @return array{data:list<array<string,mixed>>,meta:array<string,int>}
     */
    public function paginate(array $query): array
    {
        $pagination = Pagination::fromQuery($query);
        $isSubscribed = $this->isSubscribedFilter($query);

        $rows = $this->subscribers->paginate($pagination->limit, $pagination->offset(), $isSubscribed);
        $total = $this->subscribers->count($isSubscribed);

        return [
            'data' => array_map($this->adminView(...), $rows),
            'meta' => $pagination->meta($total),
        ];
    }

    /**
     * `GET /admin/subscribers/export` — CSV text inside the envelope, same
     * reasoning as every other export in this codebase.
     *
     * @param array<string,mixed> $query
     *
     * @return array{filename:string,csv:string}
     */
    public function exportCsv(array $query): array
    {
        $isSubscribed = $this->isSubscribedFilter($query);
        $rows = $this->subscribers->listAll($isSubscribed);

        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw ApiError::internal('Could not build the export');
        }

        fputcsv($handle, ['Email', 'Subscribed', 'Source', 'Subscribed At', 'Unsubscribed At'], escape: '\\');

        foreach ($rows as $row) {
            fputcsv($handle, [
                (string) $row['email'],
                ((int) $row['is_subscribed']) === 1 ? 'Yes' : 'No',
                (string) ($row['source'] ?? ''),
                (string) $row['subscribed_at'],
                (string) ($row['unsubscribed_at'] ?? ''),
            ], escape: '\\');
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return [
            'filename' => 'newsletter-subscribers-' . gmdate('Y-m-d') . '.csv',
            'csv'      => $csv === false ? '' : $csv,
        ];
    }

    /**
     * `DELETE /admin/subscribers/{id}` (doc §9.10: Super Admin only —
     * `RequireSuperAdmin` stacked in `routes/admin.php`, the same pattern
     * RTPP-27 used for review deletion). Idempotent, like every other delete
     * in this codebase: an id that does not exist has already reached the
     * end state this call wants.
     */
    public function delete(string $id): void
    {
        $this->subscribers->delete($id);
    }

    /**
     * Same truthy-string convention `ProductService::publicBoolParam()` uses
     * for query params, extended with an explicit falsy set and a `null`
     * "not provided" state — this filter has three states, not two.
     *
     * @param array<string,mixed> $query
     */
    private function isSubscribedFilter(array $query): ?bool
    {
        $value = $query['isSubscribed'] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        $normalised = is_scalar($value) ? strtolower((string) $value) : '';

        if (in_array($normalised, ['1', 'true', 'yes'], true)) {
            return true;
        }

        if (in_array($normalised, ['0', 'false', 'no'], true)) {
            return false;
        }

        throw $this->invalid('isSubscribed', 'Must be true or false');
    }

    /**
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    private function adminView(array $row): array
    {
        return [
            'id'               => (string) $row['id'],
            'email'            => (string) $row['email'],
            'is_subscribed'    => (bool) $row['is_subscribed'],
            'source'           => $row['source'] === null ? null : (string) $row['source'],
            'subscribed_at'    => (string) $row['subscribed_at'],
            'unsubscribed_at'  => $row['unsubscribed_at'] === null ? null : (string) $row['unsubscribed_at'],
        ];
    }
}
