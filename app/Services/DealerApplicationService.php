<?php

declare(strict_types=1);

namespace Rajdhani\Services;

use PDO;
use Rajdhani\Helpers\ApiError;
use Rajdhani\Helpers\DateHelper;
use Rajdhani\Helpers\Pagination;
use Rajdhani\Mail\DealerApplicationMail;
use Rajdhani\Repositories\DealerApplicationRepository;
use Rajdhani\Repositories\LocationRepository;
use Rajdhani\Repositories\ReferenceCounterRepository;
use Rajdhani\Repositories\SettingsRepository;
use Rajdhani\Services\Concerns\HandlesTransactions;
use Rajdhani\Services\Concerns\RetriesDeadlocks;
use Rajdhani\Services\Concerns\ValidatesInput;
use Rajdhani\Support\Database;
use Rajdhani\Support\Mailer;
use Rajdhani\Support\NotificationRecipients;

/**
 * Dealer / distributor applications — the platform's other lead-capture form
 * alongside product enquiries (doc §2, §8.8, §9.6, §9.10, §10.3; RTPP-30).
 *
 * **Gapless application ids**, `RDFP-DA-{year}-{seq}`, use exactly the
 * mechanism `EnquiryService` built for `RDFP-ENQ-…` in RTPP-29: one row per
 * `('DA', $year)` in `reference_counters`, locked with `SELECT … FOR UPDATE`
 * and advanced in the *same* transaction as the application's `INSERT`. The
 * `ENQ` and `DA` counters are independent rows — the DoD's "incrementing one
 * never affects the other" is a property of the `(type, year)` composite key
 * itself, proved directly by `DealerApplicationTest`. The deadlock-retry
 * wrapper `EnquiryService` needed under real concurrent load
 * (`RetriesDeadlocks`, extracted from that ticket) is reused here rather than
 * re-proven with a second fork-based load test: both callers drive the exact
 * same `nextSequence()` code path against the same table, so the concurrency
 * property `EnquiryConcurrencyTest` established already covers this caller
 * too — a second 50-process fork test would exercise the identical lock, not
 * a new one.
 *
 * **The sales notification email (§14.4) is wired in from RTPP-33
 * onward**, sent after the transaction commits — same reasoning as
 * `EnquiryService::notifySales()`: a slow SMTP server must never hold the
 * counter row's lock or roll back a real submission.
 *
 * **Expected response window.** The ticket's cited §18.5 does not exist in
 * the document (grepped; §18 has no numbered subsections), and no exact SLA
 * text appears anywhere else in it either. Rather than hard-coding a string
 * the client has not confirmed, it is an admin-configurable `settings` key
 * (`dealer_application_response_window`) — the same "not hard-coded, editable
 * from the dashboard" treatment every other business-facing text in this
 * table gets.
 */
final class DealerApplicationService
{
    use HandlesTransactions;
    use RetriesDeadlocks;
    use ValidatesInput;

    private const DEFAULT_RESPONSE_WINDOW = '2-3 business days';

    private readonly PDO $db;

    public function __construct(
        private readonly DealerApplicationRepository $applications = new DealerApplicationRepository(),
        private readonly LocationRepository $locations = new LocationRepository(),
        private readonly ReferenceCounterRepository $counters = new ReferenceCounterRepository(),
        private readonly SettingsRepository $settings = new SettingsRepository(),
        private readonly Mailer $mailer = new Mailer(),
        private readonly NotificationRecipients $recipients = new NotificationRecipients(),
        ?PDO $connection = null,
    ) {
        $this->db = $connection ?? Database::connection();
    }

    /**
     * `POST /public/dealer-applications` (doc §9.6, §10.3).
     *
     * @param array<string,mixed> $input
     *
     * @return array{applicationId:string,submittedAt:string,expectedResponseWindow:string}
     */
    public function submit(string $ip, array $input): array
    {
        $fields = $this->coreFields($input) + ['ip_address' => $ip === '' ? null : $ip];

        $result = $this->retryOnDeadlock(function () use ($fields): array {
            return $this->transaction(function () use ($fields): array {
                $year = (int) gmdate('Y');
                $sequence = $this->counters->nextSequence('DA', $year);
                $applicationId = sprintf('RDFP-DA-%d-%05d', $year, $sequence);

                $id = $this->applications->create($fields + ['application_id' => $applicationId]);
                $created = $this->applications->find($id);

                if ($created === null) {
                    throw ApiError::internal('Could not load the application just created');
                }

                return ['applicationId' => $applicationId, 'submittedAt' => DateHelper::iso((string) $created['created_at']), 'row' => $created];
            });
        });

        $this->notifySales($result['row']);

        return [
            'applicationId'  => $result['applicationId'],
            'submittedAt'    => $result['submittedAt'],
            'expectedResponseWindow' => (string) $this->settings->get(
                'dealer_application_response_window',
                self::DEFAULT_RESPONSE_WINDOW,
            ),
        ];
    }

    /**
     * Sent *after* the transaction has committed — see
     * `EnquiryService::notifySales()`'s doc for why. `Mailer::send()` never
     * throws, so no second try/catch is needed here.
     *
     * @param array<string,mixed> $row the joined row `find()` returns, already carrying `district_name`
     */
    private function notifySales(array $row): void
    {
        $mail = new DealerApplicationMail(
            (string) $row['application_id'],
            (string) $row['full_name'],
            (string) $row['company_name'],
            (string) $row['phone'],
            (string) $row['email'],
            (string) $row['district_name'],
        );

        $this->mailer->send($this->recipients->forSetting('dealer_notify_emails'), $mail->subject(), $mail->html());
    }

    /**
     * @param array<string,mixed> $query
     *
     * @return array{data:list<array<string,mixed>>,meta:array<string,int>}
     */
    public function paginate(array $query): array
    {
        $pagination = Pagination::fromQuery($query);
        [$status, $districtId, $assignedToId] = $this->adminFilters($query);

        $rows = $this->applications->paginate($pagination->limit, $pagination->offset(), $status, $districtId, $assignedToId);
        $total = $this->applications->count($status, $districtId, $assignedToId);

        return [
            'data' => array_map($this->adminView(...), $rows),
            'meta' => $pagination->meta($total),
        ];
    }

    /** @return array<string,mixed> */
    public function find(string $id): array
    {
        return $this->adminView($this->requireApplication($id));
    }

    /**
     * `PATCH /admin/applications/{id}` (doc §9.10): status, assignee,
     * internal notes — the moderation-queue fields, never the applicant's
     * own submission content.
     *
     * `reviewed_at` is stamped whenever `status` moves away from
     * `SUBMITTED` — the first (and every later) real admin action on the
     * application, not a separate field the caller has to remember to set.
     *
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed>
     */
    public function update(string $id, array $input): array
    {
        $this->requireApplication($id);
        $fields = [];

        $stampReviewed = false;

        if (array_key_exists('status', $input)) {
            $status = $this->optionalEnum(
                $input,
                'status',
                ['SUBMITTED', 'UNDER_REVIEW', 'APPROVED', 'REJECTED', 'ON_HOLD'],
                'SUBMITTED',
            );
            $fields['status'] = $status;
            $stampReviewed = $status !== 'SUBMITTED';
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

        if ($stampReviewed) {
            $this->applications->updateWithReviewTimestamp($id, $fields);
        } else {
            $this->applications->update($id, $fields);
        }

        return $this->find($id);
    }

    /**
     * `GET /admin/applications/export` — CSV text inside the envelope, same
     * reasoning as `EnquiryService::exportCsv()`.
     *
     * @param array<string,mixed> $query
     *
     * @return array{filename:string,csv:string}
     */
    public function exportCsv(array $query): array
    {
        [$status, $districtId, $assignedToId] = $this->adminFilters($query);
        $rows = $this->applications->listAll($status, $districtId, $assignedToId);

        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw ApiError::internal('Could not build the export');
        }

        fputcsv($handle, [
            'Application ID', 'Created At', 'Status', 'Full Name', 'Company', 'Phone', 'Email',
            'District', 'Upazila', 'Address', 'Trade License', 'TIN Certificate',
            'Years of Experience', 'Message', 'Assigned To',
        ], escape: '\\');

        foreach ($rows as $row) {
            fputcsv($handle, [
                (string) $row['application_id'],
                (string) $row['created_at'],
                (string) $row['status'],
                (string) $row['full_name'],
                (string) $row['company_name'],
                (string) $row['phone'],
                (string) $row['email'],
                (string) $row['district_name'],
                (string) $row['upazila_name'],
                (string) ($row['address_line'] ?? ''),
                ((int) $row['has_trade_license']) === 1 ? 'Yes' : 'No',
                ((int) $row['has_tin_certificate']) === 1 ? 'Yes' : 'No',
                (string) ($row['years_of_experience'] ?? ''),
                (string) ($row['message'] ?? ''),
                (string) ($row['assignee_name'] ?? ''),
            ], escape: '\\');
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return [
            'filename' => 'dealer-applications-' . gmdate('Y-m-d') . '.csv',
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
        $districtId = $this->requiredUlid($input, 'district_id');

        if (!$this->locations->districtExists($districtId)) {
            throw $this->invalid('district_id', 'No such district');
        }

        $upazilaId = $this->requiredUlid($input, 'upazila_id');

        if (!$this->locations->upazilaBelongsToDistrict($upazilaId, $districtId)) {
            throw $this->invalid('upazila_id', 'This upazila does not belong to the selected district');
        }

        return [
            'full_name'           => $this->requiredText($input, 'full_name', 255),
            'company_name'        => $this->requiredText($input, 'company_name', 255),
            'phone'               => $this->requiredText($input, 'phone', 32),
            'email'               => $this->requiredEmail($input),
            'district_id'         => $districtId,
            'upazila_id'          => $upazilaId,
            'address_line'        => $this->optionalText($input, 'address_line', 255),
            'has_trade_license'   => $this->optionalBool($input, 'has_trade_license', false),
            'has_tin_certificate' => $this->optionalBool($input, 'has_tin_certificate', false),
            'years_of_experience' => $this->optionalNullableInt($input, 'years_of_experience'),
            'message'             => $this->optionalText($input, 'message', 65535),
        ];
    }

    /**
     * Unlike `ValidatesInput::optionalInt()`, absence stays `null` rather
     * than falling back to a default — "0 years of experience" and "not
     * provided" are different facts, and the column is nullable precisely
     * because the form does not require this field.
     *
     * @param array<string,mixed> $input
     */
    private function optionalNullableInt(array $input, string $field): ?int
    {
        $value = $input[$field] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if (!is_numeric($value) || (int) $value < 0) {
            throw $this->invalid($field, 'Expected a non-negative number');
        }

        return (int) $value;
    }

    /** @param array<string,mixed> $input */
    private function optionalAssignee(array $input): ?string
    {
        $id = $this->optionalUlid($input, 'assigned_to_id');

        if ($id !== null && !$this->applications->assigneeExists($id)) {
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

        if ($status !== null && (!is_string($status) || !in_array($status, ['SUBMITTED', 'UNDER_REVIEW', 'APPROVED', 'REJECTED', 'ON_HOLD'], true))) {
            throw $this->invalid('status', 'Must be one of: SUBMITTED, UNDER_REVIEW, APPROVED, REJECTED, ON_HOLD');
        }

        $districtId = $this->optionalUlid($query, 'districtId');
        $assignedToId = $this->optionalUlid($query, 'assignedToId');

        return [$status, $districtId, $assignedToId];
    }

    /** @return array<string,mixed> */
    private function requireApplication(string $id): array
    {
        $application = $this->applications->find($id);

        if ($application === null) {
            throw ApiError::notFound('No such application');
        }

        return $application;
    }

    /**
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    private function adminView(array $row): array
    {
        return [
            'id'                  => (string) $row['id'],
            'application_id'      => (string) $row['application_id'],
            'full_name'           => (string) $row['full_name'],
            'company_name'        => (string) $row['company_name'],
            'phone'               => (string) $row['phone'],
            'email'               => (string) $row['email'],
            'district'            => ['id' => (string) $row['district_id'], 'name' => (string) $row['district_name']],
            'upazila'             => ['id' => (string) $row['upazila_id'], 'name' => (string) $row['upazila_name']],
            'address_line'        => $row['address_line'] === null ? null : (string) $row['address_line'],
            'has_trade_license'   => (bool) $row['has_trade_license'],
            'has_tin_certificate' => (bool) $row['has_tin_certificate'],
            'years_of_experience' => $row['years_of_experience'] === null ? null : (int) $row['years_of_experience'],
            'message'             => $row['message'] === null ? null : (string) $row['message'],
            'status'              => (string) $row['status'],
            'assigned_to'         => $row['assigned_to_id'] === null ? null : ['id' => (string) $row['assigned_to_id'], 'name' => (string) $row['assignee_name']],
            'internal_notes'      => $row['internal_notes'] === null ? null : (string) $row['internal_notes'],
            'reviewed_at'         => $row['reviewed_at'] === null ? null : DateHelper::iso((string) $row['reviewed_at']),
            'ip_address'          => $row['ip_address'] === null ? null : (string) $row['ip_address'],
            'created_at'          => DateHelper::iso((string) $row['created_at']),
            'updated_at'          => DateHelper::iso((string) $row['updated_at']),
        ];
    }
}
