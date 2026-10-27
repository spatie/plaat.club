import { createElement, type ReactNode } from 'react';

export function safeExternalUrl(value?: string | null): string | undefined {
    if (!value) return undefined;

    try {
        const url = new URL(value);
        if (url.protocol === 'https:' || url.protocol === 'http:') {
            return url.href;
        }
    } catch {
        return undefined;
    }

    return undefined;
}

export function discogsEntityUrl(type: 'a' | 'r' | 'l', value: string) {
    const entity = { a: 'artist', r: 'release', l: 'label' }[type];

    return /^\d+$/.test(value)
        ? `https://www.discogs.com/${entity}/${value}`
        : `https://www.discogs.com/search/?type=${entity}&q=${encodeURIComponent(value)}`;
}

export function discogsText(
    text: string,
    links: Record<string, string> = {},
): ReactNode[] {
    const root: ReactNode[] = [];
    const stack: { tag: string; opening: string; children: ReactNode[] }[] = [];
    const children = () => stack.at(-1)?.children ?? root;
    const tokens = /\[(\/?[bi]|[arl](?:\d+|=[^\]\r\n]+))\]/gi;
    let offset = 0;

    for (const match of text.matchAll(tokens)) {
        children().push(text.slice(offset, match.index));
        offset = match.index + match[0].length;
        const token = match[1];
        const tag = token.toLowerCase();

        if (tag === 'b' || tag === 'i') {
            stack.push({ tag, opening: match[0], children: [] });
            continue;
        }

        if (tag.startsWith('/')) {
            if (stack.at(-1)?.tag !== tag.slice(1)) {
                children().push(match[0]);
                continue;
            }

            const frame = stack.pop()!;
            children().push(
                createElement(
                    frame.tag === 'b' ? 'strong' : 'em',
                    { key: match.index },
                    ...frame.children,
                ),
            );
            continue;
        }

        const type = tag[0] as 'a' | 'r' | 'l';
        const value = token.slice(token[1] === '=' ? 2 : 1);
        const numeric = /^\d+$/.test(value);
        const label =
            numeric && links[`${type}${Number(value)}`]
                ? links[`${type}${Number(value)}`]
                : token[1] === '='
                  ? value
                  : `${{ a: 'Artist', r: 'Release', l: 'Label' }[type]} ${value}`;
        children().push(
            createElement(
                'a',
                {
                    key: match.index,
                    href: discogsEntityUrl(type, value),
                    target: '_blank',
                    rel: 'noopener noreferrer',
                },
                label,
            ),
        );
    }

    children().push(text.slice(offset));

    while (stack.length) {
        const frame = stack.pop()!;
        children().push(frame.opening, ...frame.children);
    }

    return root;
}
