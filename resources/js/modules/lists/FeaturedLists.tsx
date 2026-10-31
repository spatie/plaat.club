import { Link } from '@inertiajs/react';
import { ArrowUpRight } from 'lucide-react';

import { RecordListPreview } from '@/modules/lists/RecordListPreview';
import type { ListPreview } from '@/modules/lists/types';

export function FeaturedLists({
    heading,
    lists,
}: {
    heading: string;
    lists: ListPreview[];
}) {
    if (lists.length === 0) return null;

    return (
        <section
            className="featured-lists"
            aria-labelledby="featured-lists-title"
        >
            <div className="featured-lists-heading">
                <div>
                    <p className="eyebrow">Hand-picked listening</p>
                    <h2 id="featured-lists-title">{heading}</h2>
                    <p>
                        Personal selections from BBC Radio 4’s Desert Island
                        Discs.
                    </p>
                </div>
                <Link
                    className="featured-lists-more"
                    href="/collections?tab=lists"
                >
                    Explore all lists{' '}
                    <ArrowUpRight size={20} aria-hidden="true" />
                </Link>
            </div>
            <div className="collection-previews list-cards">
                {lists.map((list) => (
                    <RecordListPreview key={list.id} list={list} showOwner />
                ))}
            </div>
        </section>
    );
}
