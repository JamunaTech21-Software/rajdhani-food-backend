<?php

declare(strict_types=1);

namespace Rajdhani\Controllers\Admin;

use Rajdhani\Helpers\ApiError;
use Rajdhani\Http\Request;
use Rajdhani\Services\TestimonialService;

/**
 * `/admin/testimonials` (doc §11, RTPP-24). Parse, delegate, respond.
 */
final class TestimonialController
{
    public function __construct(
        private readonly TestimonialService $testimonials = new TestimonialService(),
    ) {
    }

    /** @return array<string,mixed> */
    public function index(Request $request): array
    {
        return ['data' => $this->testimonials->list($request->query)];
    }

    /** @return array<string,mixed> */
    public function show(Request $request): array
    {
        return $this->testimonials->find($this->id($request));
    }

    /** @return array<string,mixed> */
    public function store(Request $request): array
    {
        return $this->testimonials->create($request->body);
    }

    /** @return array<string,mixed> */
    public function update(Request $request): array
    {
        return $this->testimonials->update($this->id($request), $request->body);
    }

    /** @return array<string,mixed> */
    public function destroy(Request $request): array
    {
        $this->testimonials->delete($this->id($request));

        return ['deleted' => true];
    }

    /** @return array<string,mixed> */
    public function reorder(Request $request): array
    {
        return $this->testimonials->reorder($request->body);
    }

    private function id(Request $request): string
    {
        $id = $request->attribute('id');

        if (!is_string($id) || $id === '') {
            throw ApiError::notFound('No such testimonial');
        }

        return $id;
    }
}
