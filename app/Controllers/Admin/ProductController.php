<?php

declare(strict_types=1);

namespace Rajdhani\Controllers\Admin;

use Rajdhani\Helpers\ApiError;
use Rajdhani\Http\Request;
use Rajdhani\Services\ProductService;

/**
 * `/admin/products` (doc §9.9, RTPP-19). Parse, delegate, respond — every rule
 * lives in the service (doc §13).
 */
final class ProductController
{
    public function __construct(
        private readonly ProductService $products = new ProductService(),
    ) {
    }

    /** @return array<string,mixed> */
    public function index(Request $request): array
    {
        return $this->products->paginate($request->query);
    }

    /** @return array<string,mixed> */
    public function show(Request $request): array
    {
        return $this->products->find($this->id($request));
    }

    /** @return array<string,mixed> */
    public function store(Request $request): array
    {
        return $this->products->create($request->body);
    }

    /** @return array<string,mixed> */
    public function update(Request $request): array
    {
        return $this->products->update($this->id($request), $request->body);
    }

    /** @return array<string,mixed> */
    public function destroy(Request $request): array
    {
        $this->products->delete($this->id($request));

        return ['deleted' => true];
    }

    /** @return array<string,mixed> */
    public function reorder(Request $request): array
    {
        return $this->products->reorder($request->body);
    }

    private function id(Request $request): string
    {
        $id = $request->attribute('id');

        if (!is_string($id) || $id === '') {
            throw ApiError::notFound('No such product');
        }

        return $id;
    }
}
