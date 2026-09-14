<?php

declare(strict_types=1);

/**
 * /admin/* — sections 9.8 to 9.11.
 *
 * **Every route in this file carries two middleware, in this order:**
 *
 *     [RequireAdmin::class, RequireRole::read|write|own(Capability::…)]
 *
 * RequireAdmin establishes who the caller is; RequireRole decides what they may
 * do against the §7.3 matrix. Role is the only dimension of authorisation
 * (doc §7.4) — there is no brand check any more, and there is no second gate.
 *
 * Pick the level from the verb, not from the resource:
 *
 *     $r->get('/products',      …, [RequireAdmin::class, RequireRole::read(Capability::PRODUCTS)]);
 *     $r->post('/products',     …, [RequireAdmin::class, RequireRole::write(Capability::PRODUCTS)]);
 *     $r->delete('/media/:id',  …, [RequireAdmin::class, RequireRole::own(Capability::MEDIA)]);
 *
 * A route registered with RequireAdmin alone is **unauthorised by anything but
 * authentication** — every admin, of every role, can reach it. That is correct
 * for `/auth/admin/me` and almost nothing else.
 *
 * `own()` is the exception rather than the pattern: it admits the request and
 * records `row_scope` on it, and the *service* must then filter to rows the
 * caller uploaded. It exists for one cell of §7.3 — an Editor deleting their own
 * media — and using it anywhere else means inventing a rule the document does
 * not contain.
 *
 * Populated from RTPP-14 onward.
 *
 * **Every mutating route also carries an `AuditLog` instance** (RTPP-35), last
 * in the middleware list, configured with a `state` closure that re-reads the
 * row through the resource's own repository. See that class's doc for why it
 * is a closure rather than a repository class name, and for the nested-id and
 * singleton cases below.
 */

use Rajdhani\Auth\Capability;
use Rajdhani\Controllers\Admin\AuditController;
use Rajdhani\Controllers\Admin\BannerController;
use Rajdhani\Controllers\Admin\CacheController;
use Rajdhani\Controllers\Admin\CategoryController;
use Rajdhani\Controllers\Admin\CertificationController;
use Rajdhani\Controllers\Admin\ContactMessageController;
use Rajdhani\Controllers\Admin\DashboardController;
use Rajdhani\Controllers\Admin\DealerApplicationController;
use Rajdhani\Controllers\Admin\DownloadController;
use Rajdhani\Controllers\Admin\EnquiryController;
use Rajdhani\Controllers\Admin\FeatureItemController;
use Rajdhani\Controllers\Admin\GalleryCategoryController;
use Rajdhani\Controllers\Admin\GalleryImageController;
use Rajdhani\Controllers\Admin\MediaController;
use Rajdhani\Controllers\Admin\MenuLinkController;
use Rajdhani\Controllers\Admin\NewsController;
use Rajdhani\Controllers\Admin\PageBlockController;
use Rajdhani\Controllers\Admin\ProcessStepController;
use Rajdhani\Controllers\Admin\ProductController;
use Rajdhani\Controllers\Admin\ProductHighlightController;
use Rajdhani\Controllers\Admin\ProductImageController;
use Rajdhani\Controllers\Admin\ProductPackSizeController;
use Rajdhani\Controllers\Admin\ReviewController;
use Rajdhani\Controllers\Admin\SettingsController;
use Rajdhani\Controllers\Admin\SiteProfileController;
use Rajdhani\Controllers\Admin\SocialLinkController;
use Rajdhani\Controllers\Admin\StatCounterController;
use Rajdhani\Controllers\Admin\SubscriberController;
use Rajdhani\Controllers\Admin\TestimonialController;
use Rajdhani\Http\Request;
use Rajdhani\Http\Router;
use Rajdhani\Middleware\AuditLog;
use Rajdhani\Middleware\RequireAdmin;
use Rajdhani\Middleware\RequireRole;
use Rajdhani\Middleware\RequireSuperAdmin;
use Rajdhani\Repositories\BannerRepository;
use Rajdhani\Repositories\CategoryRepository;
use Rajdhani\Repositories\CertificationRepository;
use Rajdhani\Repositories\ContactMessageRepository;
use Rajdhani\Repositories\DealerApplicationRepository;
use Rajdhani\Repositories\DownloadRepository;
use Rajdhani\Repositories\EnquiryRepository;
use Rajdhani\Repositories\FeatureItemRepository;
use Rajdhani\Repositories\GalleryCategoryRepository;
use Rajdhani\Repositories\GalleryImageRepository;
use Rajdhani\Repositories\MediaRepository;
use Rajdhani\Repositories\MenuLinkRepository;
use Rajdhani\Repositories\NewsletterSubscriberRepository;
use Rajdhani\Repositories\NewsRepository;
use Rajdhani\Repositories\PageBlockRepository;
use Rajdhani\Repositories\ProcessStepRepository;
use Rajdhani\Repositories\ProductRepository;
use Rajdhani\Repositories\ReviewRepository;
use Rajdhani\Repositories\SettingsRepository;
use Rajdhani\Repositories\SiteProfileRepository;
use Rajdhani\Repositories\SocialLinkRepository;
use Rajdhani\Repositories\StatCounterRepository;
use Rajdhani\Repositories\TestimonialRepository;

/** @var Router $router */

$router->group('/admin', [], static function (Router $r): void {
    // RTPP-14 — site profile. Settings are Super-Admin-only in §7.3, so this
    // read is deliberately narrower than /public/layout, which serves the same
    // row to everyone: what is gated is the settings screen, not the content.
    $r->get(
        '/site-profile',
        Router::to(SiteProfileController::class, 'show'),
        [RequireAdmin::class, RequireRole::read(Capability::SETTINGS)],
    );
    $r->patch(
        '/site-profile',
        Router::to(SiteProfileController::class, 'update'),
        [
            RequireAdmin::class,
            RequireRole::write(Capability::SETTINGS),
            new AuditLog('SiteProfile', state: fn (Request $r) => (new SiteProfileRepository())->find(), singleton: true),
        ],
    );

    // RTPP-18 — categories. Editor and Super Admin both hold WRITE on
    // PRODUCTS (§7.3); Sales holds only READ, so /categories is visible in
    // the dashboard but its mutating routes are not. /reorder is a write:
    // reordering changes what the public site shows.
    $r->get(
        '/categories',
        Router::to(CategoryController::class, 'index'),
        [RequireAdmin::class, RequireRole::read(Capability::PRODUCTS)],
    );
    $r->get(
        '/categories/:id',
        Router::to(CategoryController::class, 'show'),
        [RequireAdmin::class, RequireRole::read(Capability::PRODUCTS)],
    );
    $r->post(
        '/categories',
        Router::to(CategoryController::class, 'store'),
        [RequireAdmin::class, RequireRole::write(Capability::PRODUCTS), new AuditLog('Category')],
    );
    $r->patch(
        '/categories/reorder',
        Router::to(CategoryController::class, 'reorder'),
        [RequireAdmin::class, RequireRole::write(Capability::PRODUCTS), new AuditLog('Category', action: 'reorder')],
    );
    $r->patch(
        '/categories/:id',
        Router::to(CategoryController::class, 'update'),
        [
            RequireAdmin::class,
            RequireRole::write(Capability::PRODUCTS),
            new AuditLog('Category', state: fn (Request $r) => (new CategoryRepository())->find((string) $r->attribute('id'))),
        ],
    );
    $r->delete(
        '/categories/:id',
        Router::to(CategoryController::class, 'destroy'),
        [
            RequireAdmin::class,
            RequireRole::write(Capability::PRODUCTS),
            new AuditLog('Category', state: fn (Request $r) => (new CategoryRepository())->find((string) $r->attribute('id'))),
        ],
    );

    // RTPP-19 — products, and the three child collections that make up the
    // tabbed admin product form. Same role gate as categories: Editor and
    // Super Admin hold WRITE on PRODUCTS, Sales holds only READ (§7.3).
    //
    // Every /reorder route is registered before its sibling /:id route for
    // the same HTTP method — the router matches in registration order, and
    // :id would otherwise capture the literal segment "reorder" as an id.
    $r->get(
        '/products',
        Router::to(ProductController::class, 'index'),
        [RequireAdmin::class, RequireRole::read(Capability::PRODUCTS)],
    );
    $r->post(
        '/products',
        Router::to(ProductController::class, 'store'),
        [RequireAdmin::class, RequireRole::write(Capability::PRODUCTS), new AuditLog('Product')],
    );
    $r->patch(
        '/products/reorder',
        Router::to(ProductController::class, 'reorder'),
        [RequireAdmin::class, RequireRole::write(Capability::PRODUCTS), new AuditLog('Product', action: 'reorder')],
    );
    $r->get(
        '/products/:id',
        Router::to(ProductController::class, 'show'),
        [RequireAdmin::class, RequireRole::read(Capability::PRODUCTS)],
    );
    $r->patch(
        '/products/:id',
        Router::to(ProductController::class, 'update'),
        [
            RequireAdmin::class,
            RequireRole::write(Capability::PRODUCTS),
            new AuditLog('Product', state: fn (Request $r) => (new ProductRepository())->find((string) $r->attribute('id'))),
        ],
    );
    $r->delete(
        '/products/:id',
        Router::to(ProductController::class, 'destroy'),
        [
            RequireAdmin::class,
            RequireRole::write(Capability::PRODUCTS),
            new AuditLog('Product', state: fn (Request $r) => (new ProductRepository())->find((string) $r->attribute('id'))),
        ],
    );

    // Pack sizes
    $r->get(
        '/products/:id/pack-sizes',
        Router::to(ProductPackSizeController::class, 'index'),
        [RequireAdmin::class, RequireRole::read(Capability::PRODUCTS)],
    );
    $r->post(
        '/products/:id/pack-sizes',
        Router::to(ProductPackSizeController::class, 'store'),
        [RequireAdmin::class, RequireRole::write(Capability::PRODUCTS), new AuditLog('ProductPackSize')],
    );
    $r->patch(
        '/products/:id/pack-sizes/reorder',
        Router::to(ProductPackSizeController::class, 'reorder'),
        [RequireAdmin::class, RequireRole::write(Capability::PRODUCTS), new AuditLog('ProductPackSize', action: 'reorder')],
    );
    $r->get(
        '/products/:id/pack-sizes/:packSizeId',
        Router::to(ProductPackSizeController::class, 'show'),
        [RequireAdmin::class, RequireRole::read(Capability::PRODUCTS)],
    );
    $r->patch(
        '/products/:id/pack-sizes/:packSizeId',
        Router::to(ProductPackSizeController::class, 'update'),
        [
            RequireAdmin::class,
            RequireRole::write(Capability::PRODUCTS),
            new AuditLog(
                'ProductPackSize',
                state: fn (Request $r) => (new ProductRepository())->findPackSize((string) $r->attribute('id'), (string) $r->attribute('packSizeId')),
                idAttribute: 'packSizeId',
            ),
        ],
    );
    $r->delete(
        '/products/:id/pack-sizes/:packSizeId',
        Router::to(ProductPackSizeController::class, 'destroy'),
        [
            RequireAdmin::class,
            RequireRole::write(Capability::PRODUCTS),
            new AuditLog(
                'ProductPackSize',
                state: fn (Request $r) => (new ProductRepository())->findPackSize((string) $r->attribute('id'), (string) $r->attribute('packSizeId')),
                idAttribute: 'packSizeId',
            ),
        ],
    );

    // Highlights
    $r->get(
        '/products/:id/highlights',
        Router::to(ProductHighlightController::class, 'index'),
        [RequireAdmin::class, RequireRole::read(Capability::PRODUCTS)],
    );
    $r->post(
        '/products/:id/highlights',
        Router::to(ProductHighlightController::class, 'store'),
        [RequireAdmin::class, RequireRole::write(Capability::PRODUCTS), new AuditLog('ProductHighlight')],
    );
    $r->patch(
        '/products/:id/highlights/reorder',
        Router::to(ProductHighlightController::class, 'reorder'),
        [RequireAdmin::class, RequireRole::write(Capability::PRODUCTS), new AuditLog('ProductHighlight', action: 'reorder')],
    );
    $r->get(
        '/products/:id/highlights/:highlightId',
        Router::to(ProductHighlightController::class, 'show'),
        [RequireAdmin::class, RequireRole::read(Capability::PRODUCTS)],
    );
    $r->patch(
        '/products/:id/highlights/:highlightId',
        Router::to(ProductHighlightController::class, 'update'),
        [
            RequireAdmin::class,
            RequireRole::write(Capability::PRODUCTS),
            new AuditLog(
                'ProductHighlight',
                state: fn (Request $r) => (new ProductRepository())->findHighlight((string) $r->attribute('id'), (string) $r->attribute('highlightId')),
                idAttribute: 'highlightId',
            ),
        ],
    );
    $r->delete(
        '/products/:id/highlights/:highlightId',
        Router::to(ProductHighlightController::class, 'destroy'),
        [
            RequireAdmin::class,
            RequireRole::write(Capability::PRODUCTS),
            new AuditLog(
                'ProductHighlight',
                state: fn (Request $r) => (new ProductRepository())->findHighlight((string) $r->attribute('id'), (string) $r->attribute('highlightId')),
                idAttribute: 'highlightId',
            ),
        ],
    );

    // Images
    $r->get(
        '/products/:id/images',
        Router::to(ProductImageController::class, 'index'),
        [RequireAdmin::class, RequireRole::read(Capability::PRODUCTS)],
    );
    $r->post(
        '/products/:id/images',
        Router::to(ProductImageController::class, 'store'),
        [RequireAdmin::class, RequireRole::write(Capability::PRODUCTS), new AuditLog('ProductImage')],
    );
    $r->patch(
        '/products/:id/images/reorder',
        Router::to(ProductImageController::class, 'reorder'),
        [RequireAdmin::class, RequireRole::write(Capability::PRODUCTS), new AuditLog('ProductImage', action: 'reorder')],
    );
    $r->get(
        '/products/:id/images/:imageId',
        Router::to(ProductImageController::class, 'show'),
        [RequireAdmin::class, RequireRole::read(Capability::PRODUCTS)],
    );
    $r->patch(
        '/products/:id/images/:imageId',
        Router::to(ProductImageController::class, 'update'),
        [
            RequireAdmin::class,
            RequireRole::write(Capability::PRODUCTS),
            new AuditLog(
                'ProductImage',
                state: fn (Request $r) => (new ProductRepository())->findImage((string) $r->attribute('id'), (string) $r->attribute('imageId')),
                idAttribute: 'imageId',
            ),
        ],
    );
    $r->delete(
        '/products/:id/images/:imageId',
        Router::to(ProductImageController::class, 'destroy'),
        [
            RequireAdmin::class,
            RequireRole::write(Capability::PRODUCTS),
            new AuditLog(
                'ProductImage',
                state: fn (Request $r) => (new ProductRepository())->findImage((string) $r->attribute('id'), (string) $r->attribute('imageId')),
                idAttribute: 'imageId',
            ),
        ],
    );

    // RTPP-21 — signed direct-to-Cloudinary upload. Gated at `own()`, not
    // `write()`: an Editor's MEDIA cell in §7.3 is OWN, not WRITE (WRITE is
    // Super Admin only), and `own()` is exactly "at least OWN, or a WRITE
    // holder above it" — the correct threshold for "may create an asset at
    // all". Creating never needs the row-scope filtering `own()` also records
    // on the request; there is no "someone else's row" to distinguish from
    // when the row being created is the caller's own by construction. Scope
    // filtering is what RTPP-22's delete route will actually consume.
    $r->post(
        '/media/signature',
        Router::to(MediaController::class, 'signature'),
        [RequireAdmin::class, RequireRole::own(Capability::MEDIA)],
    );
    $r->post(
        '/media',
        Router::to(MediaController::class, 'store'),
        [RequireAdmin::class, RequireRole::own(Capability::MEDIA), new AuditLog('Media')],
    );

    // RTPP-22 — this is the route `own()` was actually written for: an
    // Editor may delete media they uploaded, a Super Admin may delete any
    // asset. `MediaService::delete()` is what tells the two apart, from the
    // `row_scope` this middleware records.
    $r->delete(
        '/media/:id',
        Router::to(MediaController::class, 'destroy'),
        [
            RequireAdmin::class,
            RequireRole::own(Capability::MEDIA),
            new AuditLog('Media', state: fn (Request $r) => (new MediaRepository())->find((string) $r->attribute('id'))),
        ],
    );

    // RTPP-23 — banners. §7.3's "Banners & page content" row is CONTENT:
    // Editor and Super Admin hold WRITE, Sales holds none at all (unlike
    // PRODUCTS, where Sales gets READ) — there is no admin-facing read path
    // for Sales here, only the public listing.
    $r->get(
        '/banners',
        Router::to(BannerController::class, 'index'),
        [RequireAdmin::class, RequireRole::read(Capability::CONTENT)],
    );
    $r->post(
        '/banners',
        Router::to(BannerController::class, 'store'),
        [RequireAdmin::class, RequireRole::write(Capability::CONTENT), new AuditLog('Banner')],
    );
    $r->patch(
        '/banners/reorder',
        Router::to(BannerController::class, 'reorder'),
        [RequireAdmin::class, RequireRole::write(Capability::CONTENT), new AuditLog('Banner', action: 'reorder')],
    );
    $r->get(
        '/banners/:id',
        Router::to(BannerController::class, 'show'),
        [RequireAdmin::class, RequireRole::read(Capability::CONTENT)],
    );
    $r->patch(
        '/banners/:id',
        Router::to(BannerController::class, 'update'),
        [
            RequireAdmin::class,
            RequireRole::write(Capability::CONTENT),
            new AuditLog('Banner', state: fn (Request $r) => (new BannerRepository())->find((string) $r->attribute('id'))),
        ],
    );
    $r->delete(
        '/banners/:id',
        Router::to(BannerController::class, 'destroy'),
        [
            RequireAdmin::class,
            RequireRole::write(Capability::CONTENT),
            new AuditLog('Banner', state: fn (Request $r) => (new BannerRepository())->find((string) $r->attribute('id'))),
        ],
    );

    // RTPP-24 — the content module: five flat, sortable resources gated at
    // MARKETING (doc §7.3: "Testimonials, certifications, stats, process
    // steps" — feature items share the same "Sections" screen, doc §11, and
    // the same capability), plus page blocks, gated at CONTENT alongside
    // banners since both are "Banners and page content" in §7.3's row.
    // None of these six tables has a `deleted_at` column — nothing
    // references any of their ids, so `destroy()` issues a real `DELETE`.

    // Feature items
    $r->get(
        '/feature-items',
        Router::to(FeatureItemController::class, 'index'),
        [RequireAdmin::class, RequireRole::read(Capability::MARKETING)],
    );
    $r->post(
        '/feature-items',
        Router::to(FeatureItemController::class, 'store'),
        [RequireAdmin::class, RequireRole::write(Capability::MARKETING), new AuditLog('FeatureItem')],
    );
    $r->patch(
        '/feature-items/reorder',
        Router::to(FeatureItemController::class, 'reorder'),
        [RequireAdmin::class, RequireRole::write(Capability::MARKETING), new AuditLog('FeatureItem', action: 'reorder')],
    );
    $r->get(
        '/feature-items/:id',
        Router::to(FeatureItemController::class, 'show'),
        [RequireAdmin::class, RequireRole::read(Capability::MARKETING)],
    );
    $r->patch(
        '/feature-items/:id',
        Router::to(FeatureItemController::class, 'update'),
        [
            RequireAdmin::class,
            RequireRole::write(Capability::MARKETING),
            new AuditLog('FeatureItem', state: fn (Request $r) => (new FeatureItemRepository())->find((string) $r->attribute('id'))),
        ],
    );
    $r->delete(
        '/feature-items/:id',
        Router::to(FeatureItemController::class, 'destroy'),
        [
            RequireAdmin::class,
            RequireRole::write(Capability::MARKETING),
            new AuditLog('FeatureItem', state: fn (Request $r) => (new FeatureItemRepository())->find((string) $r->attribute('id'))),
        ],
    );

    // Process steps
    $r->get(
        '/process-steps',
        Router::to(ProcessStepController::class, 'index'),
        [RequireAdmin::class, RequireRole::read(Capability::MARKETING)],
    );
    $r->post(
        '/process-steps',
        Router::to(ProcessStepController::class, 'store'),
        [RequireAdmin::class, RequireRole::write(Capability::MARKETING), new AuditLog('ProcessStep')],
    );
    $r->patch(
        '/process-steps/reorder',
        Router::to(ProcessStepController::class, 'reorder'),
        [RequireAdmin::class, RequireRole::write(Capability::MARKETING), new AuditLog('ProcessStep', action: 'reorder')],
    );
    $r->get(
        '/process-steps/:id',
        Router::to(ProcessStepController::class, 'show'),
        [RequireAdmin::class, RequireRole::read(Capability::MARKETING)],
    );
    $r->patch(
        '/process-steps/:id',
        Router::to(ProcessStepController::class, 'update'),
        [
            RequireAdmin::class,
            RequireRole::write(Capability::MARKETING),
            new AuditLog('ProcessStep', state: fn (Request $r) => (new ProcessStepRepository())->find((string) $r->attribute('id'))),
        ],
    );
    $r->delete(
        '/process-steps/:id',
        Router::to(ProcessStepController::class, 'destroy'),
        [
            RequireAdmin::class,
            RequireRole::write(Capability::MARKETING),
            new AuditLog('ProcessStep', state: fn (Request $r) => (new ProcessStepRepository())->find((string) $r->attribute('id'))),
        ],
    );

    // Stat counters
    $r->get(
        '/stat-counters',
        Router::to(StatCounterController::class, 'index'),
        [RequireAdmin::class, RequireRole::read(Capability::MARKETING)],
    );
    $r->post(
        '/stat-counters',
        Router::to(StatCounterController::class, 'store'),
        [RequireAdmin::class, RequireRole::write(Capability::MARKETING), new AuditLog('StatCounter')],
    );
    $r->patch(
        '/stat-counters/reorder',
        Router::to(StatCounterController::class, 'reorder'),
        [RequireAdmin::class, RequireRole::write(Capability::MARKETING), new AuditLog('StatCounter', action: 'reorder')],
    );
    $r->get(
        '/stat-counters/:id',
        Router::to(StatCounterController::class, 'show'),
        [RequireAdmin::class, RequireRole::read(Capability::MARKETING)],
    );
    $r->patch(
        '/stat-counters/:id',
        Router::to(StatCounterController::class, 'update'),
        [
            RequireAdmin::class,
            RequireRole::write(Capability::MARKETING),
            new AuditLog('StatCounter', state: fn (Request $r) => (new StatCounterRepository())->find((string) $r->attribute('id'))),
        ],
    );
    $r->delete(
        '/stat-counters/:id',
        Router::to(StatCounterController::class, 'destroy'),
        [
            RequireAdmin::class,
            RequireRole::write(Capability::MARKETING),
            new AuditLog('StatCounter', state: fn (Request $r) => (new StatCounterRepository())->find((string) $r->attribute('id'))),
        ],
    );

    // Certifications
    $r->get(
        '/certifications',
        Router::to(CertificationController::class, 'index'),
        [RequireAdmin::class, RequireRole::read(Capability::MARKETING)],
    );
    $r->post(
        '/certifications',
        Router::to(CertificationController::class, 'store'),
        [RequireAdmin::class, RequireRole::write(Capability::MARKETING), new AuditLog('Certification')],
    );
    $r->patch(
        '/certifications/reorder',
        Router::to(CertificationController::class, 'reorder'),
        [RequireAdmin::class, RequireRole::write(Capability::MARKETING), new AuditLog('Certification', action: 'reorder')],
    );
    $r->get(
        '/certifications/:id',
        Router::to(CertificationController::class, 'show'),
        [RequireAdmin::class, RequireRole::read(Capability::MARKETING)],
    );
    $r->patch(
        '/certifications/:id',
        Router::to(CertificationController::class, 'update'),
        [
            RequireAdmin::class,
            RequireRole::write(Capability::MARKETING),
            new AuditLog('Certification', state: fn (Request $r) => (new CertificationRepository())->find((string) $r->attribute('id'))),
        ],
    );
    $r->delete(
        '/certifications/:id',
        Router::to(CertificationController::class, 'destroy'),
        [
            RequireAdmin::class,
            RequireRole::write(Capability::MARKETING),
            new AuditLog('Certification', state: fn (Request $r) => (new CertificationRepository())->find((string) $r->attribute('id'))),
        ],
    );

    // Testimonials
    $r->get(
        '/testimonials',
        Router::to(TestimonialController::class, 'index'),
        [RequireAdmin::class, RequireRole::read(Capability::MARKETING)],
    );
    $r->post(
        '/testimonials',
        Router::to(TestimonialController::class, 'store'),
        [RequireAdmin::class, RequireRole::write(Capability::MARKETING), new AuditLog('Testimonial')],
    );
    $r->patch(
        '/testimonials/reorder',
        Router::to(TestimonialController::class, 'reorder'),
        [RequireAdmin::class, RequireRole::write(Capability::MARKETING), new AuditLog('Testimonial', action: 'reorder')],
    );
    $r->get(
        '/testimonials/:id',
        Router::to(TestimonialController::class, 'show'),
        [RequireAdmin::class, RequireRole::read(Capability::MARKETING)],
    );
    $r->patch(
        '/testimonials/:id',
        Router::to(TestimonialController::class, 'update'),
        [
            RequireAdmin::class,
            RequireRole::write(Capability::MARKETING),
            new AuditLog('Testimonial', state: fn (Request $r) => (new TestimonialRepository())->find((string) $r->attribute('id'))),
        ],
    );
    $r->delete(
        '/testimonials/:id',
        Router::to(TestimonialController::class, 'destroy'),
        [
            RequireAdmin::class,
            RequireRole::write(Capability::MARKETING),
            new AuditLog('Testimonial', state: fn (Request $r) => (new TestimonialRepository())->find((string) $r->attribute('id'))),
        ],
    );

    // Page blocks
    $r->get(
        '/page-blocks',
        Router::to(PageBlockController::class, 'index'),
        [RequireAdmin::class, RequireRole::read(Capability::CONTENT)],
    );
    $r->post(
        '/page-blocks',
        Router::to(PageBlockController::class, 'store'),
        [RequireAdmin::class, RequireRole::write(Capability::CONTENT), new AuditLog('PageBlock')],
    );
    $r->patch(
        '/page-blocks/reorder',
        Router::to(PageBlockController::class, 'reorder'),
        [RequireAdmin::class, RequireRole::write(Capability::CONTENT), new AuditLog('PageBlock', action: 'reorder')],
    );
    $r->get(
        '/page-blocks/:id',
        Router::to(PageBlockController::class, 'show'),
        [RequireAdmin::class, RequireRole::read(Capability::CONTENT)],
    );
    $r->patch(
        '/page-blocks/:id',
        Router::to(PageBlockController::class, 'update'),
        [
            RequireAdmin::class,
            RequireRole::write(Capability::CONTENT),
            new AuditLog('PageBlock', state: fn (Request $r) => (new PageBlockRepository())->find((string) $r->attribute('id'))),
        ],
    );
    $r->delete(
        '/page-blocks/:id',
        Router::to(PageBlockController::class, 'destroy'),
        [
            RequireAdmin::class,
            RequireRole::write(Capability::CONTENT),
            new AuditLog('PageBlock', state: fn (Request $r) => (new PageBlockRepository())->find((string) $r->attribute('id'))),
        ],
    );

    // RTPP-25 — gallery. §7.3's GALLERY row is its own capability, WRITE for
    // both Editor and Super Admin (there is no Sales read path here, unlike
    // PRODUCTS). Categories are a flat, unscoped reorder, same shape as
    // categories/certifications; images are scoped to one category, same
    // shape as banners/page-blocks. Deleting a category cascades to its
    // images at the database level — see `GalleryCategoryRepository`'s class
    // doc — so that route carries real destructive weight despite looking
    // identical to every other `DELETE /:id` above it.
    $r->get(
        '/gallery/categories',
        Router::to(GalleryCategoryController::class, 'index'),
        [RequireAdmin::class, RequireRole::read(Capability::GALLERY)],
    );
    $r->post(
        '/gallery/categories',
        Router::to(GalleryCategoryController::class, 'store'),
        [RequireAdmin::class, RequireRole::write(Capability::GALLERY), new AuditLog('GalleryCategory')],
    );
    $r->patch(
        '/gallery/categories/reorder',
        Router::to(GalleryCategoryController::class, 'reorder'),
        [RequireAdmin::class, RequireRole::write(Capability::GALLERY), new AuditLog('GalleryCategory', action: 'reorder')],
    );
    $r->get(
        '/gallery/categories/:id',
        Router::to(GalleryCategoryController::class, 'show'),
        [RequireAdmin::class, RequireRole::read(Capability::GALLERY)],
    );
    $r->patch(
        '/gallery/categories/:id',
        Router::to(GalleryCategoryController::class, 'update'),
        [
            RequireAdmin::class,
            RequireRole::write(Capability::GALLERY),
            new AuditLog('GalleryCategory', state: fn (Request $r) => (new GalleryCategoryRepository())->find((string) $r->attribute('id'))),
        ],
    );
    $r->delete(
        '/gallery/categories/:id',
        Router::to(GalleryCategoryController::class, 'destroy'),
        [
            RequireAdmin::class,
            RequireRole::write(Capability::GALLERY),
            new AuditLog('GalleryCategory', state: fn (Request $r) => (new GalleryCategoryRepository())->find((string) $r->attribute('id'))),
        ],
    );

    // Gallery images. `/bulk` is POST-only and a distinct literal segment from
    // `/gallery/images` (create) and `/gallery/images/:id` (no POST route
    // exists there), so unlike `/reorder` it carries no registration-order
    // requirement — kept here, before `/reorder`, purely for readability.
    $r->get(
        '/gallery/images',
        Router::to(GalleryImageController::class, 'index'),
        [RequireAdmin::class, RequireRole::read(Capability::GALLERY)],
    );
    $r->post(
        '/gallery/images',
        Router::to(GalleryImageController::class, 'store'),
        [RequireAdmin::class, RequireRole::write(Capability::GALLERY), new AuditLog('GalleryImage')],
    );
    $r->post(
        '/gallery/images/bulk',
        Router::to(GalleryImageController::class, 'bulkStore'),
        [RequireAdmin::class, RequireRole::write(Capability::GALLERY), new AuditLog('GalleryImage', action: 'bulk_create')],
    );
    $r->patch(
        '/gallery/images/reorder',
        Router::to(GalleryImageController::class, 'reorder'),
        [RequireAdmin::class, RequireRole::write(Capability::GALLERY), new AuditLog('GalleryImage', action: 'reorder')],
    );
    $r->get(
        '/gallery/images/:id',
        Router::to(GalleryImageController::class, 'show'),
        [RequireAdmin::class, RequireRole::read(Capability::GALLERY)],
    );
    $r->patch(
        '/gallery/images/:id',
        Router::to(GalleryImageController::class, 'update'),
        [
            RequireAdmin::class,
            RequireRole::write(Capability::GALLERY),
            new AuditLog('GalleryImage', state: fn (Request $r) => (new GalleryImageRepository())->find((string) $r->attribute('id'))),
        ],
    );
    $r->delete(
        '/gallery/images/:id',
        Router::to(GalleryImageController::class, 'destroy'),
        [
            RequireAdmin::class,
            RequireRole::write(Capability::GALLERY),
            new AuditLog('GalleryImage', state: fn (Request $r) => (new GalleryImageRepository())->find((string) $r->attribute('id'))),
        ],
    );

    // RTPP-26 — news. §7.3's NEWS row is its own capability, WRITE for both
    // Editor and Super Admin. No `/reorder` route: there is no `sort_order`
    // column on `news_posts` — the public listing orders by `published_at`,
    // not a manually dragged position.
    $r->get(
        '/news',
        Router::to(NewsController::class, 'index'),
        [RequireAdmin::class, RequireRole::read(Capability::NEWS)],
    );
    $r->post(
        '/news',
        Router::to(NewsController::class, 'store'),
        [RequireAdmin::class, RequireRole::write(Capability::NEWS), new AuditLog('News')],
    );
    $r->get(
        '/news/:id',
        Router::to(NewsController::class, 'show'),
        [RequireAdmin::class, RequireRole::read(Capability::NEWS)],
    );
    $r->patch(
        '/news/:id',
        Router::to(NewsController::class, 'update'),
        [
            RequireAdmin::class,
            RequireRole::write(Capability::NEWS),
            new AuditLog('News', state: fn (Request $r) => (new NewsRepository())->find((string) $r->attribute('id'))),
        ],
    );
    $r->delete(
        '/news/:id',
        Router::to(NewsController::class, 'destroy'),
        [
            RequireAdmin::class,
            RequireRole::write(Capability::NEWS),
            new AuditLog('News', state: fn (Request $r) => (new NewsRepository())->find((string) $r->attribute('id'))),
        ],
    );

    // RTPP-27 — review moderation. §7.3's "Review moderation" row is a
    // single WRITE cell Editor and Super Admin share — correct for approve
    // and reject, which is all that row describes. §9.10's route table is
    // more specific than that row for one route: hard delete is Super Admin
    // only, gated by the extra `RequireSuperAdmin` stage stacked after the
    // capability check (see its class doc for why this is a second gate,
    // not a change to the capability matrix). `/bulk-approve`/`/bulk-reject`
    // are distinct literal segments from `/:id/approve`/`/:id/reject` — no
    // registration-order requirement, since the segment counts differ.
    $r->get(
        '/reviews',
        Router::to(ReviewController::class, 'index'),
        [RequireAdmin::class, RequireRole::read(Capability::REVIEWS)],
    );
    $r->patch(
        '/reviews/bulk-approve',
        Router::to(ReviewController::class, 'bulkApprove'),
        [RequireAdmin::class, RequireRole::write(Capability::REVIEWS), new AuditLog('Review', action: 'bulk_approve')],
    );
    $r->patch(
        '/reviews/bulk-reject',
        Router::to(ReviewController::class, 'bulkReject'),
        [RequireAdmin::class, RequireRole::write(Capability::REVIEWS), new AuditLog('Review', action: 'bulk_reject')],
    );
    $r->patch(
        '/reviews/:id/approve',
        Router::to(ReviewController::class, 'approve'),
        [
            RequireAdmin::class,
            RequireRole::write(Capability::REVIEWS),
            new AuditLog('Review', state: fn (Request $r) => (new ReviewRepository())->find((string) $r->attribute('id')), action: 'approve'),
        ],
    );
    $r->patch(
        '/reviews/:id/reject',
        Router::to(ReviewController::class, 'reject'),
        [
            RequireAdmin::class,
            RequireRole::write(Capability::REVIEWS),
            new AuditLog('Review', state: fn (Request $r) => (new ReviewRepository())->find((string) $r->attribute('id')), action: 'reject'),
        ],
    );
    $r->delete(
        '/reviews/:id',
        Router::to(ReviewController::class, 'destroy'),
        [
            RequireAdmin::class,
            RequireRole::write(Capability::REVIEWS),
            RequireSuperAdmin::class,
            new AuditLog('Review', state: fn (Request $r) => (new ReviewRepository())->find((string) $r->attribute('id'))),
        ],
    );

    // RTPP-29 — enquiries. Editor holds READ on ENQUIRIES (sees the leads),
    // Sales and Super Admin hold WRITE (work them) — §7.3's "Editors see the
    // leads but do not work them" distinction. `/export` is registered
    // before `/:id` for the same reason `/reorder` always is elsewhere: both
    // are GET with the same segment count, and the router matches in
    // registration order.
    $r->get(
        '/enquiries',
        Router::to(EnquiryController::class, 'index'),
        [RequireAdmin::class, RequireRole::read(Capability::ENQUIRIES)],
    );
    $r->get(
        '/enquiries/export',
        Router::to(EnquiryController::class, 'export'),
        [RequireAdmin::class, RequireRole::read(Capability::ENQUIRIES)],
    );
    $r->get(
        '/enquiries/:id',
        Router::to(EnquiryController::class, 'show'),
        [RequireAdmin::class, RequireRole::read(Capability::ENQUIRIES)],
    );
    $r->patch(
        '/enquiries/:id',
        Router::to(EnquiryController::class, 'update'),
        [
            RequireAdmin::class,
            RequireRole::write(Capability::ENQUIRIES),
            new AuditLog('Enquiry', state: fn (Request $r) => (new EnquiryRepository())->find((string) $r->attribute('id'))),
        ],
    );

    // RTPP-30 — dealer applications. Same READ/WRITE split as enquiries
    // (§7.3): Editor sees the leads, Sales and Super Admin work them.
    // `/export` before `/:id` for the same registration-order reason.
    $r->get(
        '/applications',
        Router::to(DealerApplicationController::class, 'index'),
        [RequireAdmin::class, RequireRole::read(Capability::DEALER_APPLICATIONS)],
    );
    $r->get(
        '/applications/export',
        Router::to(DealerApplicationController::class, 'export'),
        [RequireAdmin::class, RequireRole::read(Capability::DEALER_APPLICATIONS)],
    );
    $r->get(
        '/applications/:id',
        Router::to(DealerApplicationController::class, 'show'),
        [RequireAdmin::class, RequireRole::read(Capability::DEALER_APPLICATIONS)],
    );
    $r->patch(
        '/applications/:id',
        Router::to(DealerApplicationController::class, 'update'),
        [
            RequireAdmin::class,
            RequireRole::write(Capability::DEALER_APPLICATIONS),
            new AuditLog('DealerApplication', state: fn (Request $r) => (new DealerApplicationRepository())->find((string) $r->attribute('id'))),
        ],
    );

    // RTPP-31 — contact inbox and the newsletter list. Same READ/WRITE split
    // as enquiries and applications (§7.3): Editor sees the inbox, Sales and
    // Super Admin work it. Newsletter has no READ row for Editor at all in
    // §7.3 — deny by default, not an oversight.
    $r->get(
        '/messages',
        Router::to(ContactMessageController::class, 'index'),
        [RequireAdmin::class, RequireRole::read(Capability::CONTACT_MESSAGES)],
    );
    $r->patch(
        '/messages/:id',
        Router::to(ContactMessageController::class, 'update'),
        [
            RequireAdmin::class,
            RequireRole::write(Capability::CONTACT_MESSAGES),
            new AuditLog('ContactMessage', state: fn (Request $r) => (new ContactMessageRepository())->find((string) $r->attribute('id'))),
        ],
    );
    $r->get(
        '/subscribers',
        Router::to(SubscriberController::class, 'index'),
        [RequireAdmin::class, RequireRole::read(Capability::NEWSLETTER)],
    );
    $r->get(
        '/subscribers/export',
        Router::to(SubscriberController::class, 'export'),
        [RequireAdmin::class, RequireRole::read(Capability::NEWSLETTER)],
    );
    // Super Admin only (doc §9.10), even though Sales also holds WRITE on
    // NEWSLETTER — the same second-gate pattern RTPP-27 used for review
    // deletion: RequireRole admits "may work this resource", RequireSuperAdmin
    // narrows one specific route further than the capability matrix does.
    $r->delete(
        '/subscribers/:id',
        Router::to(SubscriberController::class, 'destroy'),
        [
            RequireAdmin::class,
            RequireRole::write(Capability::NEWSLETTER),
            RequireSuperAdmin::class,
            new AuditLog('Subscriber', state: fn (Request $r) => (new NewsletterSubscriberRepository())->find((string) $r->attribute('id'))),
        ],
    );

    // RTPP-32 — settings, menu links, social links, downloads. Site profile
    // itself (theme, contact, map) already shipped from RTPP-14; this ticket
    // is everything else §9.8/§9.9 lists alongside it.

    // Settings: Super Admin only (§7.3) — Editor and Sales hold no row for
    // SETTINGS at all in the matrix, so `read` already restricts to
    // Super Admin without a second gate.
    $r->get(
        '/settings',
        Router::to(SettingsController::class, 'index'),
        [RequireAdmin::class, RequireRole::read(Capability::SETTINGS)],
    );
    $r->put(
        '/settings',
        Router::to(SettingsController::class, 'update'),
        [
            RequireAdmin::class,
            RequireRole::write(Capability::SETTINGS),
            new AuditLog(
                'Settings',
                state: fn (Request $r) => array_column((new SettingsRepository())->listAll(), 'value', 'key'),
                singleton: true,
            ),
        ],
    );

    // Menu links and social links: CONTENT, Editor+ (§7.3's "Banners and
    // page content" row) — the same capability banners and page blocks use.
    // `/reorder` before `/:id` for the usual registration-order reason.
    $r->get(
        '/menu-links',
        Router::to(MenuLinkController::class, 'index'),
        [RequireAdmin::class, RequireRole::read(Capability::CONTENT)],
    );
    $r->post(
        '/menu-links',
        Router::to(MenuLinkController::class, 'store'),
        [RequireAdmin::class, RequireRole::write(Capability::CONTENT), new AuditLog('MenuLink')],
    );
    $r->patch(
        '/menu-links/reorder',
        Router::to(MenuLinkController::class, 'reorder'),
        [RequireAdmin::class, RequireRole::write(Capability::CONTENT), new AuditLog('MenuLink', action: 'reorder')],
    );
    $r->get(
        '/menu-links/:id',
        Router::to(MenuLinkController::class, 'show'),
        [RequireAdmin::class, RequireRole::read(Capability::CONTENT)],
    );
    $r->patch(
        '/menu-links/:id',
        Router::to(MenuLinkController::class, 'update'),
        [
            RequireAdmin::class,
            RequireRole::write(Capability::CONTENT),
            new AuditLog('MenuLink', state: fn (Request $r) => (new MenuLinkRepository())->find((string) $r->attribute('id'))),
        ],
    );
    $r->delete(
        '/menu-links/:id',
        Router::to(MenuLinkController::class, 'destroy'),
        [
            RequireAdmin::class,
            RequireRole::write(Capability::CONTENT),
            new AuditLog('MenuLink', state: fn (Request $r) => (new MenuLinkRepository())->find((string) $r->attribute('id'))),
        ],
    );

    $r->get(
        '/social-links',
        Router::to(SocialLinkController::class, 'index'),
        [RequireAdmin::class, RequireRole::read(Capability::CONTENT)],
    );
    $r->post(
        '/social-links',
        Router::to(SocialLinkController::class, 'store'),
        [RequireAdmin::class, RequireRole::write(Capability::CONTENT), new AuditLog('SocialLink')],
    );
    $r->patch(
        '/social-links/reorder',
        Router::to(SocialLinkController::class, 'reorder'),
        [RequireAdmin::class, RequireRole::write(Capability::CONTENT), new AuditLog('SocialLink', action: 'reorder')],
    );
    $r->get(
        '/social-links/:id',
        Router::to(SocialLinkController::class, 'show'),
        [RequireAdmin::class, RequireRole::read(Capability::CONTENT)],
    );
    $r->patch(
        '/social-links/:id',
        Router::to(SocialLinkController::class, 'update'),
        [
            RequireAdmin::class,
            RequireRole::write(Capability::CONTENT),
            new AuditLog('SocialLink', state: fn (Request $r) => (new SocialLinkRepository())->find((string) $r->attribute('id'))),
        ],
    );
    $r->delete(
        '/social-links/:id',
        Router::to(SocialLinkController::class, 'destroy'),
        [
            RequireAdmin::class,
            RequireRole::write(Capability::CONTENT),
            new AuditLog('SocialLink', state: fn (Request $r) => (new SocialLinkRepository())->find((string) $r->attribute('id'))),
        ],
    );

    // Downloads: DOWNLOADS capability — Editor+ write, Sales read (§11's
    // "Downloads | ✔ | ✔ | read" row).
    $r->get(
        '/downloads',
        Router::to(DownloadController::class, 'index'),
        [RequireAdmin::class, RequireRole::read(Capability::DOWNLOADS)],
    );
    $r->post(
        '/downloads',
        Router::to(DownloadController::class, 'store'),
        [RequireAdmin::class, RequireRole::write(Capability::DOWNLOADS), new AuditLog('Download')],
    );
    $r->get(
        '/downloads/:id',
        Router::to(DownloadController::class, 'show'),
        [RequireAdmin::class, RequireRole::read(Capability::DOWNLOADS)],
    );
    $r->patch(
        '/downloads/:id',
        Router::to(DownloadController::class, 'update'),
        [
            RequireAdmin::class,
            RequireRole::write(Capability::DOWNLOADS),
            new AuditLog('Download', state: fn (Request $r) => (new DownloadRepository())->find((string) $r->attribute('id'))),
        ],
    );
    $r->delete(
        '/downloads/:id',
        Router::to(DownloadController::class, 'destroy'),
        [
            RequireAdmin::class,
            RequireRole::write(Capability::DOWNLOADS),
            new AuditLog('Download', state: fn (Request $r) => (new DownloadRepository())->find((string) $r->attribute('id'))),
        ],
    );

    // RTPP-35 — the audit log query API. Super Admin only (§7.3): the row is
    // READ, not WRITE, because the log is written by `AuditLog` middleware and
    // by nobody else — see `RolePolicy`'s class doc for that reasoning.
    $r->get(
        '/audit-logs',
        Router::to(AuditController::class, 'index'),
        [RequireAdmin::class, RequireRole::read(Capability::AUDIT_LOG)],
    );

    // RTPP-36 — the dashboard summary. Every role holds DASHBOARD READ
    // (§7.3); Sales's "leads only" scoping happens inside DashboardService,
    // not here, same pattern as Sales's dashboard-overview row elsewhere.
    $r->get(
        '/dashboard/summary',
        Router::to(DashboardController::class, 'summary'),
        [RequireAdmin::class, RequireRole::read(Capability::DASHBOARD)],
    );

    // RTPP-36 — cache purge and sitemap regeneration, "Editor+" per the
    // route table (doc §9) — a new CACHE capability, WRITE for Editor and
    // Super Admin, none for Sales, added to §7.3 alongside this ticket
    // since the matrix had no row for it yet. Not audit-logged: this
    // touches no tracked entity's data, only a cache key and a static file.
    $r->post(
        '/cache/purge',
        Router::to(CacheController::class, 'purge'),
        [RequireAdmin::class, RequireRole::write(Capability::CACHE)],
    );
});

