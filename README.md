<!--
SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
SPDX-License-Identifier: MIT
-->

# koh/sylius-agent-contracts

Shared contracts for the `koh` Sylius agent-readiness bundles.

This package serves no endpoint. It exists so that the granular
one-package-per-protocol split stays composable: `/.well-known/api-catalog`,
`/.well-known/agent-skills/index.json`, the A2A agent card, `llms.txt`, `auth.md`
and the `Link:` response headers all need to know what the *other*, independently
installable bundles offer — and none of them may depend on each other to find out.

## Install

```bash
composer require koh/sylius-agent-contracts
```

```php
// config/bundles.php
return [
    // …
    Koh\SyliusAgentContracts\KohSyliusAgentContractsBundle::class => ['all' => true],
];
```

## Configure

```yaml
# config/packages/koh_agent.yaml
koh_agent:
    # Scheme for absolute URLs. TLS usually terminates at the ingress, so the
    # request scheme is not trustworthy.
    scheme: 'https'

    # Off by default: base URLs come from Channel::getHostname(). Only turn this
    # on once framework.trusted_proxies is configured, otherwise every absolute
    # URL in every discovery document points at the internal cluster host.
    trust_request_host: false

    cache:
        max_age: 3600
        stale_while_revalidate: 86400
```

## What you get

### `AgentContext`

Resolved once per request, passed through every provider. Sylius has N channels
where a CMS has one site, so every discovery document is channel- *and*
locale-dependent.

```php
// inject Koh\SyliusAgentContracts\Context\AgentContextFactoryInterface
$context = $agentContextFactory->create();

$context->url('/llms.txt');            // https://shop.example.com/llms.txt
$context->cacheKey('koh_llms_txt');    // koh_llms_txt.WEB_DE.de_DE
```

`AgentContext::cacheKey()` is the only sanctioned way to build a cache key in
this package family. Skipping it is the classic multi-channel bug: one channel
warms the cache and every other channel is served its documents.

Depend on `AgentContextFactoryInterface`, not the concrete class — the
implementation reaches into Sylius' channel and locale contexts, which a unit
test of your listener has no business booting.

The factory resolves the channel in three stages — Sylius' channel context,
then a hostname lookup, then the first enabled channel. The fallback matters:
`ChannelContextInterface` throws when no channel matches the host, which is
exactly what a bot arriving over the bare IP, a preview hostname or a
misconfigured proxy produces. Unhandled, that turns every `/.well-known/*`
request into a 500 — the most common reason an agent-readiness scan fails on a
multi-channel shop.

### Provider interfaces

Implement one (or several) and it is picked up automatically —
`registerForAutoconfiguration()` is registered container-wide, so this works for
services in your own `App\` namespace with no wiring at all.

| Interface | DI tag | Consumed by |
|---|---|---|
| `AgentEndpointProviderInterface` | `koh_agent.endpoint` | llms.txt, api-catalog |
| `ApiCatalogEntryProviderInterface` | `koh_agent.api_catalog_entry` | api-catalog, `Link:` headers |
| `AgentSkillProviderInterface` | `koh_agent.skill` | A2A card, agent-skills index, MCP |
| `LlmsTxtSectionProviderInterface` | `koh_agent.llms_txt_section` | llms.txt, llms-full.txt |
| `MarkdownRepresentationProviderInterface` | `koh_agent.markdown` | `Accept: text/markdown` |
| `AuthMethodDescriptorInterface` | `koh_agent.auth_method` | auth.md, `agent_auth` metadata |
| `PayableOperationProviderInterface` | `koh_agent.payable_operation` | MPP `x-payment-info`, x402 |
| `ResponseHeaderContributorInterface` | `koh_agent.response_header` | the shared response listener |
| `PaymentBypassVoterInterface` | `koh_agent.payment_bypass` | x402 |

Example — adding your own section to `llms.txt`:

```php
use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusAgentContracts\Model\{LlmsTxtLink, LlmsTxtSection};
use Koh\SyliusAgentContracts\Provider\LlmsTxtSectionProviderInterface;

final class ArticleLlmsTxtSectionProvider implements LlmsTxtSectionProviderInterface
{
    public function getLlmsTxtSection(AgentContext $context): ?LlmsTxtSection
    {
        $links = [];
        foreach ($this->articles->findPublished($context->channel, 100) as $article) {
            $links[] = new LlmsTxtLink(
                $article->getTitle(),
                $context->url('/magazin/'.$article->getSlug()),
                $article->getTeaser(),
            );
        }

        return [] === $links ? null : new LlmsTxtSection('Magazin', $links, priority: 80);
    }
}
```

### `AgentJsonResponder`

One consistent response shape for every discovery document: `public,
max-age=3600, stale-while-revalidate=86400`, an ETag (returning crawlers send
`If-None-Match`, and a 304 is far cheaper than a rebuild),
`X-Content-Type-Options: nosniff` and `Access-Control-Allow-Origin: *` — in-browser
agents read these cross-origin.

### `AgentResponseHeaderListener`

The single `kernel.response` listener for the whole family. `Link`,
`Content-Signal`, `Vary` and CORS all land on the same response; with one
listener per bundle they overwrite each other depending on registration order.
The merge rules are enforced here:

- `Link` is **appended**, never replaced — Sylius and API Platform set their own
  (`rel="preload"`, Hydra `apiDocumentation`) and clobbering them breaks the app.
- `Vary` is **merged**, never replaced.

## Deployment note

`.well-known` paths fall inside Sylius' `shop` firewall, which is stateful
(`context: shop`, `remember_me`). Without an exemption every bot hit starts a
session and the responses become uncacheable. Add a dedicated entry **above**
the Sylius firewalls — `access_control` is not enough, it is evaluated only
after the firewall has already matched:

```yaml
security:
    firewalls:
        koh_agent_public:
            pattern: '^/(\.well-known/(?!oauth)|llms\.txt|llms-full\.txt|auth\.md|robots\.txt|sitemap|openapi\.json)'
            security: false
        # … then the Sylius firewalls
```

## Decommission

Removing the package is safe once no sibling bundle is installed: drop the
`composer require`, the entry in `config/bundles.php`, `config/packages/koh_agent.yaml`
and the `koh_agent_public` firewall. It owns no database table, no route and no
cache pool of its own.

## Security

Report vulnerabilities privately — see [SECURITY.md](SECURITY.md). Advisories
are published as CSAF 2.0 at <https://ole-hartwig.eu/.well-known/csaf/>.

## Licence

MIT — see [LICENSE](LICENSE).
