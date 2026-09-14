<?php

declare(strict_types=1);

namespace Rajdhani\Tests\Feature;

use Rajdhani\Helpers\ApiError;
use Rajdhani\Repositories\ContactMessageRepository;
use Rajdhani\Services\ContactService;

/**
 * The contact form and its admin inbox (doc §2, §8.8, §9.6, §9.10, §11;
 * RTPP-31), against a real database. No reference number, no assignee —
 * the simplest of the three lead-capture forms.
 */
final class ContactMessageTest extends DatabaseTestCase
{
    private ContactService $contact;
    private ContactMessageRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new ContactMessageRepository($this->db);
        $this->contact = new ContactService($this->repository);
    }

    public function testSubmittingCreatesAMessageAndAcknowledgesReceipt(): void
    {
        $result = $this->contact->submit('203.0.113.5', $this->validInput());

        self::assertTrue($result['received']);
    }

    public function testANewMessageDefaultsToUnread(): void
    {
        $this->contact->submit('203.0.113.5', $this->validInput());
        $page = $this->contact->paginate([]);

        self::assertSame('UNREAD', $page['data'][0]['status']);
        self::assertNull($page['data'][0]['replied_at']);
    }

    public function testEmailMustLookLikeAnEmail(): void
    {
        // Override, not union: array `+` keeps the *left* side's value for a
        // duplicate key, so the replacement email has to come first.
        $error = $this->captureApiError(
            fn () => $this->contact->submit('203.0.113.5', ['email' => 'not-an-email'] + $this->validInput())
        );

        self::assertSame('email', $error->details()[0]['field']);
    }

    public function testPhoneAndSubjectAreOptional(): void
    {
        $input = $this->validInput();
        unset($input['phone'], $input['subject']);

        $this->contact->submit('203.0.113.5', $input);
        $page = $this->contact->paginate([]);

        self::assertNull($page['data'][0]['phone']);
        self::assertNull($page['data'][0]['subject']);
    }

    public function testAdminFiltersByStatus(): void
    {
        $this->contact->submit('203.0.113.5', $this->validInput());
        $this->contact->submit('203.0.113.5', $this->validInput());
        $id = $this->idForFirstMessage();
        $this->contact->update($id, ['status' => 'ARCHIVED']);

        $archived = $this->contact->paginate(['status' => 'ARCHIVED']);
        $unread = $this->contact->paginate(['status' => 'UNREAD']);

        self::assertSame(1, $archived['meta']['total']);
        self::assertSame(1, $unread['meta']['total']);
    }

    public function testMarkingRepliedStampsRepliedAt(): void
    {
        $this->contact->submit('203.0.113.5', $this->validInput());
        $id = $this->idForFirstMessage();

        $before = $this->repository->find($id);
        self::assertNull($before['replied_at']);

        $updated = $this->contact->update($id, ['status' => 'REPLIED']);

        self::assertSame('REPLIED', $updated['status']);
        self::assertNotNull($updated['replied_at']);
    }

    public function testMarkingReadDoesNotStampRepliedAt(): void
    {
        $this->contact->submit('203.0.113.5', $this->validInput());
        $id = $this->idForFirstMessage();

        $updated = $this->contact->update($id, ['status' => 'READ']);

        self::assertSame('READ', $updated['status']);
        self::assertNull($updated['replied_at']);
    }

    public function testInternalNotesCanBeSetIndependently(): void
    {
        $this->contact->submit('203.0.113.5', $this->validInput());
        $id = $this->idForFirstMessage();

        $updated = $this->contact->update($id, ['internal_notes' => 'Called back, left a voicemail.']);

        self::assertSame('Called back, left a voicemail.', $updated['internal_notes']);
        self::assertSame('UNREAD', $updated['status']);
    }

    public function testUpdatingAMissingMessageIs404(): void
    {
        $error = $this->captureApiError(
            fn () => $this->contact->update('01ARZ3NDEKTSV4RRFFQ69G5FAV', ['status' => 'READ'])
        );

        self::assertSame(404, $error->status());
    }

    public function testUpdatingWithNothingIsRejected(): void
    {
        $this->contact->submit('203.0.113.5', $this->validInput());
        $id = $this->idForFirstMessage();

        $error = $this->captureApiError(fn () => $this->contact->update($id, []));

        self::assertSame(422, $error->status());
    }

    // ─── helpers ────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function validInput(): array
    {
        return [
            'name' => 'Test Visitor', 'email' => 'visitor@example.test', 'phone' => '+8801700000000',
            'subject' => 'Question about bulk orders', 'message' => 'Do you ship outside Dhaka?',
        ];
    }

    private function idForFirstMessage(): string
    {
        $id = $this->db->query('SELECT id FROM contact_messages ORDER BY created_at ASC LIMIT 1')->fetchColumn();

        return (string) $id;
    }

    private function captureApiError(callable $action): ApiError
    {
        try {
            $action();
        } catch (ApiError $e) {
            return $e;
        }

        self::fail('Expected an ApiError, none was thrown.');
    }
}
