import type { StreamResponseFailure } from './errors'
import { csrfHeaders, isStreamResponseError, readJsonEvents } from './eventStream'
import {
  createResumableConnection,
  resumableConnectionConfig,
  type ResumableConnectionOptions,
} from './resumableConnection'
import {
  resumableReaderFor,
  type ConnectionOptions,
  type CsrfHeaders,
  type JsonEventStreamReader,
} from './streamReader'
import type { ChatConnection, ChatSource } from './types'

/**
 * The stream reader connections are built against.
 */
export type StreamAdapter = {
  read: JsonEventStreamReader
  csrf: CsrfHeaders
  isResponseFailure: (error: unknown) => error is StreamResponseFailure
}

/**
 * Builds the resumable transport from the payload the server rendered.
 */
export type ResumableConnectionFactory = (
  source: ChatSource | (() => ChatSource),
  options?: ResumableConnectionOptions,
) => ChatConnection

/**
 * The transports built against one stream reader.
 */
export type AdapterConnections = {
  resumableConnection: ResumableConnectionFactory
  /**
   * The transport a payload's chat runs on: a JSON send that claims a run, and a follow of the thread's stream.
   */
  connectionFor: (source: () => ChatSource, options?: ConnectionOptions) => ChatConnection
}

/**
 * Build the transports against a stream reader.
 */
export function connectionsFor(adapter: StreamAdapter): AdapterConnections {
  const read = resumableReaderFor(adapter.read)

  const resumableConnection: ResumableConnectionFactory = (source, options = {}) =>
    createResumableConnection({
      ...resumableConnectionConfig(source, options),
      read,
      csrf: adapter.csrf,
      isResponseFailure: adapter.isResponseFailure,
    })

  return {
    resumableConnection,
    connectionFor: (source, options = {}) => resumableConnection(source, options),
  }
}

const connections = connectionsFor({
  read: readJsonEvents,
  csrf: csrfHeaders,
  isResponseFailure: isStreamResponseError,
})

/**
 * Build the resumable transport, which follows a thread's stream from where it left off.
 */
export const resumableConnection: ResumableConnectionFactory = connections.resumableConnection

/**
 * Build the transport a payload's chat runs on, which follows the thread from the payload's offset.
 */
export const connectionFor: AdapterConnections['connectionFor'] = connections.connectionFor

/**
 * The transport a chat is given, or the options the default one is built from.
 */
export type TransportOptions = ConnectionOptions & {
  connection?: ChatConnection
}

/**
 * Build the transport a chat runs on, reading its options afresh for every request so a changed one takes effect on the next turn.
 */
export function transportFor(source: () => ChatSource, options: () => TransportOptions): ChatConnection {
  assertTransportOptions(options())

  // Built once, since a follow learns of a send only through the connection both went through.
  const fallback = resumableConnection(source, {
    headers: () => options().headers,
    credentials: () => options().credentials,
    csrfToken: () => options().csrfToken,
  })

  const resolve = (): ChatConnection => {
    const current = options()

    assertTransportOptions(current)

    return current.connection ?? fallback
  }

  const connection: ChatConnection = (request) => resolve()(request)

  // Getters, since the store probes `attach` and `cancel` by presence and the transport they belong to may change.
  Object.defineProperty(connection, 'attach', {
    get: (): ChatConnection['attach'] => {
      const attach = resolve().attach

      return attach === undefined ? undefined : (request) => attach(request)
    },
  })

  Object.defineProperty(connection, 'cancel', {
    get: (): ChatConnection['cancel'] => {
      const cancel = resolve().cancel

      return cancel === undefined ? undefined : (request) => cancel(request)
    },
  })

  return connection
}

/**
 * Refuse a transport handed options it will never read.
 */
export function assertTransportOptions(options: {
  connection?: unknown
  headers?: unknown
  credentials?: unknown
  csrfToken?: unknown
}): void {
  if (options.connection === undefined) {
    return
  }

  const ignored = (['headers', 'credentials', 'csrfToken'] as const).filter((name) => options[name] !== undefined)

  // These build the default transport. A transport that was built elsewhere takes them from whoever built it.
  if (ignored.length > 0) {
    throw new Error(
      `A chat given its own [connection] never reads [${ignored.join(', ')}]. Pass them to the connection instead.`,
    )
  }
}
