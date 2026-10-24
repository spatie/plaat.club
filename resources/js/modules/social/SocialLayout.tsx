import { Head, Link, usePage } from '@inertiajs/react';
import { useEffect, useLayoutEffect, useRef, type ReactNode } from 'react';

import { legacyCollectionUrl } from '@/lib/record-history';
import { AccountMenu } from '@/modules/social/AccountMenu';
import { isSettingUpCollection } from '@/modules/social/collectionSetup';
import { NotificationsPopover } from '@/modules/social/NotificationsPopover';
import { RecordOverlay } from '@/modules/social/RecordOverlay';
import { SocialPreviewHead } from '@/modules/social/SocialPreviewHead';
import type { SocialProps } from '@/modules/social/types';
import '../../../css/social.css';
import '../../../css/auth.css';
import '../../../css/collection-visibility.css';

export function SocialLayout({ children }: { children: ReactNode }) {
    const { props, url, component } = usePage<SocialProps>();
    const user = props.auth.user;
    const path = url.split(/[?#]/)[0];
    const settingUpCollection = user && isSettingUpCollection(user);
    const onboarding = component === 'social/FeedPage' && settingUpCollection;
    const recordOpen =
        component === 'social/RecordPage' || Boolean(props.recordOverlay);
    const layoutRef = useRef<HTMLDivElement>(null);

    useLayoutEffect(() => {
        const layout = layoutRef.current;
        if (!layout) return;
        let compact = false;
        const update = () => {
            const next =
                Boolean(layout.querySelector('.profile-record-room')) &&
                window.scrollY > (compact ? 12 : 72);
            if (next === compact) return;
            compact = next;
            layout.dataset.collectionCompact = String(compact);
        };
        const header = layout.querySelector('.site-header');
        const updateHeaderHeight = () => {
            layout.style.setProperty(
                '--site-header-height',
                `${header?.getBoundingClientRect().height ?? 0}px`,
            );
        };
        updateHeaderHeight();
        const observer = new ResizeObserver(updateHeaderHeight);
        if (header) observer.observe(header, { box: 'border-box' });
        update();
        window.addEventListener('scroll', update, { passive: true });
        return () => {
            observer.disconnect();
            window.removeEventListener('scroll', update);
            delete layout.dataset.collectionCompact;
        };
    }, [path]);

    const pageClass =
        {
            'social/HomePage': 'home-page',
            'social/AuthPage': 'auth-page',
            'social/ProfilePage': 'collection-page',
            'social/ListsPage': 'lists-page',
            'social/ProfileListsPage': 'lists-page',
            'social/ListPage': 'lists-page',
            'social/ListEditPage': 'lists-page',
            'social/CollectionsPage': 'explore-page',
            'social/FeedPage': 'feed-page',
            'social/FriendsPage': 'friends-page',
            'social/SettingsPage': 'settings-page',
            'social/DeleteAccountPage': 'delete-account-page',
            'social/AboutPage': 'about-page',
            'social/PrivacyPage': 'legal-page',
            'social/TermsPage': 'legal-page',
        }[component] ?? '';

    useEffect(() => {
        if (path !== '/' && path !== '/friends') return;
        const destination = legacyCollectionUrl(window.location.hash);
        if (destination) window.location.replace(destination);
    }, [path]);

    return (
        <div ref={layoutRef} className={`social-app ${pageClass}`}>
            <SocialPreviewHead preview={props.socialPreview} />
            <Head>
                <meta name="robots" content="noindex, nofollow" />
            </Head>
            {props.impersonating && (
                <aside
                    className="impersonation-banner"
                    aria-label="Impersonation"
                >
                    <span>
                        Viewing as{' '}
                        <strong>{user?.name ?? 'a signed-out user'}</strong>.
                        Changes affect this account.
                    </span>
                    <form method="post" action="/admin/impersonation/stop">
                        <input
                            type="hidden"
                            name="_token"
                            value={props.csrfToken}
                        />
                        <button type="submit">Return to admin</button>
                    </form>
                </aside>
            )}
            <a className="skip" href="#main" inert={recordOpen}>
                Skip to content
            </a>
            <header className="site-header" inert={recordOpen}>
                <Link className="brand" href="/">
                    <span className="brand-mark" aria-hidden="true" /> Plaat
                    Club<span className="brand-period">.</span>
                </Link>
                {user ? (
                    <>
                        {user.verified && (
                            <nav
                                className="main-navigation"
                                aria-label="Main navigation"
                            >
                                <Link
                                    href={
                                        settingUpCollection
                                            ? '/friends'
                                            : user.profileUrl
                                    }
                                    aria-current={
                                        path === user.profileUrl ||
                                        path === '/lists' ||
                                        path.startsWith('/lists/') ||
                                        onboarding
                                            ? 'page'
                                            : undefined
                                    }
                                >
                                    My collection
                                </Link>
                                <Link
                                    href={
                                        settingUpCollection
                                            ? '/friends/manage'
                                            : '/friends'
                                    }
                                    aria-current={
                                        (path === '/friends' && !onboarding) ||
                                        path.startsWith('/friends/')
                                            ? 'page'
                                            : undefined
                                    }
                                >
                                    Friends’ finds
                                </Link>
                                <Link
                                    href="/collections"
                                    aria-current={
                                        path === '/collections'
                                            ? 'page'
                                            : undefined
                                    }
                                >
                                    Explore
                                </Link>
                                {props.aiSearchEnabled && (
                                    <Link
                                        href="/ask"
                                        aria-current={
                                            path === '/ask' ? 'page' : undefined
                                        }
                                    >
                                        Ask your collection
                                    </Link>
                                )}
                            </nav>
                        )}
                        <div className="header-account">
                            {user.verified ? (
                                <NotificationsPopover
                                    key={`${user.id}:${props.notificationCount}`}
                                />
                            ) : (
                                <Link
                                    href="/verify-email"
                                    aria-current={
                                        path === '/verify-email'
                                            ? 'page'
                                            : undefined
                                    }
                                >
                                    Verify email
                                </Link>
                            )}
                            <AccountMenu
                                key={user.id}
                                user={user}
                                path={path}
                            />
                        </div>
                    </>
                ) : (
                    <nav
                        className="guest-navigation"
                        aria-label="Main navigation"
                    >
                        <Link
                            className="guest-explore"
                            href="/collections"
                            aria-current={
                                path === '/collections' ? 'page' : undefined
                            }
                        >
                            Explore
                        </Link>
                        <Link
                            href="/about"
                            aria-current={
                                path === '/about' ? 'page' : undefined
                            }
                        >
                            About
                        </Link>
                        <Link
                            href="/login"
                            aria-current={
                                path === '/login' ? 'page' : undefined
                            }
                        >
                            Sign in
                        </Link>
                        {!component.endsWith('AuthPage') && (
                            <Link className="button small" href="/register">
                                Create account
                            </Link>
                        )}
                    </nav>
                )}
            </header>
            <RecordOverlay>
                <main id="main" className="page">
                    {props.flash.status &&
                        !(
                            props.flash.status ===
                                'Collection sync requested.' &&
                            user?.discogsConnected &&
                            !user.importedAt
                        ) && (
                            <div className="flash-notice" role="status">
                                <span
                                    className="flash-notice-stamp"
                                    aria-hidden="true"
                                >
                                    <span className="brand-mark" />
                                </span>
                                <div>
                                    <p
                                        className="flash-notice-eyebrow"
                                        aria-hidden="true"
                                    >
                                        On the record
                                    </p>
                                    <p className="flash-notice-message">
                                        {props.flash.status}
                                    </p>
                                </div>
                            </div>
                        )}
                    {Object.keys(props.errors).length > 0 && (
                        <div className="errors" role="alert">
                            <strong>Please check the following:</strong>
                            <ul>
                                {Object.entries(props.errors).map(
                                    ([field, message]) => (
                                        <li key={field}>
                                            <a href={`#${field}`}>{message}</a>
                                        </li>
                                    ),
                                )}
                            </ul>
                        </div>
                    )}
                    {children}
                </main>
            </RecordOverlay>
        </div>
    );
}
