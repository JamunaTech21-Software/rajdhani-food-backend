<?php

declare(strict_types=1);

namespace Rajdhani\Controllers\Admin;

use Rajdhani\Helpers\ApiError;
use Rajdhani\Http\Request;
use Rajdhani\Services\ContactService;

/**
 * `/admin/messages` (doc §9.10, §11, RTPP-31). Parse, delegate, respond.
 * No `GET /admin/messages/{id}` — the document lists only the list and the
 * update, and the list row already carries the full message body.
 */
final class ContactMessageController
{
    public function __construct(
        private readonly ContactService $contact = new ContactService(),
    ) {
    }

    /** @return array<string,mixed> */
    public function index(Request $request): array
    {
        return $this->contact->paginate($request->query);
    }

    /** @return array<string,mixed> */
    public function update(Request $request): array
    {
        $id = $request->attribute('id');

        if (!is_string($id) || $id === '') {
            throw ApiError::notFound('No such message');
        }

        return $this->contact->update($id, $request->body);
    }
}
