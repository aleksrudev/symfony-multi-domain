# Using AI Coding Agents with Dev Containers

This is an environment setup guide. Project rules live in the root
[AGENTS.md](../../AGENTS.md), the [module map](map.md), and the owners' instructions.
The guide does not grant permission to install dependencies, change the environment,
apply migrations, or perform destructive actions. Tool permission modes do not
override the project's approval rules.

This project ships with a [Dev Container](https://containers.dev/) configuration that enables
AI coding agents to run autonomously inside a sandboxed environment with network-level restrictions.

[Claude Code](https://claude.ai/claude-code) is pre-installed and configured out of the box,
but the setup also works with other agents such as [OpenAI Codex CLI](https://github.com/openai/codex)
and [opencode](https://opencode.ai).

This setup is ideal for letting AI agents work on your Symfony project autonomously
with restrictions on outbound internet access.

## Prerequisites

- [Docker Desktop](https://www.docker.com/products/docker-desktop/) (or any Docker-compatible runtime)
- [Visual Studio Code](https://code.visualstudio.com/) with the [Dev Containers](https://marketplace.visualstudio.com/items?itemName=ms-vscode-remote.remote-containers) extension
- A valid subscription or API key for the agent you want to use

## Quick Start

1. Open the project in Visual Studio Code.
2. When prompted "Reopen in Container", click **Reopen in Container**.
   Alternatively, open the Command Palette (`Ctrl+Shift+P` / `Cmd+Shift+P`) and run
   **Dev Containers: Reopen in Container**.
3. Wait for the container to build and start. On each container start, the
   `postStartCommand` configures the firewall automatically.
4. Claude Code is pre-installed and configured in YOLO mode — open the Claude Code
   panel in Visual Studio Code or run `claude` in the integrated terminal to start using it.

Claude Code's tool permission prompts are bypassed by this configuration.
Agents still follow the project's approval rules. The firewall restricts outbound
internet traffic; it also allows DNS, private networks, and the host gateway.

## What Is YOLO Mode?

YOLO mode (also known as "bypass permissions" mode) allows Claude Code to execute
commands, edit files, and perform actions without asking for confirmation at each step.
This dramatically speeds up autonomous coding workflows.

The Dev Container configuration enables this via two Visual Studio Code settings:

```json
{
  "claudeCode.allowDangerouslySkipPermissions": true,
  "claudeCode.initialPermissionMode": "bypassPermissions"
}
```

## Network Sandboxing

Running an AI agent with full autonomy requires guardrails. The Dev Container includes
a firewall script (`.devcontainer/init-firewall.sh`) that locks down outbound network
access using `iptables` and `ipset`. The allowed internet destinations include:

| Destination                                       | Reason                          |
| ------------------------------------------------- | ------------------------------- |
| GitHub (`github.com`, `api.github.com`)           | Git operations, API access      |
| Anthropic (`anthropic.com`)                       | Claude Code backend             |
| npm registry (`registry.npmjs.org`)               | Node.js dependencies            |
| Packagist (`packagist.org`, `repo.packagist.org`) | PHP/Composer dependencies       |
| jsDelivr (`cdn.jsdelivr.net`)                     | AssetMapper dependencies        |
| Visual Studio Code Marketplace                    | Extension downloads             |
| Sentry, Statsig                                   | Telemetry (used by Claude Code) |
| Host gateway IP                                   | Communication with Docker host  |

DNS traffic, loopback, the host gateway, and private IPv4 networks
(`10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`) are also allowed.
Other outbound connections are **rejected**. The firewall uses
[dnsmasq](https://thekelleys.org.uk/dnsmasq/doc.html) to dynamically resolve
and whitelist IPs for allowed domains, handling CDN IP rotation gracefully.

Inbound connections from the host gateway IP are allowed on all ports,
and ports 80, 443 (TCP), and 443 (UDP/HTTP3) are open to any source
so you can access your Symfony app from the host browser.

## Customizing the Allowed Domains

Changes to the firewall allowlist and container rebuilds require the environment
approval described in [AGENTS.md](../../AGENTS.md). After approval, to allow
additional domains (e.g., a private registry or API), edit
`.devcontainer/init-firewall.sh` and add them to the `ipset` line in the
dnsmasq configuration section:

```bash
# Domains are '/'-separated, ending with the ipset name
ipset=/github.com/anthropic.com/your-domain.com/allowed-domains
```

Then rebuild the Dev Container for the changes to take effect.

## Using Other Agents

The Dev Container's network sandbox and project context (`AGENTS.md`) work
with any AI coding agent. You just need to install the agent and whitelist the domains it
needs to reach.

### OpenAI Codex CLI

1. Add the OpenAI API domain to the firewall allowlist in `.devcontainer/init-firewall.sh`
   (see [Customizing the Allowed Domains](#customizing-the-allowed-domains)):

   ```bash
   ipset=/.../api.openai.com/allowed-domains
   ```

2. Install and run Codex inside the container:

   ```console
   npm install -g @openai/codex
   export OPENAI_API_KEY=your-key
   codex --full-auto
   ```

### opencode

1. Add the required API domain to the firewall allowlist (e.g., `api.anthropic.com`,
   `api.openai.com`, or your provider's domain).

2. Install and run opencode inside the container:

   ```console
   curl -fsSL https://opencode.ai/install | bash
   opencode
   ```

### Other Agents

For any other agent, follow the same pattern:

1. Add the agent's API domain(s) to the firewall allowlist.
2. Install the agent inside the container.
3. Run it — the root `AGENTS.md` file provides project context
   to agents that support the convention.

## Using Without Visual Studio Code

The Dev Container configuration works with any tool that supports the
[Dev Container specification](https://containers.dev/), including:

- [Dev Container CLI](https://github.com/devcontainers/cli) (`devcontainer up`)
- [GitHub Codespaces](https://github.com/features/codespaces)
- JetBrains IDEs (with the Dev Containers plugin)

To use Claude Code from the terminal inside the container:

```console
claude
```

To start directly in YOLO mode from the CLI:

```console
claude --dangerously-skip-permissions
```

## Troubleshooting

### Firewall blocks a required domain

If your agent or Composer/npm fails to reach a service, check the firewall
logs and add the domain to the dnsmasq allowlist as described above.

### Container fails to start

Ensure Docker is running and that you have allocated enough resources
(at least 2 GB of RAM for the container). The firewall setup requires
`NET_ADMIN` capability, which the Dev Container configures automatically
via Docker Compose.
