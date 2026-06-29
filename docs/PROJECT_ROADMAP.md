# ClientPilot AI Roadmap

## Sprint 1: Foundation + Telegram Base

- Create a fresh Laravel application.
- Add Telegram webhook configuration.
- Add a thin Telegram webhook controller.
- Resolve allowed Telegram users from environment-backed config.
- Store Telegram users, user states, and processed update IDs.
- Add idempotent Telegram update routing.
- Add a basic `/start` command.
- Send the main menu with `➕ New Client` and `👥 My Clients` buttons.

## Sprint 2: Client Management

- Handle the `➕ New Client` button.
- Set Telegram user state to `waiting_for_new_client_job`.
- Receive the initial job description or client message.
- Create a client record.
- Store the initial text in `conversation_messages`.
- Set `active_client_id` for the Telegram user.
- List clients with `👥 My Clients`.
- Support active-client actions: Resume Client, Pause Client, Close Client.
- Add a View Summary placeholder only.
- Add `ClientSessionManager`.
- Add client status/stage enums.
- Add conversation sender/message type enums.

## Sprint 3: AI Foundation

- Add `AiProviderInterface`.
- Add `OpenAiProvider` infrastructure for the OpenAI Responses API.
- Add AI config from `.env`.
- Add OpenAI model config.
- Add `PromptBuilderService`.
- Add prompt versioning structure.
- Add placeholder prompt files under `resources/prompts/sales-copilot`.
- Add `ai_requests` table and `AiRequest` model.
- Add `AiRequestLogger`.
- Add `AiSensitiveDataMasker`.
- Add `AiJsonResponseValidator`.
- Add queue-ready request metadata for future AI jobs without dispatching jobs.

## Sprint 4: Client Analysis Flow

- Add `ClientAnalysisService`.
- Add `AnalyzeClientJob`.
- Add real content for `sales_copilot_analysis_v1`.
- After New Client is created, send `در حال تحلیل...`.
- Dispatch `AnalyzeClientJob`.
- Load client/job context and build the analysis prompt.
- Call `AiProviderInterface`.
- Validate a structured JSON analysis response.
- Log AI request lifecycle in `ai_requests`.
- Update client analysis fields and set `stage = analyzed`.
- Store a `bot_analysis` message in `conversation_messages`.
- Send the structured Telegram analysis message.
- Show the `💬 Start Chat` button.
- Set `chatting_with_client` state and `stage = chatting` when Start Chat is pressed.

## Sprint 5: Chat + Reply Suggestions

- Treat incoming text in `chatting_with_client` as a new client marketplace message.
- Store chat messages in `conversation_messages` with `sender = client` and `message_type = client_message`.
- Send `در حال تحلیل پیام مشتری...`.
- Dispatch `GenerateReplySuggestionsJob`.
- Add `ConversationBrainService`.
- Add `ReplySuggestionService`.
- Add `bot_suggestions` and `bot_suggestion_options`.
- Use `sales_copilot_reply_v1`.
- Generate and validate structured reply suggestion JSON.
- Store suggestions and 3 reply options.
- Send Telegram output with client read, best move, risk, and reply options.
- Show action buttons for sent options, regenerate, feedback, custom reply, and pause.
- Keep sent option persistence, feedback mode, custom reply mode, real regenerate, Memory Summary, and full Risk Guard behavior out of this sprint.

## Sprint 6: Select Sent Option

- Handle `✅ Sent Option 1`, `✅ Sent Option 2`, and `✅ Sent Option 3`.
- Find the latest generated suggestion for the active client.
- Find the selected suggestion option.
- Mark `bot_suggestions.status = selected`.
- Store `selected_option_id`, `selected_text`, and `selected_at`.
- Store a `conversation_messages` record with `sender = mehrdad`, `message_type = selected_reply`, and metadata containing suggestion and option IDs.
- Keep user state as `chatting_with_client`.
- Send a safe Telegram confirmation.
- Handle stale or already-selected suggestions without creating duplicate conversation messages.

## Sprint 7: Feedback Mode

- Handle the `ًں“‌ I donâ€™t like these replies` button.
- Store the active `bot_suggestion_id` in Telegram user state payload.
- Set Telegram user state to `waiting_for_feedback_reason`.
- Ask Mehrdad why he is not satisfied with the replies.
- Store feedback in `user_feedbacks`.
- Dispatch `ReviewUserFeedbackJob`.
- Add `FeedbackReviewService`.
- Use `sales_copilot_feedback_review_v1`.
- Validate AI feedback decisions: `accepted`, `rejected`, `partially_accepted`.
- Validate feedback result actions: `regenerated`, `kept_original`, `modified`.
- Require AI to review feedback critically instead of blindly obeying Mehrdad.
- Generate a new suggestion and options only for accepted or partially accepted feedback.
- Keep original options usable when feedback is rejected.
- Return Telegram user state to `chatting_with_client`.
- Keep Custom Reply mode, real Regenerate, Memory Summary, and full Risk Guard behavior out of this sprint.

## Sprint 8: Custom Reply Mode

- Handle the `✍️ I wrote my own reply` button.
- Set Telegram user state to `waiting_for_custom_reply`.
- Store `client_id` and optional `suggestion_id` in Telegram user state payload.
- Ask Mehrdad to paste the exact reply he manually sent on the target platform.
- Store custom replies in `conversation_messages` with `sender = mehrdad` and `message_type = custom_reply`.
- Preserve the exact custom reply text without rewriting it.
- Store `metadata.suggestion_id` only when a valid suggestion is available.
- Add `CustomReplyService`.
- Analyze custom reply commitments with simple rule-based checks only.
- Detect pricing, deadline, promises, access needs, risk level, and next stage.
- Update client stage only when the detected next stage is a valid `ClientStage`.
- Send a confirmation with any detected commitment or risk in Persian.
- Return Telegram user state to `chatting_with_client`.
- Keep Memory Summary, real Regenerate, full Risk Guard behavior, reply generation changes, and selected option changes out of this sprint.

## Sprint 9: Memory Summary

- Add `client_summaries` table and `ClientSummary` model.
- Add `MemorySummaryService`.
- Add `ClientSummaryBuilder`.
- Add `UpdateClientSummaryJob`.
- Add real content for `sales_copilot_summary_v1`.
- Track summary, current context, client needs, Mehrdad promises, pricing, deadlines, access, open questions, risk notes, next best move, and `last_message_id`.
- Trigger memory summary updates after client analysis, selected replies, custom replies, client-message reply suggestions, and feedback regeneration.
- Make summary updates fail-safe so AI failures do not break the main Telegram flow.
- Update `ConversationBrainService` to use client memory summary plus recent messages instead of full conversation history.
- Implement the View Summary button with a safe message when no summary exists.
- Keep real Regenerate, full Risk Guard behavior, new reply generation behavior, new feedback behavior, new custom reply behavior, and selected option behavior changes out of this sprint.

## Sprint 10: Risk Guard + Closing

- Add `RiskGuardService`.
- Add `MarketplaceSafetyPolicyService`.
- Add real content for `risk_guard_v1`.
- Integrate Risk Guard into reply suggestion generation only.
- Detect high-risk marketplace safety cases: outside communication, outside payment, unfunded work, free samples, suspicious files, sensitive credentials, unrealistic deadlines, huge scope with low budget, and unclear fast starts.
- Show a visible Persian high-risk warning in Telegram reply suggestion output.
- Normalize unsafe AI output to safer reply options focused on marketplace communication, clear scope, and funded milestones.
- Add Contract Closing Mode inside reply suggestion generation so ready clients are guided toward clear scope, deliverables, timeline, and a funded marketplace milestone.
- Keep real Regenerate, new Feedback behavior, new Custom Reply behavior, Memory Summary behavior changes, selected option changes, and marketplace automation out of this sprint.

## Sprint 11: Reliability + Polish

- Review and test duplicate Telegram update protection.
- Make sent-option selection retry-safe so repeated button presses do not create duplicate conversation messages.
- Review stale suggestion handling and keep stale/already-selected responses safe.
- Add per-client AI processing lock for analysis, reply suggestions, feedback review, and memory summary updates.
- Release AI processing locks in `finally`.
- Send a safe Telegram message when another AI process is already running for the same client.
- Catch failed AI jobs so webhook flow is not broken.
- Send a clear Telegram failure message when AI processing fails.
- Improve log and AI request masking for tokens, emails, phones, passwords, and authorization-like text.
- Add basic reliability tests for `/start`, unauthorized users, New Client, My Clients, sent option selection, feedback state, custom reply state, Risk Guard high-risk replies, View Summary fallback, duplicate updates, busy AI locks, and failed AI jobs.

## Sprint 12: Real Regenerate Button

- Handle the `Regenerate` button for the latest suggestion on the active client.
- Regenerate only suggestions with `status = generated`.
- Keep selected suggestions unchanged and tell Mehrdad the reply was already saved as sent.
- Keep stale or already-regenerated suggestions safe without creating new suggestions.
- Send `در حال ساخت جواب‌های جدید...` before regeneration starts.
- Use `ClientAiProcessingLock` for regeneration.
- Reuse `ReplySuggestionService`, `ConversationBrainService`, Memory Summary context, and Risk Guard rules.
- Create a new `bot_suggestion` and `bot_suggestion_options` after successful regeneration.
- Mark the old suggestion as `regenerated` only after the new suggestion is created successfully.
- Keep user state as `chatting_with_client`.
- Keep Client Detail Controls, inline callback migration, new Feedback behavior, new Custom Reply behavior, selected option changes, Memory Summary behavior changes, prompt changes, and marketplace automation out of this sprint.

## Sprint 14: Telegram Inline Callback Migration

- Add `callback_query` handling in `TelegramUpdateRouter`.
- Add `TelegramCallbackParser` for short callback data values.
- Keep `/start`, `New Client`, and `My Clients` available through the safe main reply keyboard.
- Move dynamic client and suggestion action buttons to inline keyboards.
- Support client callbacks: `cl:rs:{id}`, `cl:pa:{id}`, `cl:cl:{id}`, and `cl:sum:{id}`.
- Support start-chat callbacks: `chat:start:{id}`.
- Support suggestion callbacks: `sg:sel:{suggestion_id}:1`, `sg:sel:{suggestion_id}:2`, `sg:sel:{suggestion_id}:3`, `sg:rg:{suggestion_id}`, `sg:fb:{suggestion_id}`, and `sg:custom:{suggestion_id}`.
- Answer Telegram callback queries to stop the loading spinner.
- Check ownership for all client and suggestion callbacks.
- Keep old text-button routes working where reasonable for backward compatibility.
- Route callbacks to existing services without changing business behavior.
- Keep AI prompts, reply generation, Risk Guard, Memory Summary, Client Detail Controls, and marketplace automation out of this sprint.

## Sprint 15: Configurable Native/Target Languages + Copy-friendly Reply Formatting

- Add `.env` and config support for `NATIVE_LANGUAGE` and `TARGET_LANGUAGE`.
- Use `native_language` for bot-facing explanation sections shown to Mehrdad.
- Use `target_language` for ready-to-send client reply text.
- Update reply and feedback prompt schemas so options use `type`, `target_text`, and `native_meaning`.
- Store `target_text` in `bot_suggestion_options.body` so selected replies still save the exact client-ready text.
- Store optional `native_meaning` for bilingual display.
- Render ready-to-send reply text in Telegram HTML `<pre>` blocks for easier selection/copying.
- Escape dynamic Telegram HTML text before sending.
- Do not show duplicate native meaning when `native_language` and `target_language` are the same.
- Keep inline callbacks, selected option persistence, feedback, regenerate, Risk Guard behavior, Memory Summary behavior, controllers, and marketplace automation unchanged except for language/format integration.

## Language + Branding Polish

- Add `.env` and config support for `TARGET_PLATFORM_NAME`.
- Centralize static Telegram bot-facing messages in `CopilotMessageService`.
- Render static messages in `native_language`.
- Replace user-facing platform references with `target_platform_name`.
- Keep safety policy behavior intact while making visible platform wording dynamic where safe.
- Render Client Analysis bilingually when `NATIVE_LANGUAGE` and `TARGET_LANGUAGE` differ.
- Avoid duplicated Client Analysis output when both languages are the same.
- Keep ready-to-send reply options unchanged: `target_text` remains the saved/sent client reply and `native_meaning` remains a short translation only.
- Keep controllers, router behavior, callbacks, selected options, feedback, custom reply, regenerate, Memory Summary behavior, and marketplace automation unchanged.

## Current Implementation Status

- Completed sprints: 1 through 12, Sprint 14, and Sprint 15.
- Telegram webhook, allowed user resolution, idempotent update storage, callback query handling, and main menu are implemented.
- Client creation, client list, active client actions, chat state, sent option selection, feedback mode, custom reply mode, memory summary, Risk Guard, closing guidance, and real Regenerate are implemented within their sprint scopes.
- AI flows use `AiProviderInterface`, prompt versioning, AI request logging, masking, structured JSON validation, and queue-ready jobs.
- Reply generation uses client memory summary plus recent messages.
- Risk Guard is integrated only into reply suggestion generation.
- Dynamic Telegram action buttons use inline callbacks with ownership checks.
- Reply suggestion output supports configurable native and target languages.
- Ready-to-send reply option text is rendered in copy-friendly Telegram HTML `<pre>` blocks.
- Static Telegram bot messages are centralized and rendered with `NATIVE_LANGUAGE`.
- User-facing platform references use `TARGET_PLATFORM_NAME`.
- Client Analysis supports bilingual native/target rendering.
- Controllers remain thin.

## Manual Test Checklist

- Send `/start` from an allowed Telegram user and confirm the main menu appears.
- Send `/start` from an unauthorized Telegram user and confirm access is denied.
- Tap `New Client`, paste a job/client message, and confirm the client is created.
- Tap `My Clients` and confirm the active client is listed.
- Confirm client actions under `My Clients` appear as inline buttons.
- Start chat, paste a risky client message, and confirm a Persian high-risk warning plus safe marketplace-safe reply options.
- Confirm ready-to-send reply text appears inside copy-friendly code blocks.
- Confirm each option shows a native-language meaning only when `NATIVE_LANGUAGE` and `TARGET_LANGUAGE` differ.
- Confirm Client Analysis shows native text first and target text underneath when `NATIVE_LANGUAGE != TARGET_LANGUAGE`.
- Change `TARGET_PLATFORM_NAME` locally and confirm bot-facing platform references use the configured name.
- Confirm reply suggestion actions appear as inline buttons under the suggestion message.
- Tap `Sent Option 1` twice and confirm only one selected reply message is stored.
- Tap `Regenerate` on a generated suggestion and confirm new options appear while the old suggestion becomes regenerated.
- Tap `Regenerate` after selecting an option and confirm no regeneration happens.
- Tap `I don't like these replies` and confirm the feedback state is set.
- Tap `I wrote my own reply` and confirm the custom reply state is set.
- Tap `View Summary` before a summary exists and confirm a safe fallback message.
- Trigger two AI actions for the same client quickly and confirm the second one receives the in-progress message.

## Known Limitations

- Client Detail Controls are not implemented.
- There is no marketplace automation.
- `TARGET_PLATFORM_NAME` changes bot-facing wording and prompt context; it does not add platform automation.
- The safety layer still enforces the existing marketplace-safe policy behavior.
- The main menu still uses reply keyboard buttons by design.
- Risk Guard warns and normalizes suggestions but does not block user input.
- Memory summary updates are fail-safe; if skipped during an active AI lock, they may be refreshed by a later summary trigger.
- AI behavior depends on provider availability and valid structured JSON output.

## Later Sprints

- Add Client Detail Controls as a separate non-AI sprint.
- Add admin operations and richer reporting.
