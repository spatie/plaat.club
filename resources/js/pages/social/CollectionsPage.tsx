import { Head, Link, usePage } from '@inertiajs/react';

import { FeaturedLists } from '@/modules/lists/FeaturedLists';
import { RecordListPreview } from '@/modules/lists/RecordListPreview';
import { CollectionPreview } from '@/modules/social/CollectionPreview';
import { Pagination } from '@/modules/social/Pagination';
import type { CollectionsProps, SocialProps } from '@/modules/social/types';

export function CollectionsPage(props: CollectionsProps) {
    const user = usePage<SocialProps>().props.auth.user;
    return (
        <>
            <Head title="Explore" />
            <div className="page-heading">
                <h1>Explore.</h1>
                <p>Collections and lists shared with everyone.</p>
            </div>
            <FeaturedLists
                heading="Featured lists."
                lists={props.featuredLists}
            />
            <nav className="collection-tabs explore-tabs" aria-label="Explore">
                <Link
                    href="/collections"
                    viewTransition
                    preserveScroll
                    aria-current={
                        props.tab === 'collections' ? 'page' : undefined
                    }
                >
                    Collections
                    {props.tab === 'collections' && (
                        <span
                            className="explore-tab-indicator"
                            aria-hidden="true"
                        />
                    )}
                </Link>
                <Link
                    href="/collections?tab=lists"
                    viewTransition
                    preserveScroll
                    aria-current={props.tab === 'lists' ? 'page' : undefined}
                >
                    Lists
                    {props.tab === 'lists' && (
                        <span
                            className="explore-tab-indicator"
                            aria-hidden="true"
                        />
                    )}
                </Link>
            </nav>
            {props.tab === 'lists' ? (
                <>
                    {props.lists.data.length ? (
                        <div className="collection-previews list-cards">
                            {props.lists.data.map((list) => (
                                <RecordListPreview
                                    key={list.id}
                                    list={list}
                                    showOwner
                                />
                            ))}
                        </div>
                    ) : (
                        <section className="empty panel">
                            <h2>No public lists yet</h2>
                            <p>
                                Lists appear here when their owners choose
                                Everyone. Their collections can stay private.
                            </p>
                        </section>
                    )}
                    <Pagination {...props.lists} />
                </>
            ) : (
                <>
                    <div className="collection-previews">
                        {props.collectors.data.length ? (
                            props.collectors.data.map((collector) => (
                                <CollectionPreview
                                    key={collector.id}
                                    collector={collector}
                                />
                            ))
                        ) : (
                            <section className="empty panel">
                                <h2>No public collections yet</h2>
                                <p>
                                    Collections are shared with friends by
                                    default. They’ll appear here when their
                                    owners choose Everyone.
                                </p>
                                <Link href={user ? '/settings' : '/register'}>
                                    {user
                                        ? 'Manage my collection’s visibility'
                                        : 'Create account'}
                                </Link>
                            </section>
                        )}
                    </div>
                    <Pagination {...props.collectors} />
                </>
            )}
        </>
    );
}
