<?php

declare(strict_types=1);

namespace Rajdhani\Controllers\Admin;

use Rajdhani\Helpers\ApiError;
use Rajdhani\Http\Request;
use Rajdhani\Services\NewsletterService;

/**
 * `/admin/subscribers` (doc §9.10, §11, RTPP-31). Parse, delegate, respond.
 */
final class SubscriberController
{
    public function __construct(
        private readonly NewsletterService $newsletter = new NewsletterService(),
    ) {
    }

    /** @return array<string,mixed> */
    public function index(Request $request): array
    {
        return $this->newsletter->paginate($request->query);
    }

    /** @return array<string,mixed> */
    public function export(Request $request): array
    {
        return $this->newsletter->exportCsv($request->query);
    }

    /** @return array<string,mixed> */
    public function destroy(Request $request): array
    {
        $id = $request->attribute('id');

        if (!is_string($id) || $id === '') {
            throw ApiError::notFound('No such subscriber');
        }

        $this->newsletter->delete($id);

        return ['deleted' => true];
    }
}
