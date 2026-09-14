<?php

declare(strict_types=1);

namespace Rajdhani\Controllers\Admin;

use Rajdhani\Helpers\ApiError;
use Rajdhani\Http\Request;
use Rajdhani\Services\ProductService;

/**
 * `/admin/products/:id/pack-sizes` (doc §9.9, RTPP-19).
 *
 * A sub-resource controller, not a nested route on `ProductController` — the
 * two id segments (`:id` for the product, `:packSizeId` for the row) are both
 * real path parameters, and keeping this as its own class matches the router's
 * one-controller-per-resource convention rather than overloading the parent
 * with a resource it does not own.
 */
final class ProductPackSizeController
{
    public function __construct(
        private readonly ProductService $products = new ProductService(),
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function index(Request $request): array
    {
        return $this->products->listPackSizes($this->productId($request));
    }

    /** @return array<string,mixed> */
    public function show(Request $request): array
    {
        return $this->products->findPackSize($this->productId($request), $this->id($request));
    }

    /** @return array<string,mixed> */
    public function store(Request $request): array
    {
        return $this->products->createPackSize($this->productId($request), $request->body);
    }

    /** @return array<string,mixed> */
    public function update(Request $request): array
    {
        return $this->products->updatePackSize($this->productId($request), $this->id($request), $request->body);
    }

    /** @return array<string,mixed> */
    public function destroy(Request $request): array
    {
        $this->products->deletePackSize($this->productId($request), $this->id($request));

        return ['deleted' => true];
    }

    /** @return array<string,mixed> */
    public function reorder(Request $request): array
    {
        return $this->products->reorderPackSizes($this->productId($request), $request->body);
    }

    private function productId(Request $request): string
    {
        $id = $request->attribute('id');

        if (!is_string($id) || $id === '') {
            throw ApiError::notFound('No such product');
        }

        return $id;
    }

    private function id(Request $request): string
    {
        $id = $request->attribute('packSizeId');

        if (!is_string($id) || $id === '') {
            throw ApiError::notFound('No such pack size');
        }

        return $id;
    }
}
