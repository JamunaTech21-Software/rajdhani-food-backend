<?php

declare(strict_types=1);

namespace Rajdhani\Database\Seeders;

use Rajdhani\Helpers\SlugHelper;

/**
 * One published news post (doc 8.7).
 *
 * One is deliberate. The news list endpoint pages, sorts and filters, and a
 * single row proves the shape without pretending the client has an archive. The
 * post carries a cover image, tags and an author so that every optional field
 * on the endpoint is exercised by something.
 *
 * `author_id` points at the seeded Super Admin. It is nullable — a post whose
 * author was deleted keeps existing — so a missing admin degrades to an
 * author-less post rather than a failed seed.
 */
final class NewsSeeder extends Seeder
{
    public function tables(): array
    {
        return ['news_posts'];
    }

    public function run(): void
    {
        $now = $this->now();
        $title = 'Rajdhani Expands Its Dealer Network to All 64 Districts';

        $email = env('SEED_ADMIN_EMAIL', 'admin@rajdhanifood.com') ?? 'admin@rajdhanifood.com';

        $this->insertIfAbsent(
            'news_posts',
            ['slug' => SlugHelper::make($title)],
            [
                'title'   => $title,
                'excerpt' => 'Our distribution now reaches every district in the country, with local dealers in each.',
                'content' => '<p>Rajdhani Food Products has completed the expansion of its dealer network, with appointed dealers now operating in all 64 districts of Bangladesh.</p>'
                    . '<p>The expansion follows three years of steady growth in the north and south-east, and brings the total number of active dealers past one thousand.</p>'
                    . '<p>Applications for the remaining protected territories are open through the dealership page.</p>',
                'cover_image_id' => $this->mediaId('news-cover-01'),
                'tags'           => json_encode(['dealership', 'network', 'announcement'], JSON_THROW_ON_ERROR),
                'status'         => 'PUBLISHED',
                'is_featured'    => 1,
                'published_at'   => $now,
                'view_count'     => 0,
                'author_id'      => $this->findId('admin_users', ['email' => $email]),
                'meta_title'     => $title . ' — Rajdhani Food Products',
                'meta_description' => 'Rajdhani Food Products now has appointed dealers in all 64 districts of Bangladesh.',
                'created_at'     => $now,
                'updated_at'     => $now,
                'deleted_at'     => null,
            ],
        );
    }
}
