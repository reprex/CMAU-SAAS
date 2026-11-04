# Divisions: Replicat, CrowdMerch and CrowdLink in one SaaS

Scott, 5 Nov 2026: "Eventually this SaaS will incorporate all three business divisions – Replicat,
CrowdMerch, CrowdLink. Create navigation to toggle SaaS views between these three. Update user roles to
give or deny access to staff and admin sections in each view. Some modules like contacts, Machship, FedEx
are shared between entities. We also need a business dashboard and management reporting suite to be
shared between entities."

Fits REPSTACK: one platform (modular monolith), one legal entity (the division is branding, numbering
and reporting), shared core built once (ROD To SaaS "platform-and-freight-design.md").

## Navigation
- The menu has a division switcher (Replicat · CrowdMerch · CrowdLink), showing only the divisions the
  user can open. Each division has its own menu; CrowdMerch's is everything built so far.
- A **Shared** group under every division: Dashboard, Reports, Contacts, Freight, and Users & access
  (shared admins). A shared screen keeps the division you came from.
- Replicat and CrowdLink have a home page until their modules move in (ROD To SaaS, CrowdLink).
- `config/divisions.php` maps route names to divisions; a route not listed is CrowdMerch's.

## Access (users.divisions)
- Per division, and for the shared modules: blank (no access), **View only**, **Staff** (can change) or
  **Admin** (that division's admin screens). Shared admin manages users.
- `isAdmin()` / `canEdit()` check the division of the screen in use (`SetDivision` middleware), so the
  existing `role:admin` / `role:edit` route checks now mean "admin / staff of this division".
- Opening a division the user can't use redirects to one they can (GET), else 403.
- Accounts from before (role only) keep what they had: admins are admin everywhere; sales and read-only
  have CrowdMerch and the shared modules. The migration writes that into `divisions`.

## Shared modules
- **Dashboard:** a card per division the user can open; CrowdMerch figures now (quotes, website orders,
  range), the others as their modules move in.
- **Reports:** the planned management reporting suite, each report for one division or all three: sales
  by division, quote conversion, top clients, margin, freight, debtors (Xero payments), production and
  despatch, website.
- **Contacts:** ROD Contacts is the client master (principle 5); moves in with the first Replicat module.
- **Freight:** Machship and FedEx adapters for every division.
