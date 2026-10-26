import { Head, Link, useForm, usePage } from '@inertiajs/react';

import { CollectionSharing } from '@/modules/social/CollectionSharing';
import { DiscogsConnectButton } from '@/modules/social/DiscogsConnectButton';
import { FieldError } from '@/modules/social/FieldError';
import { ImportStatus } from '@/modules/social/ImportStatus';
import { SocialSwitch } from '@/modules/social/SocialSwitch';
import type { SocialProps } from '@/modules/social/types';

export function SettingsPage() {
    const { auth, csrfToken, googleEnabled } = usePage<SocialProps>().props;
    const user = auth.user!;
    const form = useForm({
        name: user.name,
        handle: user.handle,
        comments_enabled: user.commentsEnabled,
        digest_enabled: user.digestEnabled,
    });
    const connection = useForm({});
    return (
        <>
            <Head title="Your preferences" />
            <div className="page-heading">
                <h1>Settings</h1>
            </div>
            <div className="settings-layout">
                <section className="panel settings-sheet">
                    <h2>Profile & privacy</h2>
                    <form
                        className="stack"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.transform((data) => ({
                                ...data,
                                visibility: user.visibility,
                            }));
                            form.patch('/settings');
                        }}
                    >
                        <label htmlFor="name">
                            Display name
                            <input
                                id="name"
                                name="name"
                                autoComplete="name"
                                required
                                maxLength={80}
                                value={form.data.name}
                                onChange={(event) =>
                                    form.setData('name', event.target.value)
                                }
                                aria-invalid={Boolean(form.errors.name)}
                                aria-describedby={
                                    form.errors.name ? 'name-error' : undefined
                                }
                            />
                        </label>
                        <FieldError
                            id="name-error"
                            message={form.errors.name}
                        />
                        <label htmlFor="handle">
                            Username
                            <input
                                id="handle"
                                name="handle"
                                aria-invalid={Boolean(form.errors.handle)}
                                aria-describedby={
                                    form.errors.handle
                                        ? 'handle-error'
                                        : undefined
                                }
                                value={form.data.handle}
                                onChange={(event) =>
                                    form.setData('handle', event.target.value)
                                }
                                pattern="[a-zA-Z0-9_]{3,30}"
                                minLength={3}
                                maxLength={30}
                                required
                            />
                        </label>
                        <FieldError
                            id="handle-error"
                            message={form.errors.handle}
                        />
                        <SocialSwitch
                            checked={form.data.comments_enabled}
                            label="Allow comments on my records"
                            name="comments_enabled"
                            onChange={(checked) =>
                                form.setData('comments_enabled', checked)
                            }
                        />
                        <div className="settings-digest">
                            <SocialSwitch
                                checked={form.data.digest_enabled}
                                label="Once a week, email me what my friends have added"
                                name="digest_enabled"
                                onChange={(checked) =>
                                    form.setData('digest_enabled', checked)
                                }
                            />
                            <Link href="/about/weekly-email">
                                Preview the weekly email ↗
                            </Link>
                        </div>
                        <button className="button" disabled={form.processing}>
                            Save preferences
                        </button>
                    </form>
                    <div className="settings-sharing">
                        <h3>Who can see your collection?</h3>
                        <CollectionSharing user={user} />
                    </div>
                </section>
                <div className="settings-connections">
                    <section className="connection-section" id="discogs">
                        <h2>Discogs</h2>
                        {user.discogsConnected ? (
                            <>
                                <p>
                                    Connected as{' '}
                                    <strong>{user.discogsUsername}</strong>.
                                </p>
                                {!user.importedAt ? (
                                    <ImportStatus user={user} />
                                ) : (
                                    <>
                                        <p>
                                            {user.syncStatus ??
                                                'Ready to import'}
                                            <br />
                                            <small>
                                                Last successful sync:{' '}
                                                {user.lastSyncedAt
                                                    ? new Intl.DateTimeFormat(
                                                          'en-GB',
                                                          {
                                                              dateStyle:
                                                                  'medium',
                                                              timeStyle:
                                                                  'short',
                                                              timeZone:
                                                                  'Europe/Brussels',
                                                          },
                                                      ).format(
                                                          new Date(
                                                              user.lastSyncedAt,
                                                          ),
                                                      )
                                                    : 'Not yet'}
                                            </small>
                                        </p>
                                        <button
                                            className="button"
                                            disabled={connection.processing}
                                            onClick={() =>
                                                connection.post('/discogs/sync')
                                            }
                                        >
                                            Sync collection now
                                        </button>
                                    </>
                                )}
                                <div className="spaced">
                                    <p>
                                        Disconnecting stops syncing but keeps
                                        your records. Reconnect the same Discogs
                                        account to resume.
                                    </p>
                                    <button
                                        className="button secondary"
                                        disabled={connection.processing}
                                        onClick={() =>
                                            connection.delete('/discogs')
                                        }
                                    >
                                        Disconnect Discogs
                                    </button>
                                </div>
                            </>
                        ) : (
                            <>
                                <p>
                                    Connect your Discogs account to import your
                                    collection. We never ask for your Discogs
                                    password.
                                </p>
                                <DiscogsConnectButton
                                    reconnect={Boolean(user.discogsUsername)}
                                />
                            </>
                        )}
                    </section>
                    {googleEnabled && !user.googleConnected && (
                        <section
                            className="connection-section google-settings"
                            aria-labelledby="google-sign-in-title"
                        >
                            <h2 id="google-sign-in-title">Google sign-in</h2>
                            <p>
                                Use Google to sign in to this account. Confirm
                                your current password, then choose the Google
                                account for <strong>{user.email}</strong>.
                            </p>
                            <form
                                className="stack"
                                method="post"
                                action="/settings/google"
                            >
                                <input
                                    type="hidden"
                                    name="_token"
                                    value={csrfToken}
                                />
                                <label htmlFor="current_password">
                                    Current password
                                    <input
                                        id="current_password"
                                        name="current_password"
                                        type="password"
                                        autoComplete="current-password"
                                        required
                                    />
                                </label>
                                <button className="button secondary google-button">
                                    Connect Google
                                </button>
                            </form>
                            <p className="muted spaced">
                                Your password will still work. Google never gets
                                your Plaat Club password, and we don’t access
                                your Gmail or Drive.
                            </p>
                        </section>
                    )}
                </div>
            </div>
            <section
                className="panel settings-delete"
                aria-labelledby="delete-account-heading"
            >
                <div>
                    <h2 id="delete-account-heading">
                        Time to put the record away?
                    </h2>
                    <p>
                        Permanently delete your Plaat Club account and its data.
                        Your Discogs collection stays untouched.
                    </p>
                </div>
                <Link
                    className="button secondary"
                    href="/settings/delete-account"
                >
                    Delete my account
                </Link>
            </section>
        </>
    );
}
