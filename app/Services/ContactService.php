<?php

declare(strict_types=1);

namespace Rajdhani\Services;

use Rajdhani\Helpers\ApiError;
use Rajdhani\Helpers\DateHelper;
use Rajdhani\Helpers\Pagination;
use Rajdhani\Mail\ContactMail;
use Rajdhani\Repositories\ContactMessageRepository;
use Rajdhani\Services\Concerns\ValidatesInput;
use Rajdhani\Support\Mailer;
use Rajdhani\Support\NotificationRecipients;

/**
 * The contact form — the platform's simplest lead-capture path, with no
 * reference number and no assignee (doc §2, §8.8, §9.6, §9.10, §11; RTPP-31).
 *
 * **The contact-list notification (§14.4) is wired in from RTPP-33
 * onward**, sent after the row is created. `Mailer::send()` never throws
 * (see its class doc), so a mail failure here is logged and swallowed
 * rather than failing the visitor's submission.
 */
final class ContactService
{
    use ValidatesInput;

    public function __construct(
        private readonly ContactMessageRepository $messages = new ContactMessageRepository(),
        private readonly Mailer $mailer = new Mailer(),
        private readonly NotificationRecipients $recipients = new NotificationRecipients(),
    ) {
    }

    /**
     * `POST /public/contact` (doc §9.6). No reference number is returned —
     * unlike enquiries and dealer applications, the document does not ask
     * for one here, and the schema has no column to hold it.
     *
     * @param array<string,mixed> $input
     *
     * @return array{received:bool}
     */
    public function submit(string $ip, array $input): array
    {
        $fields = [
            'name'       => $this->requiredText($input, 'name', 255),
            'email'      => $this->requiredEmail($input),
            'phone'      => $this->optionalText($input, 'phone', 32),
            'subject'    => $this->optionalText($input, 'subject', 255),
            'message'    => $this->requiredText($input, 'message', 65535),
            'ip_address' => $ip === '' ? null : $ip,
        ];

        $this->messages->create($fields);

        $mail = new ContactMail(
            (string) $fields['name'],
            (string) $fields['email'],
            $fields['phone'],
            $fields['subject'],
            (string) $fields['message'],
        );
        $this->mailer->send($this->recipients->forSetting('contact_notify_emails'), $mail->subject(), $mail->html());

        return ['received' => true];
    }

    /**
     * @param array<string,mixed> $query
     *
     * @return array{data:list<array<string,mixed>>,meta:array<string,int>}
     */
    public function paginate(array $query): array
    {
        $pagination = Pagination::fromQuery($query);
        $status = $this->statusFilter($query);

        $rows = $this->messages->paginate($pagination->limit, $pagination->offset(), $status);
        $total = $this->messages->count($status);

        return [
            'data' => array_map($this->adminView(...), $rows),
            'meta' => $pagination->meta($total),
        ];
    }

    /**
     * `PATCH /admin/messages/{id}` (doc §9.10, §11): "Mark read/replied/
     * archived", plus internal notes — the same moderation-queue treatment
     * every other lead-capture form gets. `replied_at` is (re-)stamped every
     * time `status` is set to `REPLIED`, mirroring
     * `DealerApplicationRepository::updateWithReviewTimestamp()`'s split
     * between "when to stamp" (a service decision) and "what `now()` is" (a
     * repository concern).
     *
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed>
     */
    public function update(string $id, array $input): array
    {
        $this->requireMessage($id);
        $fields = [];
        $stampReplied = false;

        if (array_key_exists('status', $input)) {
            $status = $this->optionalEnum($input, 'status', ['UNREAD', 'READ', 'REPLIED', 'ARCHIVED'], 'UNREAD');
            $fields['status'] = $status;
            $stampReplied = $status === 'REPLIED';
        }

        if (array_key_exists('internal_notes', $input)) {
            $fields['internal_notes'] = $this->optionalText($input, 'internal_notes', 65535);
        }

        if ($fields === []) {
            throw ApiError::validation('Nothing to update', [
                ['field' => '', 'message' => 'Send at least one editable field'],
            ]);
        }

        if ($stampReplied) {
            $this->messages->updateWithRepliedTimestamp($id, $fields);
        } else {
            $this->messages->update($id, $fields);
        }

        return $this->adminView($this->requireMessage($id));
    }

    /**
     * @param array<string,mixed> $query
     */
    private function statusFilter(array $query): ?string
    {
        $status = $query['status'] ?? null;

        if ($status !== null && (!is_string($status) || !in_array($status, ['UNREAD', 'READ', 'REPLIED', 'ARCHIVED'], true))) {
            throw $this->invalid('status', 'Must be one of: UNREAD, READ, REPLIED, ARCHIVED');
        }

        return $status;
    }

    /** @return array<string,mixed> */
    private function requireMessage(string $id): array
    {
        $message = $this->messages->find($id);

        if ($message === null) {
            throw ApiError::notFound('No such message');
        }

        return $message;
    }

    /**
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    private function adminView(array $row): array
    {
        return [
            'id'             => (string) $row['id'],
            'name'           => (string) $row['name'],
            'email'          => (string) $row['email'],
            'phone'          => $row['phone'] === null ? null : (string) $row['phone'],
            'subject'        => $row['subject'] === null ? null : (string) $row['subject'],
            'message'        => (string) $row['message'],
            'status'         => (string) $row['status'],
            'replied_at'     => $row['replied_at'] === null ? null : DateHelper::iso((string) $row['replied_at']),
            'internal_notes' => $row['internal_notes'] === null ? null : (string) $row['internal_notes'],
            'ip_address'     => $row['ip_address'] === null ? null : (string) $row['ip_address'],
            'created_at'     => DateHelper::iso((string) $row['created_at']),
        ];
    }
}
