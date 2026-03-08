# FriendShyft Roadmap

This roadmap captures the big-picture plan for growing the plugin while keeping space for a future Donor Management module. Dates are illustrative; order reflects dependency and impact.

## Guiding Outcomes
- Reduce volunteer friction (discover → onboard → schedule → show up → feel impact).
- Improve nonprofit operational clarity (capacity, staffing gaps, reporting).
- Build scalable foundations for integrations and future donor management.

## Phase 1: Foundation (0–3 months)
**Goal:** Stabilize core workflows and make the plugin production-solid.
- Core volunteer journey: registration, portal, scheduling, check-in/out, notifications.
- Admin reliability: data integrity, migration safety, audit logging, role/capability checks.
- Reporting basics: CSV exports, activity summaries, time tracking accuracy.
- Testing: expand PHPUnit coverage for core CRUD, signup logic, and time tracking.
- Docs: setup, admin usage, and troubleshooting guides.
- Donor Management placeholder: reserve admin menu slot, capability namespace, and reporting hooks.

## Phase 2: Growth (3–6 months)
**Goal:** Increase adoption and retention through smarter workflows.
- Smart matching v1: rank opportunities by interests, skills, availability.
- Advanced scheduling: waitlists, substitutes, recurring availability, auto-signup tuning.
- Enhanced analytics: retention dashboards, engagement trends, impact summaries.
- Integrations polish: Google Calendar, Monday.com; add webhook scaffolding for CRM sync.
- Volunteer communications: templated reminders and segmentation by program/role.

## Phase 3: Scale (6–12 months)
**Goal:** Support larger programs with better automation and visibility.
- Mobile/PWA experience with offline check-in and push-ready architecture.
- Outcome tracking beyond hours (program outcomes, people served, units delivered).
- Automated workflows: onboarding sequences, training completions, periodic check-ins.
- Advanced forecasting: fill-rate predictions, staffing gap alerts, no-show risk.
- Donor Management module shell: empty UI, schema namespace, permissions, and report links.

## Phase 4: Expansion (12+ months)
**Goal:** Make FriendShyft a full volunteer + nonprofit growth platform.
- AI-assisted staffing optimization and proactive opportunity creation.
- Multi-channel messaging (SMS, Slack/Teams) with unified templates.
- Corporate and group volunteering support with team-based reporting.
- Background check and compliance integrations.
- Donor Management feature build-out (separate roadmap when ready).

## Donor Management Placeholder (Reserved Space)
This plugin will explicitly preserve room for donor management by:
- Keeping a dedicated admin menu slot (placeholder only).
- Reserving capability names (for example, `manage_donors`).
- Designing reporting exports to accept donor metrics when added.
- Avoiding data model collisions by preferring `fs_donor_*` table namespaces.
