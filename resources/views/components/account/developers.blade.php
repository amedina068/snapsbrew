<div class="space-y-8">
  {{-- The panels print token and OAuth client names. v-pre stops Vue from compiling them. --}}
  <div v-pre class="space-y-8">
  <x-ui.panel title="API access tokens" description="Create personal access tokens for the REST API.">
    <x-slot name="actions">
      <form method="POST" action="/tokens" class="flex w-full flex-col gap-2 sm:w-auto sm:flex-row sm:items-center">
        @csrf
        <label for="token-name" class="sr-only">Token name</label>
        <x-ui.input name="name" id="token-name" placeholder="Production token" required class="sm:w-56" />
        <x-ui.button icon="plus">New token</x-ui.button>
      </form>
    </x-slot>

    <x-ui.table caption="API access tokens">
      <x-slot name="head">
        <x-ui.th>Name</x-ui.th>
        <x-ui.th>Created</x-ui.th>
        <x-ui.th>Last used</x-ui.th>
        <x-ui.th>Renders (7d)</x-ui.th>
        <x-ui.th align="right">Actions</x-ui.th>
      </x-slot>
      @forelse ($tokens as $token)
        <tr>
          <x-ui.td strong>{{ $token->name }}</x-ui.td>
          <x-ui.td class="whitespace-nowrap">{{ optional($token->created_at)->toDayDateTimeString() }}</x-ui.td>
          <x-ui.td>{{ optional($token->last_used_at)->diffForHumans() ?? 'Never' }}</x-ui.td>
          <x-ui.td>{{ number_format($token->renders_last_7_days ?? 0) }}</x-ui.td>
          <x-ui.td align="right">
            <form action="/tokens/{{ $token->id }}" method="POST"
                  onsubmit="return confirm(@js("Revoke the token {$token->name}? Every request that sends it fails after this. You cannot undo this."))">
              @method('DELETE')
              @csrf
              <x-ui.button size="sm" variant="danger-ghost" aria-label="Revoke {{ $token->name }}">Revoke</x-ui.button>
            </form>
          </x-ui.td>
        </tr>
      @empty
        <tr><x-ui.td colspan="5" class="py-8 text-center">No API tokens yet.</x-ui.td></tr>
      @endforelse
    </x-ui.table>
  </x-ui.panel>

  <x-ui.panel title="Signed URL API keys" description="Use these keys to sign image generation URLs. You can keep multiple keys active to support rotations.">
    <x-slot name="actions">
      <form method="POST" action="/signed-url-keys">
        @csrf
        <x-ui.button icon="plus">Generate new key</x-ui.button>
      </form>
    </x-slot>

    <x-ui.table caption="Signed URL API keys">
      <x-slot name="head">
        <x-ui.th>Key</x-ui.th>
        <x-ui.th>Created</x-ui.th>
        <x-ui.th>Last used</x-ui.th>
        <x-ui.th>Renders (7d)</x-ui.th>
        <x-ui.th>Status</x-ui.th>
        <x-ui.th align="right">Actions</x-ui.th>
      </x-slot>
      @forelse ($apiKeys as $apiKey)
        <tr>
          <x-ui.td class="font-mono text-gray-900">{{ $apiKey->key }}</x-ui.td>
          <x-ui.td class="whitespace-nowrap">{{ optional($apiKey->created_at)->toDayDateTimeString() }}</x-ui.td>
          <x-ui.td>{{ optional(optional($apiKey->accessToken)->last_used_at)->diffForHumans() ?? 'Never' }}</x-ui.td>
          <x-ui.td>{{ number_format($apiKey->renders_last_7_days ?? 0) }}</x-ui.td>
          <x-ui.td>
            @if ($apiKey->invalidated_at)
              <x-ui.badge>Revoked</x-ui.badge>
            @else
              <x-ui.badge tone="success">Active</x-ui.badge>
            @endif
          </x-ui.td>
          <x-ui.td align="right">
            @if (! $apiKey->invalidated_at)
              <form method="POST" action="/signed-url-keys/{{ $apiKey->id }}"
                    onsubmit="return confirm('Revoke this signed URL key? Every signed URL that uses it stops working. You cannot undo this.')">
                @method('DELETE')
                @csrf
                <x-ui.button size="sm" variant="danger-ghost">Revoke</x-ui.button>
              </form>
            @else
              <span class="text-gray-500">—</span>
            @endif
          </x-ui.td>
        </tr>
      @empty
        <tr><x-ui.td colspan="6" class="py-8 text-center">No signed URL keys yet. Generate one to get started.</x-ui.td></tr>
      @endforelse
    </x-ui.table>
  </x-ui.panel>

  <x-ui.panel title="OAuth connections" description="Applications that you connected with OAuth, for example an MCP connector. Revoke a connection to end its access and refresh tokens.">
    <x-ui.table caption="OAuth connections">
      <x-slot name="head">
        <x-ui.th>Application</x-ui.th>
        <x-ui.th>Connected</x-ui.th>
        <x-ui.th>Last token issued</x-ui.th>
        <x-ui.th align="right">Actions</x-ui.th>
      </x-slot>
      @forelse ($oauthConnections as $connection)
        <tr>
          <x-ui.td strong>{{ $connection->client_name }}</x-ui.td>
          <x-ui.td class="whitespace-nowrap">{{ optional($connection->connected_at)->toDayDateTimeString() }}</x-ui.td>
          <x-ui.td>{{ optional($connection->last_token_at)->diffForHumans() ?? 'Never' }}</x-ui.td>
          <x-ui.td align="right">
            <form method="POST" action="{{ url('/account/oauth-connections/'.$connection->client_id) }}"
                  onsubmit="return confirm(@js("Revoke the connection of {$connection->client_name}? The application loses its access and must connect again."))">
              @method('DELETE')
              @csrf
              <x-ui.button size="sm" variant="danger-ghost" aria-label="Revoke {{ $connection->client_name }}">Revoke</x-ui.button>
            </form>
          </x-ui.td>
        </tr>
      @empty
        <tr><x-ui.td colspan="4" class="py-8 text-center">No OAuth connections yet.</x-ui.td></tr>
      @endforelse
    </x-ui.table>
  </x-ui.panel>

  </div>

  {{-- Outside v-pre: the copy field is a Vue component. The secret holds only letters, digits and "_". --}}
  <x-ui.panel title="Webhook signing secret" description="Snapsbrew signs each webhook of your company with this secret. Check the X-Snapsbrew-Signature header to confirm that a request came from Snapsbrew.">
    <x-slot name="actions">
      @if ($canRotateWebhookSecret)
        <form method="POST" action="/account/webhook-secret"
              onsubmit="return confirm('Rotate the webhook signing secret? Your receivers reject the next webhook until you give them the new secret.')">
          @csrf
          <x-ui.button variant="secondary">Rotate secret</x-ui.button>
        </form>
      @else
        <div class="flex items-center gap-3">
          <span id="webhook-owner-only" class="text-sm text-gray-600">Only a company owner can do this.</span>
          <x-ui.button type="button" variant="secondary" disabled aria-describedby="webhook-owner-only">Rotate secret</x-ui.button>
        </div>
      @endif
    </x-slot>

    <x-ui.copy-field :value="$webhookSecret" />
    <p class="mt-3 text-sm text-gray-600">
      The signature is the HMAC-SHA256 hex digest of the X-Snapsbrew-Timestamp value, a period and the raw request body.
      Reject a request when the timestamp is more than five minutes old.
    </p>
  </x-ui.panel>

  @if (config('mcp.enabled'))
    <x-ui.panel title="Connect via MCP">
      <p class="text-sm text-gray-600">
        Drive {{ config('app.name') }} from an AI client (Claude Desktop, Cursor, …) over the
        Model Context Protocol. Use any API access token above as the Bearer
        credential — the MCP tools let the assistant list, create, edit and
        preview templates, and create snaps.
      </p>
      <div class="mt-5">
        <mcp-connect url="{{ url('/mcp') }}"></mcp-connect>
      </div>
    </x-ui.panel>
  @endif
</div>
