<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;

class ProductUsageGuide
{
    /** @return null|array{title: string, url: string} */
    public function forItem(OrderItem $item): ?array
    {
        $title = trim((string) ($item->usage_guide_snapshotted
            ? $item->usage_guide_title
            : $item->product?->usage_guide_title));
        $rawUrl = trim((string) ($item->usage_guide_snapshotted
            ? $item->usage_guide_url
            : $item->product?->usage_guide_url));

        if ($title === '' || $rawUrl === '') {
            return null;
        }

        return [
            'title' => $title,
            'url' => $this->absoluteUrl($rawUrl),
        ];
    }

    /** @return list<array{title: string, url: string}> */
    public function forOrder(Order $order): array
    {
        $order->loadMissing('orderItems.product');

        return $order->orderItems
            ->map(fn (OrderItem $item): ?array => $this->forItem($item))
            ->filter()
            ->unique(fn (array $guide): string => $guide['title']."\n".$guide['url'])
            ->values()
            ->all();
    }

    private function absoluteUrl(string $url): string
    {
        return str_starts_with($url, '/') ? url($url) : $url;
    }
}
