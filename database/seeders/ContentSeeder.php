<?php

declare(strict_types=1);

namespace Rajdhani\Database\Seeders;

/**
 * The editor-managed marketing blocks (doc 8.7): banners, USP tiles, process
 * steps, stat counters, certifications, testimonials and page copy.
 *
 * Seven small tables in one seeder rather than seven seeders, because they have
 * no relationships between them and are all the same kind of thing: rows an
 * editor rearranges from the admin panel. Splitting them would produce seven
 * files of fifteen lines each and one more ordering question to get wrong.
 *
 * Every table here is insert-if-absent. This is the content the client will
 * replace first (RTPP-86), and re-running the seeders on a site someone has
 * been editing must be safe — that is the whole point of the exercise.
 *
 * Two of these tables carry real unique constraints, `process_steps` on
 * (group, step_number) and `page_blocks` on (page_key, block_key), so their
 * natural keys are enforced by the database. The rest do not: banners and
 * feature items are meant to be repeatable, so the "same row" test is chosen
 * here by hand and is only as good as the choice.
 */
final class ContentSeeder extends Seeder
{
    public function tables(): array
    {
        return [
            'banners',
            'feature_items',
            'process_steps',
            'stat_counters',
            'certifications',
            'testimonials',
            'page_blocks',
        ];
    }

    public function run(): void
    {
        /** @var array{usps:list<array{title:string,description:string,iconName:string}>,stats:list<array{value:string,label:string,iconName:string}>} $content */
        $content = $this->seedData('site-content.json');

        $this->seedBanners();
        $this->seedFeatures($content['usps']);
        $this->seedProcessSteps();
        $this->seedStats($content['stats']);
        $this->seedCertifications();
        $this->seedTestimonials();
        $this->seedPageBlocks();
    }

    private function seedBanners(): void
    {
        $now = $this->now();

        /** @var list<array{placement:string,eyebrow:string|null,title:string,highlight:string|null,subtitle:string|null,image:string|null,cta:array{0:string,1:string}|null}> $banners */
        $banners = [
            [
                'placement' => 'HOME_HERO',
                'eyebrow'   => 'PREMIUM QUALITY TEA',
                'title'     => 'From the Finest Gardens of',
                'highlight' => 'Bangladesh',
                'subtitle'  => 'Blended and packed for the strong, aromatic cup this country drinks every morning.',
                'image'     => 'hero-home',
                'cta'       => ['Explore Our Tea', '/products'],
            ],
            [
                'placement' => 'ABOUT_HERO',
                'eyebrow'   => 'WHO WE ARE',
                'title'     => 'Twenty-Five Years of Tea',
                'highlight' => null,
                'subtitle'  => 'A family business that grew into a nationwide name.',
                'image'     => 'hero-about',
                'cta'       => null,
            ],
            [
                'placement' => 'PRODUCTS_HERO',
                'eyebrow'   => 'OUR RANGE',
                'title'     => 'Every Blend We Make',
                'highlight' => null,
                'subtitle'  => 'Premium blends, classic black, green tea, tea bags and loose leaf.',
                'image'     => 'hero-products',
                'cta'       => null,
            ],
            [
                'placement' => 'DEALER_HERO',
                'eyebrow'   => 'PARTNER WITH US',
                'title'     => 'Become a Rajdhani Dealer',
                'highlight' => null,
                'subtitle'  => 'Sell a brand people already ask for by name.',
                'image'     => 'hero-dealer',
                'cta'       => ['Apply Now', '/dealer#apply'],
            ],
            [
                'placement' => 'DEALER_CTA',
                'eyebrow'   => null,
                'title'     => 'Interested in a dealership?',
                'highlight' => null,
                'subtitle'  => 'We are expanding across all 64 districts.',
                'image'     => null,
                'cta'       => ['Apply for Dealership', '/dealer'],
            ],
        ];

        foreach ($banners as $banner) {
            $this->insertIfAbsent(
                'banners',
                // One banner per placement in the seed set. The table allows
                // several — a rotating hero — but a second seeded row would be
                // indistinguishable from one an editor added.
                ['placement' => $banner['placement']],
                [
                    'title'               => $banner['title'],
                    'title_highlight'     => $banner['highlight'],
                    'subtitle'            => $banner['subtitle'],
                    'eyebrow_text'        => $banner['eyebrow'],
                    'desktop_image_id'    => $banner['image'] === null ? null : $this->mediaId($banner['image']),
                    'mobile_image_id'     => null,
                    'video_url'           => null,
                    'primary_cta_label'   => $banner['cta'][0] ?? null,
                    'primary_cta_url'     => $banner['cta'][1] ?? null,
                    'secondary_cta_label' => null,
                    'secondary_cta_url'   => null,
                    'overlay_opacity'     => 40,
                    'sort_order'          => 1,
                    'status'              => 'PUBLISHED',

                    // No schedule: an unscheduled banner is always live, which
                    // is what a default should be.
                    'starts_at'  => null,
                    'ends_at'    => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'deleted_at' => null,
                ],
            );
        }
    }

    /** @param list<array{title:string,description:string,iconName:string}> $usps */
    private function seedFeatures(array $usps): void
    {
        $order = 0;

        foreach ($usps as $usp) {
            $this->insertIfAbsent(
                'feature_items',
                ['section' => 'HOME_USP', 'title' => $usp['title']],
                [
                    'description'   => $usp['description'],
                    'icon_name'     => $usp['iconName'],
                    'icon_image_id' => null,
                    'icon_bg_color' => '#1B5E20',
                    'sort_order'    => ++$order,
                    'is_active'     => 1,
                ],
            );
        }

        $benefits = [
            ['Protected Territory', 'One dealer per area, so you are not competing with us.'],
            ['Marketing Support', 'Point-of-sale material, signage and campaign artwork.'],
            ['Reliable Supply', 'Scheduled deliveries from a factory that runs year round.'],
            ['Competitive Margins', 'Pricing built for a business, not a side line.'],
        ];
        $order = 0;

        foreach ($benefits as [$title, $description]) {
            $this->insertIfAbsent(
                'feature_items',
                ['section' => 'DEALER_BENEFITS', 'title' => $title],
                [
                    'description'   => $description,
                    'icon_name'     => 'check-circle',
                    'icon_image_id' => null,
                    'icon_bg_color' => '#C9A227',
                    'sort_order'    => ++$order,
                    'is_active'     => 1,
                ],
            );
        }
    }

    private function seedProcessSteps(): void
    {
        $steps = [
            ['Carefully Sourced', 'Leaves selected from estates in Sylhet and Moulvibazar.'],
            ['Graded and Sorted', 'Every lot is graded by leaf size and appearance before it is accepted.'],
            ['Blended', 'Our blenders build each product to a fixed profile, batch after batch.'],
            ['Tested', 'Samples are cupped and lab-tested against the batch specification.'],
            ['Packed and Sealed', 'Sealed in moisture-proof packaging the day it is blended.'],
        ];

        foreach ($steps as $index => [$title, $description]) {
            $this->insertIfAbsent(
                'process_steps',
                ['group' => 'FROM_GARDEN_TO_CUP', 'step_number' => $index + 1],
                [
                    'title'       => $title,
                    'description' => $description,
                    'icon_name'   => 'leaf',
                    'image_id'    => null,
                    'sort_order'  => $index + 1,
                    'is_active'   => 1,
                ],
            );
        }
    }

    /** @param list<array{value:string,label:string,iconName:string}> $stats */
    private function seedStats(array $stats): void
    {
        $order = 0;

        foreach ($stats as $stat) {
            $this->insertIfAbsent(
                'stat_counters',
                ['group' => 'HOME', 'label' => $stat['label']],
                [
                    'value'      => $stat['value'],
                    'icon_name'  => $stat['iconName'],
                    'sort_order' => ++$order,
                    'is_active'  => 1,
                ],
            );
        }
    }

    private function seedCertifications(): void
    {
        $certifications = [
            ['ISO 22000:2018', 'Food Safety Management'],
            ['BSTI Certified', 'Bangladesh Standards and Testing Institution'],
            ['HACCP', 'Hazard Analysis and Critical Control Points'],
        ];
        $order = 0;

        foreach ($certifications as [$name, $subtitle]) {
            $this->insertIfAbsent(
                'certifications',
                ['name' => $name],
                [
                    'subtitle' => $subtitle,
                    'logo_id'  => null,

                    // No certificate PDF: uploading the real documents is the
                    // client's, under RTPP-86. The column is nullable for
                    // exactly this gap.
                    'certificate_file_id' => null,
                    'sort_order'          => ++$order,
                    'is_active'           => 1,
                ],
            );
        }
    }

    private function seedTestimonials(): void
    {
        $testimonials = [
            ['Ahmed Hossain', 'Distributor, Chattogram', 'We have carried Rajdhani for eleven years. Supply is steady and the blend never changes on us.', 5],
            ['Farhana Rahman', 'Retailer, Dhaka', 'Customers ask for it by name. That is the whole review.', 5],
            ['Mizanur Rahman', 'Wholesaler, Sylhet', 'Margins work, deliveries arrive when they said they would.', 4],
        ];
        $order = 0;

        foreach ($testimonials as [$author, $role, $quote, $rating]) {
            $this->insertIfAbsent(
                'testimonials',
                ['author_name' => $author],
                [
                    'author_role' => $role,
                    'avatar_id'   => null,
                    'quote'       => $quote,
                    'rating'      => $rating,
                    'status'      => 'PUBLISHED',
                    'sort_order'  => ++$order,
                ],
            );
        }
    }

    private function seedPageBlocks(): void
    {
        /** @var list<array{page:string,block:string,eyebrow:string|null,heading:string,body:string,image:string|null,bullets:list<string>|null}> $blocks */
        $blocks = [
            [
                'page'    => 'about',
                'block'   => 'our_story',
                'eyebrow' => 'OUR STORY',
                'heading' => 'Built on One Blend',
                'body'    => '<p>Rajdhani Food Products started as a single blend sold from a single shop. Twenty-five years later the blend has not changed and the shop has become a factory.</p>',
                'image'   => 'about-story',
                'bullets' => null,
            ],
            [
                'page'    => 'about',
                'block'   => 'mission',
                'eyebrow' => 'OUR MISSION',
                'heading' => 'Good Tea, Every Packet',
                'body'    => '<p>To put a consistent, honest cup of tea within reach of every household in Bangladesh.</p>',
                'image'   => null,
                'bullets' => null,
            ],
            [
                'page'    => 'about',
                'block'   => 'vision',
                'eyebrow' => 'OUR VISION',
                'heading' => 'The Name People Ask For',
                'body'    => '<p>To be the tea a shopkeeper reaches for without being asked twice.</p>',
                'image'   => null,
                'bullets' => null,
            ],
            [
                'page'    => 'quality',
                'block'   => 'commitment',
                'eyebrow' => 'OUR COMMITMENT',
                'heading' => 'Tested Before It Leaves',
                'body'    => '<p>Every batch is cupped and lab-tested against its specification. A batch that misses the profile does not ship.</p>',
                'image'   => null,
                'bullets' => ['Moisture content', 'Leaf grade', 'Liquor colour and strength', 'Packaging seal integrity'],
            ],
            [
                'page'    => 'dealer',
                'block'   => 'intro',
                'eyebrow' => 'DEALERSHIP',
                'heading' => 'Sell a Brand That Sells Itself',
                'body'    => '<p>We are expanding the dealer network across all 64 districts. Applications are reviewed within a week.</p>',
                'image'   => null,
                'bullets' => null,
            ],
        ];

        foreach ($blocks as $index => $block) {
            $bullets = $block['bullets'];

            $this->insertIfAbsent(
                'page_blocks',
                ['page_key' => $block['page'], 'block_key' => $block['block']],
                [
                    'eyebrow'    => $block['eyebrow'],
                    'heading'    => $block['heading'],
                    'subheading' => null,
                    'body'       => $block['body'],

                    // JSON column: encoded here rather than passed as an array,
                    // because PDO binds strings and MySQL parses them.
                    'bullet_points' => $bullets === null ? null : json_encode($bullets, JSON_THROW_ON_ERROR),
                    'image_id'      => $block['image'] === null ? null : $this->mediaId($block['image']),
                    'cta_label'     => null,
                    'cta_url'       => null,
                    'sort_order'    => $index + 1,
                    'status'        => 'PUBLISHED',
                ],
            );
        }
    }
}
