export function RecordOwnership({
    owned,
    otherPressingOwned,
}: {
    owned?: boolean;
    otherPressingOwned?: boolean;
}) {
    if (owned === undefined) return null;

    return (
        <small className={`record-ownership ${owned ? 'is-owned' : ''}`}>
            {owned
                ? 'In your collection'
                : otherPressingOwned
                  ? 'You own another pressing'
                  : 'Not in your collection'}
        </small>
    );
}
