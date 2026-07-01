# Neuron AI — Webinar 2026

A [Neuron AI](https://github.com/inspector-apm/neuron-ai) agent application, hosted inside a Symfony 8.1 app and run
locally with [DDEV](https://ddev.readthedocs.io/). Neuron drives the AI agents; Symfony provides the web/HTTP layer,
routing, and console.

## Stack

| Component   | Version        |
|-------------|----------------|
| PHP         | 8.4            |
| Symfony     | 8.1            |
| Web server  | nginx-fpm      |
| Database    | MariaDB 11.8   |

## Prerequisites

- [DDEV](https://ddev.readthedocs.io/en/stable/users/install/ddev-installation/) (≥ 1.24)
- Docker (or a supported provider: OrbStack, Colima, Docker Desktop)
- An LLM API key — OpenAI and/or Anthropic

## Clone & start

```bash
# 1. Clone
git clone git@github.com:lussoluca/webinar_neuron_2026.git neuron-ai
cd neuron-ai

# 2. Start DDEV (creates containers, provisions DB)
ddev start

# 3. Install PHP dependencies
ddev composer install
```

## Configure secrets

`.env.local` is gitignored, so a fresh clone has no API keys. Create it with your own:

```bash
ddev exec 'cat > .env.local <<EOF
OPENAI_API_KEY="sk-proj-..."
ANTHROPIC_API_KEY="sk-ant-..."
EOF'
```

## Open the app

```bash
ddev launch          # opens https://neuron-ai.ddev.site in the browser
```
