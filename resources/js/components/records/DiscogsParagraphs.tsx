import { discogsText } from '@/components/records/discogsText';

export function DiscogsParagraphs({
    text,
    links,
}: {
    text: string;
    links?: Record<string, string>;
}) {
    return (
        <div className="discogs-paragraphs">
            {text
                .trim()
                .split(/\r?\n[\t ]*\r?\n(?:[\t ]*\r?\n)*/)
                .map((paragraph, index) => (
                    <p className="discogs-text" key={index}>
                        {discogsText(paragraph, links)}
                    </p>
                ))}
        </div>
    );
}
