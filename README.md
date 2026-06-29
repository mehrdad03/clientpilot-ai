# ClientPilot AI

**Telegram AI Copilot for Business Client Conversations**

ClientPilot AI is a Laravel-based Telegram bot that helps users manage business client conversations with AI-assisted analysis, reply suggestions, conversation memory, multilingual support, and safety-aware guidance.

It is designed for **human-in-the-loop communication workflows**: the bot analyzes, suggests, and organizes — while the human reviews and manually sends the final reply on the target platform or communication channel.

---

## Overview

Business conversations often move fast. Whether the message comes from a client, lead, customer, partner, or project requester, important details can get lost:

* scope
* intent
* deadlines
* pricing
* promises
* access requests
* risks
* next best action

ClientPilot AI acts as a lightweight communication copilot inside Telegram.

A user can paste an incoming client message, project brief, or business request, get a structured read of the conversation, review suggested replies, save the reply they actually sent, and keep a compact memory summary for future context.

The target platform name is configurable. The sample default is `FreelanceHub`, but the same application structure can be adapted for client marketplaces, sales channels, direct business messaging, service businesses, consulting workflows, or internal customer communication processes.

---

## Problem It Solves

Client conversations can become messy when handled manually across different platforms and channels.

ClientPilot AI helps with:

* understanding what the client really wants
* identifying hidden risks before replying
* generating clear and professional reply options
* keeping the human in control
* remembering previous conversation context
* supporting multilingual communication
* keeping replies aligned with safe business communication practices

The goal is not to automate sending messages.
The goal is to make the human faster, clearer, and better prepared before replying.

---

## Use Cases

ClientPilot AI can be adapted for different communication workflows, including:

### 💼 Sales Conversations

Qualify leads, understand buying intent, detect objections, and generate clear next-step replies.

### 🧩 Project Requirement Discussions

Analyze project briefs, clarify missing requirements, identify scope risks, and prepare professional responses.

### 🧑‍💻 Freelancers & Independent Professionals

Help freelancers manage client conversations more effectively, understand project expectations, avoid risky agreements, and respond professionally across freelance platforms and direct client channels.

### 🛠️ Service Business Communication

Help agencies, consultants, developers, designers, and operators respond to client requests more consistently.

### 🎧 Support-Style Business Messaging

Summarize customer issues, suggest careful replies, and keep track of unresolved questions.

### 🌍 Multilingual Client Replies

Show explanations in the user’s native language while generating ready-to-send replies in the target client language.

### ⚠️ Risk-Aware Communication

Warn about risky requests such as unclear scope, unsafe payment terms, suspicious files, credential sharing, or unrealistic deadlines.

### 🧠 Conversation Memory

Keep a compact summary of what was discussed, what was promised, and what needs to happen next.

---

## How It Works

1. Paste a new client brief, request, or message into Telegram.
2. The bot creates a client conversation and analyzes the opportunity.
3. When the client sends another message, paste it into Telegram.
4. The bot generates a client read, best move, risk note, and reply options.
5. Review the options and manually send the chosen reply on the target platform or communication channel.
6. Mark the selected option in Telegram so ClientPilot AI stores the actual reply.
7. Continue the conversation with memory summary and safety-aware guidance.

ClientPilot AI does **not** send messages to external platforms automatically.

---

## Key Features

* Telegram webhook endpoint and update routing
* Allowed Telegram user control
* Client creation and active client state
* Conversation message storage
* Initial client analysis
* AI reply suggestions with multiple option styles
* Sent-option tracking
* Feedback mode for improving suggestions
* Custom reply mode for manually written replies
* Regenerate flow for fresh suggestions
* Memory summary for long-running conversations
* Risk Guard for safer client communication
* Inline Telegram callback buttons for client and suggestion actions
* Duplicate update protection and retry-safe callback handling
* Multilingual output configuration
* HTML-escaped copy-friendly reply formatting
* Dynamic target platform/channel name

---

## Safety-First Human-In-The-Loop Design

ClientPilot AI is built to support decision-making, not replace the human.

The bot can warn about risks such as:

* unsafe off-platform payment
* unsafe off-platform communication
* starting work before contract, milestone, or payment terms are clear
* suspicious files or downloads
* sensitive credential sharing
* free unpaid scope creep
* unrealistic scope or deadline pressure
* unclear ownership or delivery expectations

When a high-risk situation is detected, the bot helps keep the conversation on a safer communication path and suggests safer reply options.

The human still reviews and manually sends the final reply.

---

## Multilingual Support

ClientPilot AI separates the language used for internal guidance from the language used for client-ready replies.

Environment/config options:

```env
NATIVE_LANGUAGE=fa
TARGET_LANGUAGE=en
TARGET_PLATFORM_NAME=FreelanceHub
```

* `NATIVE_LANGUAGE`: language used for bot explanations shown to the user
* `TARGET_LANGUAGE`: language used for ready-to-send client replies
* `TARGET_PLATFORM_NAME`: display name of the target platform, marketplace, or communication channel

When native and target languages differ, reply options can show:

* `target_text`: ready-to-send client reply
* `native_meaning`: short translation or meaning for the user

This helps the user understand the reply clearly before sending it manually.

---

## Example Workflow

```text
Incoming client message:
"Can you start today? I need this done quickly, and I can pay after everything is finished."

ClientPilot AI output:
- client intent analysis
- best next move
- risk warning
- safer reply options

Human action:
- reviews the suggested replies
- manually sends the selected reply on the target platform or channel
- marks the option as sent in Telegram
```

---

## Example Reply Flow

```text
Client:
Can you do this quickly and how much will it cost?

ClientPilot AI:
Client intent analyzed.
Risk checked.
3 reply options generated.

Ready-to-send reply:
Thanks for the details. I can help with this, but I would first need to confirm the exact scope, timeline, and expected deliverables before giving a final estimate.
```

---

## Tech Stack

* Laravel
* PHP
* Telegram Bot API
* OpenAI-compatible AI provider interface
* MySQL
* Laravel queues and jobs
* PHPUnit

---

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

---

## Tests

Run the test suite:

```bash
php artisan test
```

---

## Security Notes

* Do not commit `.env`.
* Do not commit real bot tokens, API keys, webhook secrets, database passwords, ngrok URLs, or private credentials.
* Keep `vendor/`, `node_modules/`, logs, cache files, and local sqlite databases out of Git.
* The bot stores conversation context for workflow continuity; avoid pasting unnecessary secrets into client conversation text.
* AI request logging masks sensitive payload fields and common sensitive text patterns.
* ClientPilot AI does not automatically send messages to external platforms.

---

## Roadmap Ideas

Possible future improvements:

* richer client detail controls
* admin dashboard for conversation review
* improved prompt management UI
* analytics for conversation outcomes
* additional communication safety policy tuning
* deployment documentation
* multi-workspace support
* reusable templates for different business types
* channel-specific conversation presets

---

## License

License not specified yet.
