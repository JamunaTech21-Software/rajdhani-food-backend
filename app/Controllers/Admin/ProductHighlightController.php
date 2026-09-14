<?php

declare(strict_types=1);

namespace Rajdhani\Controllers\Admin;

use Rajdhani\Helpers\ApiError;
use Rajdhani\Http\Request;
use Rajdhani\Services\ProductService;

/** `/admin/products/:id/highlights` (doc §9.9, RTPP-19). See ProductPackSizeController's doc for why this is its own class. */
final class ProductHighlightController
{
    public function __construct(
        private readonly ProductService $products = new ProductService(),
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function index(Request $request): array
    {
        return $this->products->listHighlights($this->productId($request));
    }

    /** @return array<string,mixed> */
    public function show(Request $request): array
    {
        return $this->products->findHighlight($this->productId($request), $this->id($request));
    }

    /** @return array<string,mixed> */
    public function store(Request $request): array
    {
        return $this->products->createHighlight($this->productId($request), $request->body);
    }

    /** @return array<string,mixed> */
    public function update(Request $request): array
    {
        return $this->products->updateHighlight($this->productId($request), $this->id($request), $request->body);
    }

    /** @return array<string,mixed> */
    public function destroy(Request $request): array
    {
        $this->products->deleteHighlight($this->productId($request), $this->id($request));

        return ['deleted' => true];
    }

    /** @return array<string,mixed> */
    public function reorder(Request $request): array
    {
        return $this->products->reorderHighlights($this->productId($request), $request->body);
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
        $id = $request->attribute('highlightId');

        if (!is_string($id) || $id === '') {
            throw ApiError::notFound('No such highlight');
        }

        return $id;
    }
}
