<?php

declare(strict_types=1);

namespace Rajdhani\Auth;

/**
 * The rows of the §7.3 permission matrix.
 *
 * One case per row of the table in the requirements document, named after the
 * thing being protected rather than after a route — routes come and go, and
 * several routes share a capability. Adding a case here is a change to the
 * document's matrix and belongs there first.
 */
enum Capability: string
{
    case DASHBOARD           = 'dashboard';
    case PRODUCTS            = 'products';
    case CONTENT             = 'content';
    case GALLERY             = 'gallery';
    case NEWS                = 'news';
    case MARKETING           = 'marketing';
    case REVIEWS             = 'reviews';
    case DOWNLOADS           = 'downloads';
    case ENQUIRIES           = 'enquiries';
    case DEALER_APPLICATIONS = 'dealer_applications';
    case CONTACT_MESSAGES    = 'contact_messages';
    case NEWSLETTER          = 'newsletter';
    case SETTINGS            = 'settings';
    case ADMIN_USERS         = 'admin_users';
    case AUDIT_LOG           = 'audit_log';
    case MEDIA               = 'media';

    /** The matrix row label, for the permissions payload and for error messages. */
    public function label(): string
    {
        return match ($this) {
            self::DASHBOARD           => 'Dashboard overview',
            self::PRODUCTS            => 'Products and categories',
            self::CONTENT             => 'Banners and page content',
            self::GALLERY             => 'Gallery',
            self::NEWS                => 'News',
            self::MARKETING           => 'Testimonials, certifications, stats, process steps',
            self::REVIEWS             => 'Review moderation',
            self::DOWNLOADS           => 'Downloads',
            self::ENQUIRIES           => 'Product enquiries',
            self::DEALER_APPLICATIONS => 'Dealer applications',
            self::CONTACT_MESSAGES    => 'Contact messages',
            self::NEWSLETTER          => 'Newsletter subscribers and export',
            self::SETTINGS            => 'Site settings and theme',
            self::ADMIN_USERS         => 'Admin user management',
            self::AUDIT_LOG           => 'Audit log',
            self::MEDIA               => 'Media library',
        };
    }
}
