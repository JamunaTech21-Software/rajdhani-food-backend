<?php

declare(strict_types=1);

/**
 * /public/* — sections 9.3 to 9.7. No authentication required; a customer token
 * is optional and only unlocks wishlist, reviews and the account area.
 *
 * There is no brand header and no brand resolution: the API serves one site
 * (doc §6). Populated from RTPP-14 onward.
 */

use Rajdhani\Controllers\Public\BannerController;
use Rajdhani\Controllers\Public\CategoryController;
use Rajdhani\Controllers\Public\CertificationController;
use Rajdhani\Controllers\Public\ContactController;
use Rajdhani\Controllers\Public\DealerApplicationController;
use Rajdhani\Controllers\Public\DownloadController;
use Rajdhani\Controllers\Public\EnquiryController;
use Rajdhani\Controllers\Public\FeatureItemController;
use Rajdhani\Controllers\Public\GalleryController;
use Rajdhani\Controllers\Public\HomeController;
use Rajdhani\Controllers\Public\LayoutController;
use Rajdhani\Controllers\Public\LocationController;
use Rajdhani\Controllers\Public\NewsController;
use Rajdhani\Controllers\Public\NewsletterController;
use Rajdhani\Controllers\Public\PageBlockController;
use Rajdhani\Controllers\Public\ProcessStepController;
use Rajdhani\Controllers\Public\ProductController;
use Rajdhani\Controllers\Public\ReviewController;
use Rajdhani\Controllers\Public\StatCounterController;
use Rajdhani\Controllers\Public\WishlistController;
use Rajdhani\Http\Router;
use Rajdhani\Middleware\OptionalCustomer;
use Rajdhani\Middleware\RequireCustomer;
use Rajdhani\Middleware\ThrottleForm;
use Rajdhani\Middleware\VerifyHuman;

/** @var Router $router */

$router->group('/public', [], static function (Router $r): void {
    // RTPP-14 — the site profile the front-end boots from. Deliberately
    // unauthenticated and header-free: it is the first call a cold page load
    // makes, before anyone has signed in.
    $r->get('/layout', Router::to(LayoutController::class, 'show'));

    // RTPP-36 — the composed home payload, cached (§14.1). Its own ingredient
    // list is banners + featured products + stats + news + testimonials —
    // see HomeService's class doc for why that is narrower than §10.1's full
    // page description.
    $r->get('/home', Router::to(HomeController::class, 'show'));

    // RTPP-18 — feeds the sticky filter bar (§10.2) and the header Products
    // dropdown (§10.5).
    $r->get('/categories', Router::to(CategoryController::class, 'index'));

    // RTPP-20 — the public catalogue: listing with URL-syncable filters,
    // detail by slug, related-by-category. `/:slug/related` is registered
    // before nothing else competes for it, but note it must never be shadowed
    // by a later, more general `/:slug` pattern — there isn't one here since
    // both are GET on distinct literal suffixes under the same dynamic segment.
    $r->get('/products', Router::to(ProductController::class, 'index'));
    $r->get('/products/:slug', Router::to(ProductController::class, 'show'));
    $r->get('/products/:slug/related', Router::to(ProductController::class, 'related'));

    // RTPP-27 — approved reviews for one product (open) and submission
    // (customer token required — doc §9.4). `POST` here always creates a
    // `PENDING` review; editing an existing one is `PATCH /my/reviews/:id`
    // below, never a second `POST` to this route. RTPP-34 adds this form's
    // own rate limit and human-verification pair — the doc's five-form
    // list (§14.2) names "review" alongside the four anonymous ones, even
    // though this one is otherwise gated by `RequireCustomer` rather than
    // being anonymous.
    $r->get('/products/:slug/reviews', Router::to(ReviewController::class, 'index'));
    $r->post(
        '/products/:slug/reviews',
        Router::to(ReviewController::class, 'store'),
        [new ThrottleForm('review'), new VerifyHuman('review'), RequireCustomer::class],
    );

    // RTPP-23 — active, in-window banners for one placement, driving a hero
    // slider or a promo slot. `placement` is required; see BannerService.
    $r->get('/banners', Router::to(BannerController::class, 'index'));

    // RTPP-25 — the gallery tab bar and its paginated, optionally
    // category-filtered image grid (doc §9.5). `/categories` is registered
    // before the bare `/gallery` route it sits under only for readability —
    // the two never compete, since one has an extra literal segment.
    $r->get('/gallery/categories', Router::to(GalleryController::class, 'categories'));
    $r->get('/gallery', Router::to(GalleryController::class, 'index'));

    // RTPP-26 — news. `/news/featured` is registered before `/news/:slug`:
    // both are GET with the same segment count, and the router matches in
    // registration order, so the dynamic `:slug` route would otherwise
    // capture the literal segment "featured" as a slug.
    $r->get('/news', Router::to(NewsController::class, 'index'));
    $r->get('/news/featured', Router::to(NewsController::class, 'featured'));
    $r->get('/news/:slug', Router::to(NewsController::class, 'show'));

    // RTPP-27 — a customer's own review history (doc §9.7). All three
    // require a customer token; editing or deleting someone else's review
    // 404s rather than 403s — see `ReviewRepository::findOwn()`'s class doc.
    $r->get('/my/reviews', Router::to(ReviewController::class, 'myIndex'), [RequireCustomer::class]);
    $r->patch('/my/reviews/:id', Router::to(ReviewController::class, 'myUpdate'), [RequireCustomer::class]);
    $r->delete('/my/reviews/:id', Router::to(ReviewController::class, 'myDestroy'), [RequireCustomer::class]);

    // RTPP-28 — server-persisted wishlist, every route customer-token gated
    // (doc §9.7). `/merge` is POST like `/wishlist` itself but a distinct
    // literal segment — no registration-order requirement.
    $r->get('/wishlist', Router::to(WishlistController::class, 'index'), [RequireCustomer::class]);
    $r->post('/wishlist', Router::to(WishlistController::class, 'store'), [RequireCustomer::class]);
    $r->post('/wishlist/merge', Router::to(WishlistController::class, 'merge'), [RequireCustomer::class]);
    $r->delete('/wishlist/:productId', Router::to(WishlistController::class, 'destroy'), [RequireCustomer::class]);

    // RTPP-29 — product enquiries. `OptionalCustomer` lets the form pre-fill
    // and attribute the lead to an account without requiring one (doc §7.1);
    // `ThrottleForm('enquiry')` is this form's own 5-per-hour-per-IP budget
    // (doc §14.2), separate from the other three public forms'.
    // `VerifyHuman('enquiry')` (RTPP-34) is reCAPTCHA v3 plus the honeypot.
    $r->post(
        '/enquiries',
        Router::to(EnquiryController::class, 'store'),
        [new ThrottleForm('enquiry'), new VerifyHuman('enquiry'), OptionalCustomer::class],
    );

    // RTPP-30 — the district/upazila lookups behind the dealer form's
    // cascading select (doc §9.6, §10.3), and the application itself. No
    // `OptionalCustomer` here: `dealer_applications` has no `customer_id`
    // column, unlike enquiries. `ThrottleForm('dealer_application')` is this
    // form's own 5-per-hour-per-IP budget, separate from `enquiry`'s.
    $r->get('/locations/districts', Router::to(LocationController::class, 'districts'));
    $r->get('/locations/districts/:id/upazilas', Router::to(LocationController::class, 'upazilas'));
    $r->post(
        '/dealer-applications',
        Router::to(DealerApplicationController::class, 'store'),
        [new ThrottleForm('dealer_application'), new VerifyHuman('dealer_application')],
    );

    // RTPP-31 — contact form and newsletter, the last two of §14.2's four
    // rate-limited public forms, each with its own 5-per-hour-per-IP budget.
    // `unsubscribe` is a GET one-click link, not rate-limited — see
    // `NewsletterController`'s class doc.
    $r->post(
        '/contact',
        Router::to(ContactController::class, 'store'),
        [new ThrottleForm('contact'), new VerifyHuman('contact')],
    );
    $r->post(
        '/newsletter/subscribe',
        Router::to(NewsletterController::class, 'subscribe'),
        [new ThrottleForm('newsletter'), new VerifyHuman('newsletter')],
    );
    $r->get('/newsletter/unsubscribe/:token', Router::to(NewsletterController::class, 'unsubscribe'));

    // RTPP-32 — brochure/catalogue resolve, by key rather than id: the
    // front-end's download button is built against a known stable key.
    $r->get('/downloads/:key', Router::to(DownloadController::class, 'show'));

    // RTPP-67 — the public read paths RTPP-24 deferred for every module
    // besides banners/news/gallery: feature items, process steps, stat
    // counters, certifications and static-page blocks. Each list is scoped
    // by a required `section`/`group` (or, for page blocks, a route
    // segment) — see each service's own doc for why "everything mixed
    // together" has no sensible public reading.
    $r->get('/page-blocks/:pageKey', Router::to(PageBlockController::class, 'index'));
    $r->get('/certifications', Router::to(CertificationController::class, 'index'));
    $r->get('/feature-items', Router::to(FeatureItemController::class, 'index'));
    $r->get('/process-steps', Router::to(ProcessStepController::class, 'index'));
    $r->get('/stats', Router::to(StatCounterController::class, 'index'));
});
