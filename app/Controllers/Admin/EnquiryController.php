<?php

declare(strict_types=1);

namespace Rajdhani\Controllers\Admin;

use Rajdhani\Helpers\ApiError;
use Rajdhani\Http\Request;
use Rajdhani\Services\EnquiryService;

/**
 * `/admin/enquiries` (doc §9.10, RTPP-29). Parse, delegate, respond.
 */
final class EnquiryController
{
    public function __construct(
        private readonly EnquiryService $enquiries = new EnquiryService(),
    ) {
    }

    /** @return array<string,mixed> */
    public function index(Request $request): array
    {
        return $this->enquiries->paginate($request->query);
    }

    /** @return array<string,mixed> */
    public function show(Request $request): array
    {
        return $this->enquiries->find($this->id($request));
    }

    /** @return array<string,mixed> */
    public function update(Request $request): array
    {
        return $this->enquiries->update($this->id($request), $request->body);
    }

    /** @return array<string,mixed> */
    public function export(Request $request): array
    {
        return $this->enquiries->exportCsv($request->query);
    }

    private function id(Request $request): string
    {
        $id = $request->attribute('id');

        if (!is_string($id) || $id === '') {
            throw ApiError::notFound('No such enquiry');
        }

        return $id;
    }
}
