# ClientPilot AI

Telegram AI Copilot for freelance client conversations.

ClientPilot AI is a Laravel-based Telegram bot that helps freelancers manage marketplace client conversations with AI-assisted analysis, reply suggestions, conversation memory, and safety-aware guidance. It is designed for human-in-the-loop workflows: the bot suggests, the freelancer reviews, and the freelancer manually sends the final reply on the target platform.

## Overview

Freelancers often manage fast-moving client conversations across marketplaces and direct client channels. Important details can get lost: scope, deadlines, pricing, promises, access requests, and safety risks.

ClientPilot AI acts as a lightweight conversation copilot inside Telegram. A freelancer can paste a client message, get a structured read of the client, review suggested replies, save the reply they actually sent, and keep a compact memory summary for the conversation.

The target platform name is configurable. The sample default is `FreelanceHub`, but the same application structure can be adapted for freelance marketplaces, client marketplaces, or direct client channels.

## Problem It Solves

- Freelancers need quick context before replying to leads and clients.
- Client conversations can include hidden risk around scope, payment, credentials, or deadlines.
- Reply suggestions are useful only when the human stays in control.
- Long conversations need compact memory so the next reply uses the right context.
- Multilingual freelancers may need native-language explanations and target-language client replies.

## How It Works

1. Paste a new client brief or message into Telegram.
2. The bot creates a client conversation and analyzes the opportunity.
3. When the client sends another message, paste it into Telegram.
4. The bot generates a client read, best move, risk note, and reply options.
5. Review the options and manually send the chosen reply on the target platform.
6. Mark the selected option in Telegram so ClientPilot AI stores the actual reply.
7. Continue the conversation with memory summary and safety-aware guidance.

ClientPilot AI does not send messages to marketplaces automatically.

## Key Features

- Telegram webhook endpoint and update routing
- Allowed Telegram user control
- Client creation and active client state
- Conversation message storage
- Initial client analysis
- AI reply suggestions with three option styles
- Sent-option tracking
- Feedback mode for improving suggestions
- Custom reply mode for manually written replies
- Regenerate flow for fresh suggestions
- Memory summary for long-running conversations
- Risk Guard for marketplace-safe conversation guidance
- Inline Telegram callback buttons for client and suggestion actions
- Duplicate update protection and retry-safe callback handling
- Multilingual output configuration
- HTML-escaped copy-friendly reply formatting

## Safety-First Human-In-The-Loop Design

ClientPilot AI is built to support decision-making, not replace the freelancer.

The bot can warn about risks such as:

- unsafe off-platform payment
- unsafe off-platform communication
- starting work before contract, milestone, or payment terms are clear
- suspicious files or downloads
- sensitive credential sharing
- free unpaid scope creep
- unrealistic scope or deadline pressure

When a high-risk situation is detected, the bot keeps the conversation on a marketplace-safe path and suggests safer reply options. The human still reviews and manually sends the final reply.

## Multilingual Support

ClientPilot AI separates the language used for the freelancer from the language used for client-ready replies.

Environment/config options:

```env
NATIVE_LANGUAGE=fa
TARGET_LANGUAGE=en
TARGET_PLATFORM_NAME=FreelanceHub
```

- `NATIVE_LANGUAGE`: language used for bot explanations shown to the freelancer
- `TARGET_LANGUAGE`: language used for ready-to-send client replies
- `TARGET_PLATFORM_NAME`: display name of the target platform or client channel

When native and target languages differ, reply options can show:

- target text: ready-to-send client reply
- native meaning: short translation/meaning for the freelancer

## Example Workflow

```text
Client message:
"Can you start today? I can send payment directly after you finish."

Bot output:
- client read
- best move
- risk warning
- safer reply options

Human action:
- review an option
- manually send it on the target platform
- mark the option as sent in Telegram
```

## Tech Stack

- Laravel
- Telegram Bot API
- OpenAI-compatible AI provider interface
- MySQL
- Laravel queues and jobs
- PHPUnit

## Setup

Install dependencies:

```bash
composer install
npm install
```

Create a local environment file:

```bash
cp .env.example .env
php artisan key:generate
```

Configure local placeholder values in `.env`:

```env
TELEGRAM_BOT_TOKEN=your-telegram-bot-token
TELEGRAM_WEBHOOK_SECRET=change-me
TELEGRAM_ALLOWED_USER_IDS=123456789

OPENAI_API_KEY=your-local-ai-provider-key
OPENAI_MODEL=gpt-4o-mini

NATIVE_LANGUAGE=fa
TARGET_LANGUAGE=en
TARGET_PLATFORM_NAME=FreelanceHub
```

Run migrations:

```bash
php artisan migrate
```

Run the queue worker when using queued jobs:

```bash
php artisan queue:work
```

## Tests

Run the test suite:

```bash
php artisan test
```

## Security Notes

- Do not commit `.env`.
- Do not commit real bot tokens, API keys, webhook secrets, database passwords, ngrok URLs, or private credentials.
- Keep `vendor/`, `node_modules/`, logs, cache files, and local sqlite databases out of Git.
- The bot stores conversation context for workflow continuity; avoid pasting unnecessary secrets into client conversation text.
- AI request logging masks sensitive payload fields and common sensitive text patterns.

## Roadmap Ideas

Possible future improvements:

- richer client detail controls
- admin dashboard for conversation review
- improved prompt management UI
- analytics for conversation outcomes
- additional marketplace-safe policy tuning
- deployment documentation

## License

License not specified yet.
