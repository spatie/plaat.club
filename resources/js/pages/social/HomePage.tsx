import { Head } from '@inertiajs/react';

import { FeaturedLists } from '@/modules/lists/FeaturedLists';
import { HeroArtwork } from '@/modules/social/HeroArtwork';
import type { HomeProps } from '@/modules/social/types';

export function HomePage({ artwork, featuredLists }: HomeProps) {
    return (
        <>
            <Head title="Good taste is contagious." />
            <section className="landing-hero" aria-labelledby="landing-title">
                <h1 id="landing-title">Good taste</h1>
                <p className="landing-bottom-type">is contagious.</p>
                {artwork.length > 0 ? (
                    <div
                        className="landing-records"
                        aria-label="Records from public collections"
                    >
                        <HeroArtwork records={artwork} moving />
                    </div>
                ) : (
                    <p className="landing-empty">
                        Every great collection starts somewhere.
                    </p>
                )}
                <p className="landing-welcome">
                    <span>
                        Vinyl, CDs
                        <br />
                        and cassettes
                        <br />
                        welcome.
                    </span>
                </p>
            </section>
            <FeaturedLists heading="Go record digging." lists={featuredLists} />
            <footer className="landing-attribution">
                Record data provided by{' '}
                <a href="https://www.discogs.com">Discogs</a>.
            </footer>
        </>
    );
}
