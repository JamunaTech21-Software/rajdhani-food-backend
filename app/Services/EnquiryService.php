<?php

declare(strict_types=1);

namespace Rajdhani\Services;

use PDO;
use Rajdhani\Helpers\ApiError;
use Rajdhani\Helpers\DateHelper;
use Rajdhani\Helpers\Pagination;
use Rajdhani\Mail\EnquiryMail;
use Rajdhani\Repositories\EnquiryRepository;
use Rajdhani\Repositories\ProductRepository;
use Rajdhani\Repositories\ReferenceCounterRepository;
use Rajdhani\Services\Concerns\HandlesTransactions;
use Rajdhani\Services\Concerns\RetriesDeadlocks;
use Rajdhani\Services\Concerns\ValidatesInput;
use Rajdhani\Support\Database;
use Rajdhani\Support\Mailer;
use Rajdhani\Support\NotificationRecipients;

/**
 * Product enquiries — the platform's primary conversion path, since there is
 * no cart or checkout (doc §2, §8.2, §8.8, §9.6, §9.10, §11; RTPP-29).
 *
 * **Gapless reference numbers.** `RDFP-ENQ-{year}-{seq}` is assigned by
 * locking `reference_counters`' one row for `('ENQ', $year)`
 * (`ReferenceCounterRepository::nextSequence()`) and inserting the enquiry
 * in the *same* transaction. The ticket's own design note explains why this
 * beats a database sequence or `AUTO_INCREMENT`: either of those burns a
 * value the moment a transaction rolls back, because the sequence advances
 * outside the transaction that consumes it. Here the counter's `UPDATE` is
 * inside the same transaction as the enquiry's `INSERT` — if the insert
 * fails, the whole transaction (counter advance included) rolls back
 * together, and the next successful submission gets the number the failed
 * one would have taken. `ReferenceCounterRepository`'s `SELECT … FOR UPDATE`
 * is what makes this collision-free under real concurrency, not just
 * correct in the rollback case — proven by a genuine multi-process test
 * (`tests/Feature/EnquiryConcurrencyTest.php`), not reasoning about it, per
 * the ticket's own DoD wording. That same load test is what actually found
 * `RetriesDeadlocks::retryOnDeadlock()`'s reason to exist: 50 processes
 * racing to lock the *same* counter row is a real InnoDB deadlock scenario,
 * not a hypothetical one — see that trait's doc. `DealerApplicationService`
 * (RTPP-30) reuses the same trait for its own counter row, `('DA', year)`.
 *
 * **The sales notification email (§14.4) is wired in from RTPP-33
 * onward.** `submit()` sends after the transaction commits, never inside
 * it — a slow or dead SMTP server must never hold the counter row's lock
 * or roll back a real submission. `Mailer::send()` never throws (see its
 * own class doc), so a mail failure here is logged and swallowed, exactly
 * this ticket's original "stops cleanly at the point a real mailer would
 * be called" design, now with a real mailer at that point instead of
 * nothing.
 *
 * **CSV export is returned inside the standard JSON envelope**
 * (`{"data": {"filename": ..., "csv": "..."}}`), not as a
 * `Content-Type: text/csv` file download. `Kernel`'s own class doc states a
 * hard invariant — "nothing leaves this class except a section 9.1
 * envelope" — for a real security reason (an unhandled exception must never
 * leak a body outside that envelope), and extending the response pipeline
 * to carry a second content type is a framework change this one ticket has
 * no mandate to make unilaterally. The dashboard can turn the returned CSV
 * string into a client-side download (a `Blob` and an anchor click) exactly
 * as easily as it would consume a streamed file.
 */
final class EnquiryService
{
    use HandlesTransactions;
    use RetriesDeadlocks;
    use ValidatesInput;

    private readonly PDO $db;

    public function __construct(
        private readonly EnquiryRepository $enquiries = new EnquiryRepository(),
        private readonly ProductRepository $products = new ProductRepository(),
        private readonly ReferenceCounterRepository $counters = new ReferenceCounterRepository(),
        private readonly Mailer $mailer = new Mailer(),
        private readonly NotificationRecipients $recipients = new NotificationRecipients(),
        ?PDO $connection = null,
    ) {
        $this->db = $connection ?? Database::connection();
    }

    /**
     * `POST /public/enquiries` (doc §9.6). `customerId` is whatever
     * `OptionalCustomer` found, or null for an anonymous visitor — either is
     * a valid submission.
     *
     * @param array<string,mixed> $input
     *
     * @return array{referenceNo:string}
     */
    public function submit(?string $customerId, string $ip, array $input): array
    {
        $fields = $this->coreFields($input) + [
            'customer_id' => $customerId,
            'ip_address'  => $ip === '' ? null : $ip,
        ];

        $referenceNo = $this->retryOnDeadlock(function () use ($fields): string {
            return $this->transaction(function () use ($fields): string {
                $year = (int) gmdate('Y');
                $sequence = $this->counters->nextSequence('ENQ', $year);
                $referenceNo = sprintf('RDFP-ENQ-%d-%05d', $year, $sequence);

                $this->enquiries->create($fields + ['reference_no' => $referenceNo]);

                return $referenceNo;
            });
        });

        $this->notifySales($referenceNo, $fields);

        return ['referenceNo' => $referenceNo];
    }

    /**
     * Sent *after* the transaction above has committed — never inside it,
     * so a slow SMTP server cannot hold the counter row's lock, and a mail
     * failure can never roll back a real submission. `Mailer::send()`
     * itself never throws (see its class doc), so this is not wrapped in
     * a second try/catch here.
     *
     * @param array<string,scalar|null> $fields
     */
    private function notifySales(string $referenceNo, array $fields): void
    {
        $productId = $fields['product_id'];
        $product = is_string($productId) ? $this->products->find($productId) : null;

        $mail = new EnquiryMail(
            $referenceNo,
            (string) $fields['name'],
            (string) $fields['email'],
            (string) $fields['phone'],
            $product !== null ? (string) $product['name'] : null,
            (string) $fields['message'],
        );

        $this->mailer->send($this->recipients->forSetting('enquiry_notify_emails'), $mail->subject(), $mail->html());
    }

    /**
     * @param array<string,mixed> $query
     *
     * @return array{data:list<array<string,mixed>>,meta:array<string,int>}
     */
    public function paginate(array $query): array
    {
        $pagination = Pagination::fromQuery($query);
        [$status, $productId, $assignedToId] = $this->adminFilters($query);

        $rows = $this->enquiries->paginate($pagination->limit, $pagination->offset(), $status, $productId, $assignedToId);
        $total = $this->enquiries->count($status, $productId, $assignedToId);

        return [
            'data' => array_map($this->adminView(...), $rows),
            'meta' => $pagination->meta($total),
        ];
    }

    /** @return array<string,mixed> */
    public function find(string $id): array
    {
        return $this->adminView($this->requireEnquiry($id));
    }

    /**
     * `PATCH /admin/enquiries/{id}` (doc §9.10): status, assignee, internal
     * notes — the moderation-queue fields, never the visitor's own
     * submission content.
     *
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed>
     */
    public function update(string $id, array $input): array
    {
        $this->requireEnquiry($id);
        $fields = [];

        if (array_key_exists('status', $input)) {
            $fields['status'] = $this->optionalEnum($input, 'status', ['NEW', 'IN_PROGRESS', 'CONTACTED', 'CLOSED', 'SPAM'], 'NEW');
        }

        if (array_key_exists('assigned_to_id', $input)) {
            $fields['assigned_to_id'] = $this->optionalAssignee($input);
        }

        if (array_key_exists('internal_notes', $input)) {
            $fields['internal_notes'] = $this->optionalText($input, 'internal_notes', 65535);
        }

        if ($fields === []) {
            throw ApiError::validation('Nothing to update', [
                ['field' => '', 'message' => 'Send at least one editable field'],
            ]);
        }

        $this->enquiries->update($id, $fields);

        return $this->find($id);
    }

    /**
     * `GET /admin/enquiries/export` — see the class doc on why this returns
     * CSV text inside the envelope rather than a file download.
     *
     * @param array<string,mixed> $query
     *
     * @return array{filename:string,csv:string}
     */
    public function exportCsv(array $query): array
    {
        [$status, $productId, $assignedToId] = $this->adminFilters($query);
        $rows = $this->enquiries->listAll($status, $productId, $assignedToId);

        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw ApiError::internal('Could not build the export');
        }

        fputcsv($handle, [
            'Reference', 'Created At', 'Status', 'Name', 'Company', 'Phone', 'Email', 'City',
            'Product', 'Pack Size', 'Quantity', 'Message', 'Assigned To',
        ], escape: '\\');

        foreach ($rows as $row) {
            fputcsv($handle, [
                (string) $row['reference_no'],
                (string) $row['created_at'],
                (string) $row['status'],
                (string) $row['name'],
                (string) ($row['company_name'] ?? ''),
                (string) $row['phone'],
                (string) $row['email'],
                (string) $row['city'],
                (string) ($row['product_name'] ?? ''),
                (string) ($row['pack_size_label'] ?? ''),
                (string) ($row['quantity'] ?? ''),
                (string) $row['message'],
                (string) ($row['assignee_name'] ?? ''),
            ], escape: '\\');
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return [
            'filename' => 'enquiries-' . gmdate('Y-m-d') . '.csv',
            'csv'      => $csv === false ? '' : $csv,
        ];
    }

    /**
     * @param array<string,mixed> $input
     *
     * @return array<string,scalar|null>
     */
    private function coreFields(array $input): array
    {
        return [
            'product_id'      => $this->optionalProductRef($input),
            'name'            => $this->requiredText($input, 'name', 255),
            'company_name'    => $this->optionalText($input, 'company_name', 255),
            'phone'           => $this->requiredText($input, 'phone', 32),
            'email'           => $this->requiredEmail($input),
            'city'            => $this->requiredText($input, 'city', 128),
            'pack_size_label' => $this->optionalText($input, 'pack_size_label', 64),
            'quantity'        => $this->optionalText($input, 'quantity', 64),
            'message'         => $this->requiredText($input, 'message', 65535),
            'source_page'     => $this->optionalText($input, 'source_page', 255),
        ];
    }

    /** @param array<string,mixed> $input */
    private function optionalProductRef(array $input): ?string
    {
        $id = $this->optionalUlid($input, 'product_id');

        if ($id !== null && $this->products->find($id) === null) {
            throw $this->invalid('product_id', 'No such product');
        }

        return $id;
    }

    /** @param array<string,mixed> $input */
    private function optionalAssignee(array $input): ?string
    {
        $id = $this->optionalUlid($input, 'assigned_to_id');

        if ($id !== null && !$this->enquiries->assigneeExists($id)) {
            throw $this->invalid('assigned_to_id', 'No such admin user');
        }

        return $id;
    }

    /**
     * @param array<string,mixed> $query
     *
     * @return array{0:?string,1:?string,2:?string}
     */
    private function adminFilters(array $query): array
    {
        $status = $query['status'] ?? null;

        if ($status !== null && (!is_string($status) || !in_array($status, ['NEW', 'IN_PROGRESS', 'CONTACTED', 'CLOSED', 'SPAM'], true))) {
            throw $this->invalid('status', 'Must be one of: NEW, IN_PROGRESS, CONTACTED, CLOSED, SPAM');
        }

        $productId = $this->optionalUlid($query, 'productId');
        $assignedToId = $this->optionalUlid($query, 'assignedToId');

        return [$status, $productId, $assignedToId];
    }

    /** @return array<string,mixed> */
    private function requireEnquiry(string $id): array
    {
        $enquiry = $this->enquiries->find($id);

        if ($enquiry === null) {
            throw ApiError::notFound('No such enquiry');
        }

        return $enquiry;
    }

    /**
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    private function adminView(array $row): array
    {
        return [
            'id'              => (string) $row['id'],
            'reference_no'    => (string) $row['reference_no'],
            'product'         => $row['product_id'] === null ? null : [
                'id' => (string) $row['product_id'], 'name' => (string) $row['product_name'], 'slug' => (string) $row['product_slug'],
            ],
            'customer_id'     => $row['customer_id'] === null ? null : (string) $row['customer_id'],
            'name'            => (string) $row['name'],
            'company_name'    => $row['company_name'] === null ? null : (string) $row['company_name'],
            'phone'           => (string) $row['phone'],
            'email'           => (string) $row['email'],
            'city'            => (string) $row['city'],
            'pack_size_label' => $row['pack_size_label'] === null ? null : (string) $row['pack_size_label'],
            'quantity'        => $row['quantity'] === null ? null : (string) $row['quantity'],
            'message'         => (string) $row['message'],
            'status'          => (string) $row['status'],
            'assigned_to'     => $row['assigned_to_id'] === null ? null : ['id' => (string) $row['assigned_to_id'], 'name' => (string) $row['assignee_name']],
            'internal_notes'  => $row['internal_notes'] === null ? null : (string) $row['internal_notes'],
            'source_page'     => $row['source_page'] === null ? null : (string) $row['source_page'],
            'ip_address'      => $row['ip_address'] === null ? null : (string) $row['ip_address'],
            'created_at'      => DateHelper::iso((string) $row['created_at']),
            'updated_at'      => DateHelper::iso((string) $row['updated_at']),
        ];
    }
}
