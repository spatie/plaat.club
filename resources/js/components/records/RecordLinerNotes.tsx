import { ChevronDown } from 'lucide-react';

import { DiscogsParagraphs } from '@/components/records/DiscogsParagraphs';
import { RecordCreditList } from '@/components/records/RecordCreditList';
import {
    discogsEntityUrl,
    safeExternalUrl,
} from '@/components/records/discogsText';
import type { CollectionRecord } from '@/components/records/types';

import '../../../css/record-liner-notes.css';

export function RecordLinerNotes({ record }: { record: CollectionRecord }) {
    const bios = record.artistBios?.filter((bio) => bio.profile.trim()) ?? [];
    const notes = record.releaseNotes?.trim();
    const releaseCredits = record.credits?.release ?? [];
    const trackCredits =
        record.credits?.tracks.filter((track) => track.artists.length) ?? [];

    if (
        !bios.length &&
        !notes &&
        !releaseCredits.length &&
        !trackCredits.length
    ) {
        return null;
    }

    return (
        <div className="record-liner-notes">
            {notes && (
                <details open>
                    <summary>
                        Release notes{' '}
                        <ChevronDown size={18} aria-hidden="true" />
                    </summary>
                    <div className="record-disclosure-content">
                        <DiscogsParagraphs
                            text={notes}
                            links={record.discogsTextLinks}
                        />
                    </div>
                </details>
            )}
            {bios.length > 0 && (
                <details open>
                    <summary>
                        About the artist{' '}
                        <ChevronDown size={18} aria-hidden="true" />
                    </summary>
                    <div className="record-disclosure-content">
                        {bios.map((bio) => (
                            <section key={bio.id}>
                                <h3>
                                    <a
                                        href={
                                            safeExternalUrl(bio.discogsUrl) ??
                                            discogsEntityUrl(
                                                'a',
                                                String(bio.id),
                                            )
                                        }
                                        target="_blank"
                                        rel="noopener noreferrer"
                                    >
                                        {bio.name}
                                    </a>
                                </h3>
                                <DiscogsParagraphs
                                    text={bio.profile}
                                    links={record.discogsTextLinks}
                                />
                            </section>
                        ))}
                    </div>
                </details>
            )}
            {(releaseCredits.length > 0 || trackCredits.length > 0) && (
                <details open>
                    <summary>
                        Credits <ChevronDown size={18} aria-hidden="true" />
                    </summary>
                    <div className="record-disclosure-content">
                        {releaseCredits.length > 0 && (
                            <RecordCreditList credits={releaseCredits} />
                        )}
                        {trackCredits.map((track, index) => (
                            <section key={`${track.position}-${index}`}>
                                <h3>
                                    {[track.position, track.title]
                                        .filter(Boolean)
                                        .join(' · ')}
                                </h3>
                                <RecordCreditList credits={track.artists} />
                            </section>
                        ))}
                    </div>
                </details>
            )}
        </div>
    );
}
