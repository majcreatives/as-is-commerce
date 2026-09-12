# AGENTS.md

# As-Is-Commerce — Universal AI Agent Development Protocol

**Project:** As-Is-Commerce
**Project Type:** Gamified Credit-Based E-Commerce Auction Marketplace
**Target Market:** Ghana
**Currency:** GHS / GH₵
**Current Baseline:** Stage 20
**Repository Branch:** `main`
**Repository:** `https://github.com/majcreatives/as-is-commerce.git`

---

# 1. PURPOSE OF THIS FILE

This file is the authoritative development protocol for **any AI coding agent** working on As-Is-Commerce.

It applies regardless of:

* AI provider
* AI model
* IDE
* terminal
* coding environment
* local or cloud execution environment

Examples include, but are not limited to:

* Claude
* OpenAI Codex
* Gemini
* Cursor
* Windsurf
* GitHub Copilot
* OpenCode
* other autonomous or semi-autonomous coding agents

An agent must read and follow this file before modifying the repository.

If another instruction conflicts with this file, the agent must identify the conflict rather than silently overriding established project rules.

The project owner has final authority over business requirements.

---

# 2. PROJECT STATUS

The project is currently at the **Stage 20 baseline**.

Stage 20 established the Hostinger/MariaDB deployment compatibility baseline and synchronized the local Git repository with the staging-compatible migration changes.

The current Git baseline is:

```
0bafe97 Stage 20 — Hostinger MariaDB compatibility baseline
```

The repository is hosted on GitHub and the current branch is:

```
main
```

The working tree is expected to remain clean unless the agent is actively performing approved development work.

---

# 3. SOURCE OF TRUTH

The development hierarchy is:

```
Local Repository
      ↓
    Git
      ↓
   GitHub
      ↓
```

Hostinger Staging
↓
Verification
↓
Production

### Local repository

The local repository is the primary development environment.

### GitHub

GitHub is the canonical remote Git repository and source-control history.

### Hostinger staging

Hostinger is a **deployment and verification environment**.

Hostinger is NOT the primary development environment.

Do not treat manual changes made directly on Hostinger as the source of truth.

Do not develop by editing application source directly on the server.

If a server-side emergency change is ever required, it must subsequently be reconciled into the local repository and Git history.

### Production

Production must never be changed merely because staging works.

Production deployment is a separate controlled step.

---

# 4. CURRENT DEPLOYMENT ENVIRONMENT

The current staging environment is hosted on Hostinger.

Staging domain:

```
darksalmon-swan-978886.hostingersite.com
```

Staging application directory:

```
/home/u146516859/domains/darksalmon-swan-978886.hostingersite.com/public_html/as-is-commerce-stage20
```

The application currently uses:

* Laravel 13
* PHP 8.4+
* MariaDB 11.8.x on Hostinger
* database-backed cache
* database-backed queue
* database-backed sessions
* Paystack architecture
* Redis architecture planned/used for later high-concurrency requirements

Hostinger's shell-level default PHP may differ from the application's required PHP version.

The deployment has therefore been configured to explicitly use PHP 8.4 where required.

Do not assume that:

```
php
```

and:

```
/opt/alt/php84/usr/bin/php
```

refer to the same PHP runtime on Hostinger.

Always verify the actual runtime before making deployment decisions.

---

# 5. DEVELOPMENT WORKFLOW

For normal development:

1. Inspect the repository.
2. Read `AGENTS.md`.
3. Check Git status.
4. Confirm the current branch.
5. Pull the latest relevant GitHub changes when appropriate.
6. Inspect existing implementation.
7. Understand the affected architecture.
8. Plan the change.
9. Implement the smallest appropriate change.
10. Run relevant tests.
11. Review the Git diff.
12. Commit meaningful changes.
13. Push to GitHub.
14. Deploy to staging when appropriate.
15. Verify staging.
16. Report exactly what was changed and verified.

Do not skip inspection merely because the requested change appears simple.

---

# 6. BEFORE MODIFYING THE REPOSITORY

Before changing code, the agent should inspect:

```
git status
git branch --show-current
git log --oneline -10
```

Then inspect the relevant existing implementation.

The agent must not assume:

* a feature does not exist
* a component is unused
* a database field is obsolete
* a service can be replaced
* a migration can safely be rewritten
* a business rule can be inferred from UI behavior

The existing codebase is authoritative for implementation details unless the project owner explicitly requests a change.

---

# 7. GIT SAFETY

Git is part of the project's safety system.

### Never perform without explicit approval:

```
git reset --hard
git clean -fd
git push --force
git push --force-with-lease
history rewriting
destructive branch deletion
mass file deletion
reverting unrelated commits
```

Do not destroy existing work to make a task easier.

Do not overwrite user modifications without understanding them.

Before committing:

```
git status
git diff
```

Review staged changes as well when appropriate.

### Commit discipline

Commits should:

* represent one coherent change
* have a meaningful message
* avoid unrelated modifications
* be understandable from Git history

Do not create meaningless commits such as:

```
changes
fixes
update
stuff
test
```

Prefer messages that describe the actual change.

---

# 8. BRANCHING

`main` represents the stable project baseline.

Do not use `main` as an unrestricted experimental workspace.

For substantial development work, create a focused feature branch.

Example:

```
git checkout -b feature/auction-ui-improvements
```

For bug fixes:

```
git checkout -b fix/auction-validation
```

For deployment work:

```
git checkout -b chore/hostinger-deployment
```

Small, trivial documentation-only changes may be handled differently when appropriate.

Do not create unnecessary branches for every tiny change.

---

# 9. SECRETS AND CREDENTIALS

Never commit:

* `.env`
* production `.env`
* database passwords
* Paystack secret keys
* API keys
* webhook secrets
* private keys
* SSH credentials
* access tokens
* personal credentials

Use `.env.example` for configuration documentation.

Never paste secrets into:

* Git commits
* source files
* logs
* screenshots
* test fixtures
* documentation
* AI prompts
* issue reports

If a secret is accidentally exposed, stop and report it immediately.

---

# 10. CORE ARCHITECTURE

As-Is-Commerce is a **server-authoritative modular monolith**.

Do not introduce microservices merely because a task could theoretically use them.

Prefer the existing architecture.

The system is built around:

* Laravel
* relational database
* server-side business rules
* separate financial/credit accounting
* auction state management
* Paystack payment processing
* idempotent transaction handling
* role-based administration
* controlled inventory acquisition

Architecture changes require explicit approval when they materially alter the established design.

---

# 11. BUSINESS LOGIC IS LOCKED

Business rules are not implementation suggestions.

They are product requirements.

An AI agent must not silently change business behavior because another implementation appears:

* simpler
* cleaner
* more profitable
* more conventional
* easier to code
* easier to test

If a requested implementation conflicts with an established business rule, identify the conflict and ask the project owner before changing the rule.

Do not reinterpret requirements silently.

---

# 12. AUCTION MODEL

The locked auction model is:

## Highest Valid Credit Bid

The system must not silently revert to or implement:

* Last Bidder Standing
* Lowest Unique Bid
* Highest bidder plus arbitrary additional fees
* random winner selection
* hidden bid weighting
* credit-derived fake cash auction pricing

unless the project owner explicitly changes the business model.

The authoritative auction result must be determined by the server.

The client must never be trusted as the final authority for:

* bid validity
* bid amount
* auction timing
* winner
* settlement
* credit deduction
* inventory ownership

---

# 13. AUCTION SETTLEMENT ECONOMICS

The normal auction winner pays:

1. The highest valid bid in permanently consumed credits
2. The separately configured GHS Auction Settlement Amount
3. Applicable checkout delivery and other charges

The GHS Auction Settlement Amount is:

* independently configured
* separate from Buy Now pricing
* frozen/snapshotted for the auction
* not derived from a displayed Buy Now price

Do not replace this model with a percentage of Buy Now price unless explicitly instructed.

Do not invent a "current auction cash price" based on accumulated credits.

Credits are an economic component of the auction, not a substitute for the configured GHS settlement amount.

---

# 14. BID COST

The architecture supports configurable bid cost.

The current architecture default is:

```
bid_cost_credits = 1
```

Do not assume that changing bid cost is merely a UI setting.

Bid-cost changes can affect:

* credit consumption
* auction validity
* ledgers
* winner calculation
* financial reporting
* loss-credit calculations
* tests

Treat such changes as financial/business changes.

---

# 15. AUCTION STATE MACHINE

The established auction lifecycle is:

```
DRAFT
  ↓
SCHEDULED
  ↓
LIVE
  ↓
CLOSING
  ↓
PENDING_SETTLEMENT
  ↓
SETTLED
```

Alternative outcomes include:

```
FORFEITED
  ↓
RELISTED
```

Cancellation is supported from appropriate pre-settlement states.

Do not introduce arbitrary states without understanding the existing state machine.

Auction transitions must remain server-authoritative.

---

# 16. AUCTION RULE SNAPSHOTS

Auction rules are snapshotted.

An auction must not unexpectedly inherit new global rules after it has been created if the architecture requires a frozen rule snapshot.

When changing auction configuration:

* determine whether the change affects future auctions only
* determine whether existing auctions use a snapshot
* preserve historical correctness
* never retroactively alter financial outcomes without explicit approval

---

# 17. CREDIT SYSTEM

Credits are a financial/economic instrument within the application.

Treat credits with the same care as monetary balances.

The application uses:

* credit balances
* credit lots
* credit consumption
* lot acquisition values
* auction credit consumption
* store-wallet economics
* ledger/idempotency controls

Credits must not be treated as an ordinary UI counter.

---

# 18. CREDIT LOTS

Credit valuation is lot-based.

The current Stage 16.5 implementation uses lot-based valuation rather than a flat universal credit valuation.

The relevant economic calculation uses:

```
floor(
    credits
    × lot.acquisition_amount_minor
    / lot.original_amount
)
```

Integer truncation occurs according to the established implementation.

Do not replace lot-based valuation with a flat credit price without explicit approval.

The established lot allocation behavior must be preserved.

---

# 19. CREDIT LOT ALLOCATION

The project uses controlled credit-lot allocation behavior, including:

* promotional priority where applicable
* earliest-expiry handling
* lot-specific acquisition value
* accounting-safe consumption

Do not casually reorder or simplify lot allocation.

Any change must be evaluated for its effect on:

* customer balances
* loss-credit calculations
* store-wallet issuance
* financial reporting
* historical transactions

---

# 20. STORE WALLET

Stage 16.5 introduced the store-wallet ledger.

The implementation includes:

* `store_wallets`
* append-only `store_wallet_transactions`
* unique idempotency keys
* wallet-row locking
* balance projection
* one-time issuance protection

Store-wallet issuance must remain idempotent.

The store wallet is currently associated with the defined catalogue checkout economics.

Do not automatically expand store-wallet coverage to auction Buy Now or unrelated flows without explicit product approval.

---

# 21. LOSS-CREDIT ECONOMICS

The established loss-credit/store-wallet calculation is lot-based.

The current implementation uses the acquisition value of the consumed credit lots.

Do not replace it with a flat cash-per-credit conversion.

Do not round in a way that changes the established economic result.

Any change to this area requires:

* unit tests
* edge-case tests
* idempotency tests
* review of historical implications

---

# 22. PAYMENT ARCHITECTURE

Payments use Paystack.

Payment status must be determined from authoritative server-side/payment-provider information.

Do not trust:

* browser success messages
* client-side redirects
* manipulated request parameters
* UI state

Payment processing must remain idempotent.

Webhook handling must remain safe against:

* duplicate delivery
* retries
* reordered events
* partially completed requests

---

# 23. PAYMENT CONFLICT POLICY

This is a LOCKED rule.

If:

1. Paystack payment succeeds, but
2. the single inventory unit has already been legitimately acquired by another transaction,

the successful order/payment must remain:

```
Paid
```

The fulfillment must be marked blocked and routed to the appropriate admin exception workflow.

Do NOT:

* silently cancel the order
* mark payment as failed
* invent an automatic refund
* delete the transaction
* pretend the inventory race did not happen

Refund/recovery is a dedicated workflow.

Do not alter this policy without explicit approval from the project owner.

---

# 24. ORDER LIFECYCLE

Order lifecycle logic is authoritative.

Do not bypass the existing lifecycle services/state transitions simply to make a feature work.

When modifying orders, inspect:

* order state
* payment state
* fulfillment state
* inventory ownership
* exception handling
* idempotency behavior

A UI action must not directly mutate financial state if the architecture expects a domain/service workflow.

---

# 25. INVENTORY INTEGRITY

Single-unit inventory is especially sensitive.

The application must protect against two legitimate transactions acquiring the same physical unit.

Do not solve inventory races purely at the UI level.

Inventory ownership must be protected server-side using the existing transaction/locking/state architecture.

Test race-sensitive operations where applicable.

---

# 26. IDEMPOTENCY

Idempotency is mandatory for financial and retry-prone operations.

Examples include:

* payments
* webhooks
* wallet transactions
* credit issuance
* credit consumption
* store-wallet issuance
* order lifecycle transitions
* inventory acquisition

Do not remove an idempotency key because a request "should only happen once."

The reason for idempotency is precisely that real systems retry.

---

# 27. DATABASE RULES

The application currently targets MySQL-compatible relational databases and has been specifically verified against MariaDB on Hostinger.

Hostinger currently runs MariaDB 11.8.x.

Database changes must therefore consider MariaDB compatibility.

Do not assume that MySQL-specific syntax will always work on Hostinger.

---

# 28. MIGRATION DISCIPLINE

Migrations are part of the application's history.

Do not casually rewrite migrations that have already been applied to a shared/staging/production environment.

If an already-applied schema needs changing:

Prefer a new migration.

Only modify an existing migration when the project owner explicitly approves it or when it is a controlled baseline correction that has not become part of a trusted deployed history.

Before deployment, inspect:

```
php artisan migrate:status
```

After deployment, verify migration status again.

---

# 29. MARIA DB COMPATIBILITY BASELINE

Stage 20 established MariaDB compatibility corrections.

The following migration syntax was corrected from:

```
DROP CHECK
```

to:

```
DROP CONSTRAINT
```

for MariaDB compatibility.

Affected constraints include:

```
chk_rulesets_bid_cost
chk_rulesets_checkout_price
chk_rulesets_discount_rate
```

These were syntax-level compatibility corrections.

They must not be interpreted as permission to alter the underlying business rules.

---

# 30. TESTING REQUIREMENTS

A feature is not complete simply because the UI appears to work.

Test appropriate combinations of:

### Happy path

* valid input
* successful payment
* successful bid
* successful checkout
* successful fulfillment

### Failure path

* invalid input
* insufficient credits
* failed payment
* unavailable inventory
* expired auction
* unauthorized access

### Retry path

* duplicate request
* duplicate webhook
* page refresh
* repeated checkout
* repeated bid submission where applicable

### Concurrency path

Where applicable:

* simultaneous bids
* simultaneous inventory acquisition
* payment/inventory race
* wallet race
* duplicate financial operation

### Security path

* unauthorized users
* wrong role
* forged parameters
* manipulated IDs
* direct endpoint access

---

# 31. TEST DATABASE

Be aware that test configuration must explicitly control:

* database connection
* cache driver
* queue driver
* session driver

Do not assume the test suite is using the intended database merely because PHPUnit starts successfully.

Verify test configuration when debugging database-related tests.

Never run destructive commands against a production database.

---

# 32. UI / UX DEVELOPMENT

The project has a gamified marketplace experience.

UI changes must preserve the underlying business rules.

When changing an existing page:

1. Inspect the existing implementation.
2. Identify reusable components.
3. Understand existing state handling.
4. Preserve responsive behavior.
5. Preserve authorization.
6. Preserve server-side validation.
7. Avoid duplicating existing functionality.
8. Verify the final UI.

Do not modify backend financial logic simply to make a visual change easier.

---

# 33. ADMIN SYSTEM

The application contains role-based administration.

Established roles include:

* Super Admin
* Finance Admin
* Auction Manager
* Product Manager
* Customer Support
* Fulfillment Manager
* Marketing Manager
* Fraud Analyst

Do not bypass authorization by hiding buttons.

Authorization must remain enforced server-side.

A user not seeing a button does not mean they are authorized to call the underlying endpoint.

---

# 34. ADMIN FINANCIAL ACTIONS

Administrative actions affecting:

* credits
* balances
* orders
* refunds
* payments
* inventory
* auction results

must have appropriate authorization and auditability.

Do not add an admin shortcut that directly modifies financial data without considering:

* authorization
* idempotency
* audit trail
* concurrency
* reversibility

---

# 35. SECURITY

Never weaken:

* authentication
* authorization
* CSRF protection
* validation
* server-side ownership checks
* payment verification
* webhook verification
* rate limiting
* audit logging

Do not disable security controls merely to make local testing easier.

If a security control needs to be bypassed for a development test, use a controlled test mechanism rather than changing production behavior.

---

# 36. PERFORMANCE

Do not optimize blindly.

Before changing a query or caching layer:

1. Understand the current behavior.
2. Determine whether the query is actually a bottleneck.
3. Preserve correctness.
4. Check cache invalidation.
5. Check authorization and user-specific state.
6. Test the result.

A faster incorrect financial calculation is worse than a slower correct one.

---

# 37. QUEUES AND JOBS

Queue behavior must be treated as asynchronous application logic.

When modifying jobs or queued notifications, consider:

* retries
* duplicate execution
* failure handling
* serialization
* idempotency
* queue driver differences between local and staging

Do not assume that synchronous local execution represents production queue behavior.

---

# 38. REDIS

Redis is part of the intended architecture for later high-concurrency auction behavior and queue/single-writer requirements.

Do not introduce Redis assumptions into code paths that are currently intentionally supported by database-backed infrastructure unless the project stage explicitly requires it.

The architecture should be prepared for Redis without making staging deployment dependent on unavailable infrastructure unnecessarily.

---

# 39. AUCTION CONCURRENCY

Auction bidding is server-authoritative.

The architecture anticipates a high-concurrency bid path involving:

* authoritative server time
* transactional consistency
* wallet/credit locking
* idempotency
* later Redis single-writer processing
* controlled/coalesced broadcasts

Do not implement client-side timing as the authoritative auction clock.

Do not let browser timestamps determine winners.

---

# 40. DELIVERY

Delivery is a checkout concern and must remain separate from the auction's configured settlement amount.

The platform may eventually integrate third-party delivery providers such as Bolt, Uber or Yango.

Do not assume delivery pricing or availability without verifying the actual provider API and project requirements.

For geographic delivery restrictions, preserve server-side validation.

Do not rely only on a frontend map/radius check.

---

# 41. PRODUCT / CATALOGUE

Catalogue products and auction products are related but must not be treated as identical flows.

A product may support:

* Buy Now
* Auction
* both

depending on its configuration.

Do not assume that a Buy Now price determines the auction settlement amount.

Do not automatically copy catalogue pricing into auction financial calculations.

---

# 42. EXPERIMENTATION

The project intentionally contains gamification and micro-transaction concepts.

Potential future experiments may include:

* auction voting
* auction discovery
* participation mechanics
* credit packages
* engagement features
* promotional incentives

However:

**Increasing revenue is not permission to violate transparency, financial integrity, user consent, or the locked accounting model.**

Do not implement hidden charges.

Do not misrepresent prices.

Do not manipulate financial records.

Do not disguise a monetary transaction as something it is not.

Experiments must remain compatible with the project's accounting and legal requirements.

---

# 43. DO NOT OVER-ENGINEER

Do not introduce:

* unnecessary services
* unnecessary abstractions
* unnecessary packages
* microservices
* duplicate repositories
* duplicate state machines
* duplicate validation systems

unless there is a demonstrated requirement.

Prefer the smallest change that correctly fits the existing architecture.

---

# 44. DO NOT UNDER-ENGINEER FINANCIAL FLOWS

The opposite rule also applies.

Do not simplify:

* payment handling
* wallet handling
* credit accounting
* auction settlement
* inventory ownership
* order state transitions

merely because the simpler implementation is shorter.

Financial correctness takes priority over implementation convenience.

---

# 45. WHEN A REQUEST IS AMBIGUOUS

Use this decision process:

### Minor implementation ambiguity

Use the existing architecture and conventions.

### Product/UI ambiguity

Make the least disruptive interpretation and clearly report the assumption.

### Financial ambiguity

STOP and ask.

### Auction-rule ambiguity

STOP and ask.

### Inventory ownership ambiguity

STOP and ask.

### Security ambiguity

STOP and ask.

### Architecture ambiguity

Inspect existing architecture first. If the choice materially changes architecture, ask before proceeding.

---

# 46. NEVER CLAIM UNVERIFIED WORK

An AI agent must never claim:

* "tested"
* "deployed"
* "verified"
* "working"
* "fixed"
* "migration completed"
* "payment confirmed"

unless it actually performed the appropriate verification.

If something could not be tested, say:

```
Not tested.
```

If something was inferred rather than verified, say:

```
Not verified; inferred from the current implementation.
```

Accuracy of the development report is mandatory.

---

# 47. DEPLOYMENT TO HOSTINGER

Hostinger staging is a verification environment.

Before deployment:

1. Confirm Git status.
2. Confirm branch.
3. Review diff.
4. Commit approved changes.
5. Push to GitHub.
6. Deploy the intended commit to staging.
7. Run required dependency/database commands.
8. Verify migrations.
9. Clear/rebuild appropriate caches.
10. Test the changed functionality.
11. Check logs where necessary.

Do not deploy uncommitted experimental work unless explicitly instructed.

---

# 48. PRODUCTION DEPLOYMENT

Production deployment requires explicit project-owner approval.

Do not infer production approval from:

* "staging works"
* "looks good"
* "tests passed"
* "push it"
* "deploy"

unless the context clearly identifies production deployment.

Production deployment must be a deliberate step.

---

# 49. HOSTINGER SERVER CHANGES

Manual Hostinger changes can be necessary for infrastructure configuration.

Examples may include:

* permissions
* `.htaccess`
* PHP runtime selection
* server configuration
* deployment commands

These are not automatically application-source changes.

If a server change affects application behavior, document it.

If it should be reproducible, capture the configuration in the project's deployment documentation or scripts where appropriate.

---

# 50. FILE PERMISSIONS

Do not blindly run recursive permission changes across the entire application.

When repairing permissions:

* understand the affected path
* modify only what is necessary
* preserve executable requirements
* avoid making secrets world-readable
* avoid making application files writable unnecessarily

---

# 51. DOCUMENTATION

When an architectural or deployment decision is important enough to affect future agents, document it.

Do not rely on one AI conversation as the only source of project knowledge.

Important knowledge should live in:

* `AGENTS.md`
* appropriate project documentation
* code comments where genuinely necessary
* migration history
* Git commit history
* deployment documentation

---

# 52. AI AGENT BEHAVIOR

The AI agent is an implementation assistant, not the project owner.

The agent should:

* inspect before modifying
* explain significant assumptions
* preserve existing work
* identify conflicts
* avoid unnecessary rewrites
* test changes
* report truthfully
* keep changes focused

The agent should NOT:

* invent requirements
* silently change economics
* silently change auction mechanics
* silently change payment policy
* silently alter financial calculations
* delete existing functionality
* rewrite large portions of the application unnecessarily
* claim success without verification

---

# 53. CHANGE REVIEW CHECKLIST

Before considering a substantial task complete, verify:

## Code

* [ ] Existing implementation inspected
* [ ] No unrelated files changed
* [ ] No unnecessary dependencies added
* [ ] No secrets added

## Business logic

* [ ] Auction model preserved
* [ ] Settlement economics preserved
* [ ] Credit economics preserved
* [ ] Payment conflict policy preserved
* [ ] Inventory integrity preserved

## Database

* [ ] Migration reviewed
* [ ] MariaDB compatibility considered
* [ ] Existing data safety considered
* [ ] Migration status verified where applicable

## Security

* [ ] Authorization preserved
* [ ] Server-side validation preserved
* [ ] Sensitive data protected

## Testing

* [ ] Relevant tests run
* [ ] Failure paths considered
* [ ] Idempotency considered
* [ ] Concurrency considered where relevant

## Git

* [ ] `git status` reviewed
* [ ] `git diff` reviewed
* [ ] Commit is focused
* [ ] No secrets committed
* [ ] Correct branch used

## Deployment

* [ ] Staging deployment performed if required
* [ ] Staging behavior verified
* [ ] Production not changed without approval

---

# 54. COMPLETION REPORT FORMAT

For substantial tasks, the agent should finish with a concise report using this structure:

```
## Completed

- [change]
- [change]
- [change]

## Files Changed

- [file]
- [file]

## Tests

- [test/command]
- [result]

## Database

- [migration/schema change]
- [status]

## Deployment

- Local: [status]
- GitHub: [status]
- Staging: [status]
- Production: [status]

## Risks / Notes

- [remaining issue]
- [assumption]
- [follow-up]
```

Do not report a status that was not actually verified.

---

# 55. CURRENT STAGE 20 BASELINE

The current repository baseline includes the Stage 20 Hostinger/MariaDB compatibility work.

The current known Git state is:

```
main
origin/main
0bafe97 Stage 20 — Hostinger MariaDB compatibility baseline
```

The repository should be treated as stable at this baseline unless the agent is performing an explicitly requested change.

Future work should build on this state rather than reconstructing earlier stages.

---

# 56. STAGE 16.5 ECONOMIC BASELINE

Stage 16.5 established important financial infrastructure.

This includes:

* Store Wallet ledger
* append-only store-wallet transactions
* unique idempotency keys
* wallet-row locking
* projected wallet balances
* lot-based credit valuation
* snapshot versioning
* migration handling for prior valuation models
* controlled catalogue checkout coverage
* order-lifecycle-based releases
* bid cooldown configuration

These systems are part of the current architecture.

Do not regress them when implementing later features.

---

# 57. HISTORICAL COMPATIBILITY

When modifying existing functionality, consider that the application may contain:

* existing users
* existing credits
* existing credit lots
* existing orders
* existing auctions
* existing payment records
* existing wallet transactions
* existing snapshots

Do not write new code that only works for freshly seeded data if existing data may exist.

Backward compatibility should be evaluated whenever data structures change.

---

# 58. SEEDERS

Seeders may create:

* roles
* permissions
* settings
* auction rulesets
* credit packages
* catalogue products

Do not assume that seeders should create fake auctions unless explicitly designed to do so.

Seed data must not accidentally be mistaken for real financial activity.

When changing seeders, understand whether they are:

* development-only
* staging bootstrap
* production-safe
* idempotent

---

# 59. ADMIN ACCOUNT CREATION

Administrative accounts must be created using the application's intended administrative mechanisms.

Do not insert admin users directly into the database unless explicitly required for recovery/debugging.

Do not weaken role/permission checks to make administration easier.

---

# 60. VISUAL / STAGING VERIFICATION

When a task changes the UI, visual verification should be performed where practical.

Verify:

* desktop
* mobile/responsive behavior
* empty states
* validation states
* error states
* loading states
* authorization states
* successful states

Do not consider a UI task complete merely because the underlying PHP/JavaScript compiles.

---

# 61. PRINCIPLE OF MINIMAL CHANGE

When implementing a requested change:

> Change what is necessary. Preserve what already works.

Do not turn a focused UI change into a system-wide refactor.

Do not refactor unrelated code merely because it could be cleaner.

If a refactor is genuinely necessary, explain why before expanding scope.

---

# 62. PRINCIPLE OF EXPLICITNESS

If a decision could materially affect:

* money
* credits
* inventory
* payments
* users
* auctions
* security
* database integrity

make the decision explicit.

Do not hide important behavior inside:

* magic constants
* undocumented assumptions
* implicit side effects
* silent fallbacks

---

# 63. FINAL GOLDEN RULE

When uncertain:

```
Inspect first.

Preserve existing work.

Follow the current architecture.

Do not guess about business logic.

Do not silently change economics.

Do not silently change auction rules.

Do not silently change payment behavior.

Do not destroy data.

Do not expose secrets.

Test before claiming success.

Keep Git history clean.

Keep Hostinger as staging, not the source of truth.

Ask the project owner when an important decision is genuinely ambiguous.
```

The goal is not merely to make code run.

The goal is to evolve As-Is-Commerce without breaking the business model,
financial integrity, historical correctness, security, or architectural
direction established by the project owner.
