# HM Case Study API — WordPress Plugin

> **Plugin Name:** HM Case Study API
> **Package:** `hm/case-study-api`
> **Version:** 1.0.0
> **PHP Namespace:** `CSP\`
> **Autoloading:** PSR-4 via Composer (`src/` → `CSP\`)
> **WP Entry Hook:** `plugins_loaded`
> **REST API Base URL:** `/wp-json/csp/v1`

---

## Overview

A WordPress plugin that exposes a complete **REST API backend** for a Case Study management platform. The plugin registers a Custom Post Type (`hm_case`), six custom taxonomies, custom user roles, a notifications database table, and a full set of authenticated REST endpoints consumed by a standalone **Vue 3 SPA**.

**The plugin has zero frontend rendering responsibility.** All UI is handled by the SPA.

### Core Architectural Principle

> The `hm_case` CPT is the single source of truth. Gravity Forms is used **only** as a form schema registry — it never stores submission data. All persistent case data lives exclusively in `post_meta` and WP taxonomies.

---

## Table of Contents

1. [Architecture & Design Patterns](#1-architecture--design-patterns)
2. [File & Folder Structure](#2-file--folder-structure)
3. [Bootstrap & Dependency Injection](#3-bootstrap--dependency-injection)
4. [User Roles & Hierarchy](#4-user-roles--hierarchy)
5. [Custom Post Type: `hm_case`](#5-custom-post-type-hm_case)
6. [Case Statuses & Lifecycle](#6-case-statuses--lifecycle)
7. [Taxonomies](#7-taxonomies)
8. [FormFieldMap — Master Configuration](#8-formfieldmap--master-configuration)
9. [Middleware Pipeline](#9-middleware-pipeline)
10. [REST API Endpoints](#10-rest-api-endpoints)
11. [Services Layer](#11-services-layer)
12. [Repositories Layer](#12-repositories-layer)
13. [DTO & Response Layer](#13-dto--response-layer)
14. [Notifications System](#14-notifications-system)
15. [Database Schema](#15-database-schema)
16. [Post Meta Schema](#16-post-meta-schema)
17. [User Meta Schema](#17-user-meta-schema)
18. [Access Control Matrix](#18-access-control-matrix)
19. [Hooks](#19-hooks)
20. [Exception Handling](#20-exception-handling)
21. [Extension Guide](#21-extension-guide)

---

## 1. Architecture & Design Patterns

| Pattern | Implementation |
|---|---|
| **Dependency Injection Container** | `src/Core/Container.php` — lightweight IoC with `bind()` / `singleton()` / `get()` |
| **Repository Pattern** | `CaseRepository`, `UserRepository`, `NotificationRepository` abstract all WP/DB queries |
| **Service Layer** | All business logic lives in `src/Services/` — controllers are thin dispatchers only |
| **DTO / Mapper** | `DTOMapper` transforms internal data structures into clean API response shapes |
| **Middleware Pipeline** | `MiddlewarePipeline` chains middlewares via `array_reduce` — composable and ordered |
| **Config-Driven Extension** | `FormFieldMap.php` is the single file to edit for field/taxonomy/meta/display changes |
| **Value Object (Status)** | `CaseStatus` is a `final` class of string constants — single source for all status slugs |
| **Declarative Transitions** | `StatusTransitions.php` maps `current_status → action → next_status` as a pure config array |

**`declare(strict_types=1)`** is enforced in every PHP source file.

---

## 2. File & Folder Structure

```
hm-case-study-api/
│
├── hm-case-study-api.php           # Plugin entry point — registers plugins_loaded hook
├── composer.json                   # PSR-4: "CSP\\" => "src/"
│
├── src/
│   │
│   ├── Core/
│   │   ├── Plugin.php              # Main orchestrator: registers all DI bindings, boots all modules
│   │   └── Container.php           # Lightweight IoC DI container (bind / singleton / get)
│   │
│   ├── API/
│   │   ├── Router.php              # Registers all REST routes via register_rest_route() on rest_api_init
│   │   │
│   │   ├── Controllers/
│   │   │   ├── CaseController.php        # Cases: CRUD, form data sync, all status transition actions
│   │   │   ├── FormController.php        # Form schema proxy — transforms GF schema for SPA consumption
│   │   │   ├── DashboardController.php   # Stats counters + filter option lists (role-scoped)
│   │   │   ├── UserController.php        # User listing (admin: all; manager: own agents only)
│   │   │   └── NotificationController.php # Notification list / read / unread-count
│   │   │
│   │   ├── Middleware/
│   │   │   ├── MiddlewarePipeline.php    # array_reduce chain executor
│   │   │   ├── SanitizeMiddleware.php    # Recursive wp_kses_post() on all string params
│   │   │   ├── AuthMiddleware.php        # is_user_logged_in() guard → 401
│   │   │   └── PermissionMiddleware.php  # current_user_can('read') baseline → 403
│   │   │
│   │   └── Responses/
│   │       ├── ApiResponse.php           # Static success() / error() WP_REST_Response builders
│   │       └── ErrorCodes.php            # String constants: CSP_FORBIDDEN, CSP_NOT_FOUND, etc.
│   │
│   ├── Services/
│   │   ├── CaseService.php               # Case creation, retrieval, meta hydration, supervisor resolution
│   │   ├── CaseFormDataService.php       # Partial save, progress calculation, sync_on:'always' processing
│   │   ├── CaseStatusService.php         # All status transitions + notification dispatch
│   │   ├── CasePermissionService.php     # All ACL checks: canView/canEdit/canDelete/canApprove etc.
│   │   ├── GravityFormsService.php       # GFAPI::get_form() → clean schema DTO (read-only GF usage)
│   │   ├── TaxonomyService.php           # Auto-creates terms + assigns via wp_set_object_terms()
│   │   ├── NotificationService.php       # DB insert + wp_mail() dual-channel dispatch
│   │   └── UserService.php               # Rebuilds _assigned_agent_ids inverse index
│   │
│   ├── Repositories/
│   │   ├── CaseRepository.php            # WP_Query abstraction with dynamic tax/date/author filters
│   │   ├── UserRepository.php            # WP_User_Query abstraction with role/status/search
│   │   └── NotificationRepository.php    # Raw $wpdb queries on {prefix}csp_notifications
│   │
│   ├── DTO/
│   │   └── DTOMapper.php                 # toCaseListItem / toCaseDetail / toUser / toNotification
│   │
│   ├── Config/
│   │   ├── FormFieldMap.php              # ⭐ MASTER CONFIG: field_id → storage + display flags
│   │   └── StatusTransitions.php         # Valid status transitions map: status → action → next_status
│   │
│   ├── Domain/
│   │   └── Case/
│   │       └── CaseStatus.php            # final class with DRAFT/IN_REVIEW/RETURNED/APPROVED/REJECTED
│   │
│   ├── PostTypes/
│   │   └── CasePostType.php              # Registers hm_case CPT + 4 custom post statuses
│   │
│   ├── Taxonomies/
│   │   ├── AbstractTaxonomy.php          # Base register() — all taxonomy classes extend this
│   │   ├── ProductTypeTaxonomy.php       # slug: hm_product_type
│   │   ├── IndustrySegmentTaxonomy.php   # slug: hm_industry_segment
│   │   ├── MachineTypeTaxonomy.php       # slug: hm_machine_type
│   │   ├── MachineMakeTaxonomy.php       # slug: hm_machine_make
│   │   ├── ToolBrandTaxonomy.php         # slug: hm_tool_brand
│   │   └── SolutionTypeTaxonomy.php      # slug: hm_solution_type
│   │
│   ├── Roles/
│   │   └── RoleManager.php               # add_role() + add_cap() for all custom roles
│   │
│   ├── Database/
│   │   └── Migrations.php                # dbDelta() CREATE TABLE for csp_notifications
│   │
│   ├── Hooks/
│   │   └── UserHooks.php                 # Listens to user meta changes → rebuilds agent↔manager index
│   │
│   └── Exceptions/
│       ├── ApiException.php              # RuntimeException with errorCode + httpStatus + data
│       ├── ValidationException.php
│       └── PermissionException.php
│
└── vendor/                               # Composer autoloader (generated, not committed)
```

---

## 3. Bootstrap & Dependency Injection

### Entry Point (`hm-case-study-api.php`)

```php
add_action('plugins_loaded', function () {
    (new Plugin())->init();
});
```

### `Plugin::init()` Boot Sequence

1. **`registerBindings()`** — registers all singletons and factory bindings in the `Container`
2. **`boot()`** — resolves and starts active components:
    - `Router::register()` — attaches `rest_api_init` hook
    - `UserHooks::register()` — attaches user meta hooks
    - On WP `init` hook: registers CPT, roles, and all 6 taxonomies

### IoC Container (`src/Core/Container.php`)

A minimal dependency injection container:

| Method | Behaviour |
|---|---|
| `bind(abstract, factory)` | Factory called on every `get()` — new instance each time |
| `singleton(abstract, factory)` | Factory called once; result cached in `$instances[]` |
| `get(abstract)` | Resolves binding → falls back to `new $abstract()` if class exists → throws if neither |

All controllers, services, and repositories are registered as **singletons**. Constructor dependencies are injected explicitly in factory closures — no reflection magic.

**Dependency graph (key chains):**

```
CaseController
  ├── CaseService
  ├── CaseFormDataService → CaseService
  ├── CaseStatusService → CaseService + CasePermissionService + TaxonomyService + NotificationService
  ├── CasePermissionService → CaseService
  ├── CaseRepository
  └── DTOMapper

DashboardController → CaseRepository
UserController → UserRepository + DTOMapper
NotificationController → NotificationRepository + DTOMapper
FormController → GravityFormsService
```

---

## 4. User Roles & Hierarchy

### Role Slugs

| Role | WP Slug | Description |
|---|---|---|
| Super Admin | `administrator` | Full system access; own cases auto-approved on submit |
| Manager | `hm_manager` | Reviews subordinate agent cases; can create own cases |
| Field Agent | `hm_field_agent` | Creates and submits case studies |
| Marketing | `hm_marketing` | Read-only access to all cases and the library |

### Hierarchy

```
administrator
    └── hm_manager  (1 or many per administrator)
            └── hm_field_agent  (1 or many per manager)
```

### Role–Agent Relationship (stored as User Meta)

- `_assigned_manager_id` (on `hm_field_agent`) — ID of the agent's assigned manager
- `_assigned_agent_ids` (on `hm_manager`) — JSON array of subordinate agent IDs, **automatically rebuilt** by `UserHooks` whenever `_assigned_manager_id` changes on any user

### WordPress Capabilities (`RoleManager.php`)

Fine-grained ownership and workflow checks are handled by `CasePermissionService` at the API layer. WP capabilities serve as a coarse baseline.

| Capability | administrator | hm_manager | hm_field_agent | hm_marketing |
|---|---|---|---|---|
| `create_hm_cases` | ✅ | ✅ | ✅ | — |
| `edit_hm_cases` | ✅ | ✅ | ✅ | — |
| `edit_others_hm_cases` | ✅ | ✅ | — | — |
| `delete_hm_cases` | ✅ | — | — | — |
| `read_private_hm_cases` | ✅ | ✅ | — | ✅ |

---

## 5. Custom Post Type: `hm_case`

**Registered slug:** `hm_case` (via `CasePostType::POST_TYPE` constant)

| Property | Value |
|---|---|
| `public` | `false` |
| `show_ui` | `true` |
| `show_in_rest` | `true` (WP admin REST, not the custom API) |
| `rest_base` | `hm-cases` |
| `supports` | `author`, `title`, `custom-fields` |
| `capability_type` | `['hm_case', 'hm_cases']` |
| `map_meta_cap` | `true` |
| `rewrite` | `false` |

### Custom Post Statuses

Registered via `register_post_status()` with `protected: true`, `show_in_admin_all_list: true`:

| Slug | Label |
|---|---|
| `draft` | (WP native) |
| `in_review` | In Review |
| `returned` | Returned |
| `approved` | Approved |
| `rejected` | Rejected |

---

## 6. Case Statuses & Lifecycle

### Status Value Object (`src/Domain/Case/CaseStatus.php`)

```php
final class CaseStatus {
    const DRAFT     = 'draft';
    const IN_REVIEW = 'in_review';
    const RETURNED  = 'returned';
    const APPROVED  = 'approved';
    const REJECTED  = 'rejected';
}
```

### Transition Map (`src/Config/StatusTransitions.php`)

```
DRAFT      ──[submit]──► IN_REVIEW   (or APPROVED if author = administrator)
IN_REVIEW  ──[approve]─► APPROVED
IN_REVIEW  ──[reject]──► REJECTED
IN_REVIEW  ──[return]──► RETURNED
RETURNED   ──[submit]──► IN_REVIEW
RETURNED   ──[approve]─► APPROVED    (manager can approve returned cases)
RETURNED   ──[reject]──► REJECTED
APPROVED   ── (no normal transitions; superadmin override only)
REJECTED   ── (no normal transitions; superadmin override only)
```

### Reviewer Auto-Assignment

Determined at case **creation** by `CaseService::determineSupervisorId()` and stored in `supervisor_id` meta:

| Case Author | `supervisor_id` set to |
|---|---|
| `hm_field_agent` | Their `_assigned_manager_id` |
| `hm_manager` | First available `administrator` user |
| `administrator` | `null` — auto-approved on submit |

### Complete Case Creation Flow

1. SPA calls `GET /forms/{id}/schema` → receives full schema, determines `total_steps`
2. SPA calls `POST /cases` with `{ form_id, total_steps }` → plugin creates `hm_case` post (status: `draft`), writes all initial meta, returns `case_id`
3. Post title initialised as `Case #<ID>`; updates to `<CustomerName> #<ID>` when field `100` receives a value on any partial save
4. User fills form; SPA calls `PATCH /cases/{id}/form-data` on each step advance — fields merged into `hm_form_data` JSON
5. Final submit: `POST /cases/{id}/submit` → syncs taxonomies, triggers status transition, dispatches notifications

---

## 7. Taxonomies

All taxonomies extend `AbstractTaxonomy` which calls `register_taxonomy()` attached to the `hm_case` post type. All registered with `public: false`, `show_ui: true`, `hierarchical: true`.

| Class | Taxonomy Slug | Label |
|---|---|---|
| `ProductTypeTaxonomy` | `hm_product_type` | Product Type |
| `IndustrySegmentTaxonomy` | `hm_industry_segment` | Industry Segment |
| `MachineTypeTaxonomy` | `hm_machine_type` | Machine Type |
| `MachineMakeTaxonomy` | `hm_machine_make` | Machine Make |
| `ToolBrandTaxonomy` | `hm_tool_brand` | Tool Brand |
| `SolutionTypeTaxonomy` | `hm_solution_type` | Solution Type |

**Terms are auto-created on case submit** by `TaxonomyService::syncTaxonomies()` — if the submitted value doesn't exist as a term, `wp_insert_term()` creates it automatically before assignment.

---

## 8. FormFieldMap — Master Configuration

**File:** `src/Config/FormFieldMap.php`

This is the **single extension point** for all field-to-backend mappings. Every service, repository, and DTO mapper reads this config at runtime via `require`. Adding a new filterable field, taxonomy, list column, or card field requires editing **only this one file**.

### Entry Schema

```php
[
    'field_id'  => 7,                        // Gravity Forms field ID (int, or "17.1" for checkbox sub-inputs)
    'gf_type'   => 'radio',                  // GF field type — for reference only
    'label'     => 'Product Type',           // Human-readable label used in API responses
    'storage'   => [
        'taxonomy' => 'hm_product_type',     // If set: value assigns a WP taxonomy term on submit
        'meta_key' => null,                  // If set: value written to this post_meta key
    ],
    'display'   => [
        'in_list'    => true,                // Include as a column in case list API responses
        'in_card'    => true,                // Include in case detail (card) API responses
        'in_filters' => true,                // Expose in /dashboard/filters; queryable in GET /cases
    ],
    'sync_on'   => 'submit',                 // 'always': sync on every PATCH; 'submit': only on final submit
]
```

### Current Field Map

| field_id | Label | Storage | in_list | in_card | in_filters | sync_on |
|---|---|---|---|---|---|---|
| 7 | Product Type | taxonomy: `hm_product_type` | ✅ | ✅ | ✅ | submit |
| 8 | Industry Segment | taxonomy: `hm_industry_segment` | ✅ | ✅ | ✅ | submit |
| 126 | Machine Type | taxonomy: `hm_machine_type` | ✅ | ✅ | ✅ | submit |
| 227 | Machine Make | taxonomy: `hm_machine_make` | ✅ | ✅ | ✅ | submit |
| 229 | Tool Brand | taxonomy: `hm_tool_brand` | ✅ | ✅ | ✅ | submit |
| 20 | Solution Type | taxonomy: `hm_solution_type` | ✅ | ✅ | ✅ | submit |
| 100 | Customer Name | meta: `_case_customer_name` | — | ✅ | — | **always** |
| 99 | Customer ID | meta: `_case_customer_id` | — | — | — | always |
| 2 | City | meta: `_case_city` | — | ✅ | — | submit |
| 4 | State | meta: `_case_state` | — | ✅ | — | submit |
| 138 | Insert Specification | meta: `_case_insert_specification` | — | ✅ | — | submit |
| 137 | Tool Specification | meta: `_case_tool_specification` | — | ✅ | — | submit |
| 201 | Total Cost Savings | meta: `_case_total_cost_savings` | — | ✅ | — | submit |
| 66 | Down Time Savings | meta: `_case_down_time_savings` | — | ✅ | — | submit |
| 67 | Cycle Time Savings | meta: `_case_cycle_time_savings` | — | ✅ | — | submit |

### `sync_on: 'always'` behaviour

On every `PATCH /cases/{id}/form-data`, `CaseFormDataService` iterates the map and processes entries with `sync_on: 'always'`. Field `100` (Customer Name) is the **title-driver** — when non-empty, the post title is immediately updated to `{CustomerName} #<ID>`.

### `sync_on: 'submit'` behaviour

Taxonomy assignments and submit-only meta keys are written by `TaxonomyService::syncTaxonomies()` called inside `CaseStatusService::submit()`.

### How FormFieldMap drives each subsystem

| Subsystem | Reads from map | Effect |
|---|---|---|
| `CaseFormDataService` | `sync_on`, `storage.meta_key` | Writes meta on partial save (`always`) or final submit (`submit`) |
| `TaxonomyService` | `storage.taxonomy` | Auto-creates term + assigns to case on submit |
| `CaseRepository` | `storage.taxonomy` | Builds dynamic `tax_query` entries for `GET /cases` filters |
| `DashboardController` | (taxonomy slugs hardcoded for now) | Returns filter options for product_type and industry_segment |
| `DTOMapper` | `display.in_list`, `display.in_card`, `storage.*` | Appends dynamic fields to list/detail API response shapes |

---

## 9. Middleware Pipeline

Every request to every route passes through a pipeline assembled in `Router::handle()`:

```
WP_REST_Request
      │
      ▼
SanitizeMiddleware    →  Recursive wp_kses_post() on all string params
      │
      ▼
AuthMiddleware        →  is_user_logged_in() check  →  401 CSP_UNAUTHORIZED if not
      │
      ▼
PermissionMiddleware  →  current_user_can('read') check  →  403 CSP_FORBIDDEN if not
      │
      ▼
Controller::method()  →  fine-grained ACL via CasePermissionService
```

The pipeline uses `array_reduce` over the **reversed** middlewares array so the first-piped middleware runs outermost (first in, last out wrapping). `ApiException` thrown anywhere in the chain is caught by `Router::handle()` and converted to `ApiResponse::error()`. All other `\Throwable` produce a 500 `CSP_INTERNAL_ERROR`.

The base `permission_callback` on every `register_rest_route()` call is `is_user_logged_in()` — this acts as a WordPress-level gate before the pipeline even starts.

---

## 10. REST API Endpoints

**Base URL:** `/wp-json/csp/v1`
**Authentication:** WordPress cookie + `X-WP-Nonce` header injected via `wp_localize_script()`

### Standard Response Envelope

```json
// Success
{
  "success": true,
  "data": { ... },
  "message": "",
  "meta": { "total": 42, "page": 1, "per_page": 20, "total_pages": 3 }
}

// Error
{
  "success": false,
  "code": "CSP_FORBIDDEN",
  "message": "You do not have permission to perform this action.",
  "data": null
}
```

### Error Code Constants (`ErrorCodes.php`)

| Constant | String Value |
|---|---|
| `UNAUTHORIZED` | `CSP_UNAUTHORIZED` |
| `FORBIDDEN` | `CSP_FORBIDDEN` |
| `NOT_FOUND` | `CSP_NOT_FOUND` |
| `VALIDATION_ERROR` | `CSP_VALIDATION_ERROR` |
| `BAD_REQUEST` | `CSP_BAD_REQUEST` |
| `INTERNAL_ERROR` | `CSP_INTERNAL_ERROR` |

---

### 10.1 Form Schema

| Method | Endpoint | Controller | Description |
|---|---|---|---|
| `GET` | `/forms/{id}/schema` | `FormController::getSchema` | Returns full GF form schema as a clean SPA-ready DTO |

**Response `data`:**

```json
{
  "form_id": 4,
  "form_title": "Case Study",
  "total_steps": 6,
  "non_data_field_types": ["page", "section", "html"],
  "steps": [
    {
      "step_number": 1,
      "label": "",
      "fields": [
        {
          "id": 100, "type": "text", "label": "Customer Name",
          "is_required": false, "is_hidden": false, "visibility": "visible",
          "placeholder": "Start typing...", "css_class": "csp-client-autocomplete",
          "choices": null, "conditional_logic": null,
          "validation": { "is_required": false, "max_length": "" },
          "defaultValue": "", "description": "", "adminLabel": ""
        }
      ]
    }
  ]
}
```

Steps are detected dynamically: `type: "page"` GF fields act as step boundaries. Total step count is **never hardcoded** in the backend.

For calculation fields, the response also includes:
```json
"enableCalculation": true,
"calculationFormula": "...",
"calculation": { "formula": "...", "rounding": "", "referencedFields": ["5", "6"] }
```

For checkbox/radio/select fields, `inputs[]` (sub-input IDs like `17.1`, `17.2`) are included when present.

---

### 10.2 Cases — List & Create

| Method | Endpoint | Controller | Description |
|---|---|---|---|
| `GET` | `/cases` | `CaseController::index` | List cases, role-scoped, with filters & pagination |
| `POST` | `/cases` | `CaseController::create` | Create a new draft case |

**Role scoping in `GET /cases`:**

| Role | Visible cases |
|---|---|
| `administrator` / `hm_marketing` | All cases from all users |
| `hm_manager` | Own cases + all cases by assigned agents (`_assigned_agent_ids`) |
| `hm_field_agent` | Own cases only |

**`GET /cases` Query Parameters:**

| Param | Type | Description |
|---|---|---|
| `status` | string / csv | `draft`, `in_review`, `returned`, `approved`, `rejected` or comma-separated list |
| `product_type` | string | Taxonomy slug (dynamic — any taxonomy in FormFieldMap with `in_filters: true`) |
| `industry_segment` | string | Taxonomy slug |
| `submitted_by` | int | Filter by author user ID |
| `date_from` | string | ISO date — inclusive lower bound on `post_date` |
| `date_to` | string | ISO date — inclusive upper bound on `post_date` |
| `search` | string | WP `s` search on post title (which contains customer name per title-update logic) |
| `page` | int | Default: 1 |
| `per_page` | int | 10 / 20 / 50. Default: 20 |
| `orderby` | string | `date` / `title` / `status`. Default: `date` |
| `order` | string | `asc` / `desc`. Default: `desc` |

**`POST /cases` Request Body:**
```json
{ "form_id": 4, "total_steps": 6 }
```

**`POST /cases` Response (201):**
```json
{
  "id": 114, "title": "Case #114", "status": "draft",
  "hm_form_id": 4, "current_step": 1, "total_steps": 6,
  "author_id": 20, "supervisor_id": 5, "hm_form_data": {}
}
```

---

### 10.3 Cases — Single Record

| Method | Endpoint | Controller | Description |
|---|---|---|---|
| `GET` | `/cases/{id}` | `CaseController::show` | Full case detail with permissions |
| `DELETE` | `/cases/{id}` | `CaseController::delete` | Soft-delete via `wp_trash_post()` |

**`GET /cases/{id}` Response `data`:**

```json
{
  "id": 113,
  "title": "AKSHAR INDUSTRIES #113",
  "status": "in_review",
  "progress": 67,
  "current_step": 4,
  "total_steps": 6,
  "gf_form_id": 4,
  "form_data": { "100": "AKSHAR INDUSTRIES", "7": "Cutting Tools", "17": ["Tool Life", "Surface Finish"] },
  "taxonomies": {
    "hm_product_type": [{ "term_id": 3, "name": "Cutting Tools", "slug": "cutting-tools" }],
    "hm_industry_segment": [{ "term_id": 7, "name": "Medical", "slug": "medical" }]
  },
  "meta_fields": {
    "_case_customer_name": "AKSHAR INDUSTRIES",
    "_case_city": "Rajkot",
    "_case_state": "Gujarat"
  },
  "author": { "id": 20, "full_name": "John Doe", "role": "hm_field_agent" },
  "reviewer": { "id": 5, "full_name": "Jane Manager", "role": "hm_manager" },
  "review_message": null,
  "review_history": [],
  "permissions": {
    "can_edit": false, "can_delete": false, "can_approve": true,
    "can_reject": true, "can_return": true, "can_submit": false
  },
  "created_at": "2026-03-25T10:00:00+00:00",
  "updated_at": "2026-03-26T13:54:39+00:00",
  "submitted_at": "2026-03-26T13:54:39"
}
```

---

### 10.4 Form Data Sync

| Method | Endpoint | Controller | Description |
|---|---|---|---|
| `GET` | `/cases/{id}/form-data` | `CaseController::getFormData` | Retrieve saved fields + step state for SPA hydration |
| `PATCH` | `/cases/{id}/form-data` | `CaseController::updateFormData` | Save partial or full field values |

**`PATCH` Request Body:**
```json
{ "fields": { "100": "Acme Corp", "7": "Cutting Tools", "2": "New York" }, "current_step": 3 }
```

**`PATCH` Response `data`:**
```json
{ "id": 113, "title": "Acme Corp #113", "current_step": 3, "progress": 50 }
```

**`GET` Response `data`:**
```json
{ "current_step": 3, "total_steps": 6, "progress": 50, "fields": { "100": "Acme Corp", "7": "Cutting Tools" } }
```

Fields are **merged** (not replaced) on each PATCH — existing field values are preserved unless explicitly overwritten.

---

### 10.5 Status Transitions

| Method | Endpoint | Controller | `message` required |
|---|---|---|---|
| `POST` | `/cases/{id}/submit` | `CaseController::submit` | No |
| `POST` | `/cases/{id}/approve` | `CaseController::approve` | No |
| `POST` | `/cases/{id}/reject` | `CaseController::reject` | **Yes** |
| `POST` | `/cases/{id}/return` | `CaseController::returnForRevision` | **Yes** |
| `PATCH` | `/cases/{id}/status` | `CaseController::overrideStatus` | Optional |

`reject` and `return` require `{ "message": "..." }` in the request body. A `400 CSP_VALIDATION_ERROR` is returned if message is absent or empty.

**Override request** (admin only):
```json
{ "status": "approved", "message": "Manually approved.", "override": true }
```
The `override` field is not strictly validated server-side — any admin call to this endpoint bypasses workflow validation by design.

**All transition responses `data`:**
```json
{ "id": 113, "title": "...", "post_status": "approved", "return_reason": "" }
```

---

### 10.6 Dashboard

| Method | Endpoint | Controller | Description |
|---|---|---|---|
| `GET` | `/dashboard/stats` | `DashboardController::getStats` | Activity panel counters, role-scoped |
| `GET` | `/dashboard/filters` | `DashboardController::getFilters` | Taxonomy term lists for sidebar filter UI |

**Stats response `data`:**
```json
{ "pending_review": 5, "returned": 2, "approved": 18, "rejected": 3, "draft": 7, "total": 35 }
```

Stats are scoped by the same role logic as `GET /cases` (admin/marketing = all; manager = own + agents; field_agent = own only).

**Filters response `data`:**
```json
{
  "product_types": [{ "term_id": 3, "name": "Cutting Tools", "slug": "cutting-tools", "count": 12 }],
  "industry_segments": [{ "term_id": 7, "name": "Medical", "slug": "medical", "count": 4 }],
  "machine_types": [],
  "machine_makes": [],
  "tool_brands": [],
  "solution_types": [],
  "submitted_by": []
}
```

---

### 10.7 Users

| Method | Endpoint | Controller | Scope |
|---|---|---|---|
| `GET` | `/users` | `UserController::index` | Admin: all users; Manager: own agents only; Field agent: 403 |

**Query parameters:** `search` (wildcard on login/email/display_name), `role`, `status` (`active` / `inactive` / `all`), `orderby` (`date` / `name`), `order`, `page`, `per_page`

**User DTO shape:**
```json
{
  "id": 20, "full_name": "Jane Field", "email": "jane@example.com",
  "role": "hm_field_agent", "status": "active",
  "avatar_url": "https://...",
  "supervisor": { "id": 5, "full_name": "John Manager" },
  "agents": [],
  "cases_count": { "total": 0, "draft": 0, "in_review": 0, "approved": 0 },
  "created_at": "2026-01-15T09:00:00+00:00"
}
```

---

### 10.8 Notifications

| Method | Endpoint | Controller | Description |
|---|---|---|---|
| `GET` | `/notifications` | `NotificationController::index` | Paginated list for current user |
| `PATCH` | `/notifications/{id}/read` | `NotificationController::markAsRead` | Mark single notification as read |
| `POST` | `/notifications/read-all` | `NotificationController::readAll` | Mark all as read |
| `GET` | `/notifications/unread-count` | `NotificationController::getUnreadCount` | Badge counter |

**`GET /notifications` params:** `is_read` (bool filter), `page`, `per_page`

**Notification DTO shape:**
```json
{
  "id": 42, "type": "case_returned",
  "case_id": 113, "case_title": "AKSHAR INDUSTRIES #113",
  "message": "Your case study was returned for revision. Reason: Please correct values.",
  "is_read": false, "created_at": "2026-03-26T15:00:00+00:00"
}
```

`markAsRead` includes ownership check (`WHERE id = X AND user_id = Y`) — users cannot mark other users' notifications.

---

## 11. Services Layer

### `CaseService`

Primary case data service — all other services depend on it.

- **`createDraftCase(form_id, total_steps)`** — inserts `hm_case` post with status `draft`, writes all initial meta keys (`hm_form_data`, `total_steps`, `current_step`, `hm_form_id`, `author_id`, `supervisor_id`, `return_reason`). Title set to `Case #<ID>`. Returns `int $case_id` or `WP_Error`.
- **`getCase(case_id)`** — returns hydrated associative array: `id`, `title`, `post_status`, `author_id`, `supervisor_id`, `total_steps`, `current_step`, `return_reason`, `hm_form_data` (decoded JSON array).
- **`determineSupervisorId(WP_User)`** — resolves reviewer: `administrator` → `null`; `hm_manager` → first admin user; `hm_field_agent` → `_assigned_manager_id`.

### `CaseFormDataService`

- **`saveFormData(case_id, fields, current_step)`** — merges incoming `fields` array into existing `hm_form_data` JSON (preserves unmodified fields), updates `current_step`, processes `sync_on:'always'` entries (title update for field 100, meta writes), returns progress DTO `{ id, title, current_step, progress }`.
- **`getFormData(case_id)`** — returns `{ current_step, total_steps, progress, fields }`.
- **`calculateProgress(current_step, total_steps)`** → `int` 0–100, `min(100, max(0, round(...)))`.

### `CaseStatusService`

Orchestrates all status transitions. Loads `StatusTransitions.php` config in constructor.

- **`submit(case_id, user_id)`** — checks `canSubmit`, resolves next status (admin → `APPROVED`; others → `IN_REVIEW`), calls `TaxonomyService::syncTaxonomies()`, clears `return_reason`, sets `_case_submitted_at`, dispatches notifications.
- **`approve(case_id, user_id)`** — checks `canApprove`, calls internal `transition()`, dispatches `onCaseApproved`.
- **`reject(case_id, user_id, reason)`** — validates non-empty reason, checks `canReject`, transitions, dispatches `onCaseRejected`.
- **`return(case_id, user_id, reason)`** — validates non-empty reason, checks `canReturn`, transitions, dispatches `onCaseReturned`.
- **`override(case_id, user_id, status, reason)`** — admin-only, direct `wp_update_post()`, syncs taxonomies, no workflow validation.
- Internal **`transition(case_id, action, reason, onSuccess)`** — looks up valid next status from config, calls `wp_update_post()`, writes/clears `return_reason`, executes `$onSuccess` callback.

### `CasePermissionService`

Single centralised ACL service. All checks compute `{ status, is_author, is_supervisor, is_admin, is_marketing }` from `CaseService::getCase()` + `get_userdata()`.

| Method | Returns `true` when |
|---|---|
| `canView` | is_admin ∨ is_marketing ∨ is_author ∨ is_supervisor |
| `canEdit` | admin (own: always; others: status ∈ {in_review, returned}); author: status ∈ {draft, returned}; supervisor: status ∈ {in_review, returned} |
| `canDelete` | admin: always; author: status = draft only |
| `canSubmit` | author only, status ∈ {draft, returned} |
| `canApprove` | (admin ∧ ¬author) ∨ supervisor, status ∈ {in_review, returned} |
| `canReject` | Same as `canApprove` |
| `canReturn` | (admin ∧ ¬author) ∨ supervisor, status = in_review only |

**`getPermissions(case_id, user_id)`** returns all six flags as an array, embedded in every `GET /cases/{id}` response.

### `GravityFormsService`

- **`getFormSchema(form_id)`** — calls `GFAPI::get_form($form_id)`, transforms raw GF form object to a clean DTO. Returns `null` if Gravity Forms is not active (`class_exists('GFAPI')` check).
- Splits fields into steps by detecting `type: "page"` field objects as step boundaries.
- Strips all internal GF properties; exposes only: `id`, `type`, `inputType`, `label`, `is_required`, `is_hidden`, `visibility`, `size`, `placeholder`, `css_class`, `choices`, `conditional_logic`, `validation`, `numberFormat`, `adminLabel`, `description`, `defaultValue`, `inputs` (for checkbox sub-inputs), `enableCalculation`, `calculationFormula`, `calculation` (with parsed `referencedFields[]`).

### `TaxonomyService`

- **`syncTaxonomies(case_id, form_data)`** — iterates FormFieldMap entries with `storage.taxonomy` set. For each: calls `term_exists()`, creates term with `wp_insert_term()` if missing, then `wp_set_object_terms()` with `append: false` (replaces existing terms for that taxonomy). Clears terms if field value is empty.
- **`removeTaxonomies(case_id)`** — clears all mapped taxonomy terms (for use before soft delete).

### `NotificationService`

Dual-channel dispatch: DB row + email on every notification.

- **`notify(type, case_id, recipient_id, message)`** — `$wpdb->insert()` into `{prefix}csp_notifications` + `wp_mail()` to recipient email.
- **`onCaseSubmitted(case_id, reviewer_id)`** → notifies reviewer.
- **`onCaseApproved(case_id, author_id)`** → notifies author + all admins (excluding author if admin).
- **`onCaseRejected(case_id, author_id, reason)`** → notifies author + all admins.
- **`onCaseReturned(case_id, author_id, reason)`** → notifies author only.

### `UserService`

- **`rebuildManagerAgents(manager_id)`** — queries all active users with `_assigned_manager_id = manager_id` (excludes `_user_status = inactive`), writes result as JSON-encoded ID array to `_assigned_agent_ids` on the manager. Called automatically by `UserHooks` on meta changes.

---

## 12. Repositories Layer

### `CaseRepository`

Wraps `WP_Query`. Constructor loads `FormFieldMap.php` for dynamic taxonomy filter generation.

**`getCases(args)`** builds query with:
- `post_type: hm_case`, `fields: ids` (returns IDs only for performance)
- Status filter with CSV parsing (defaults to all non-trash statuses)
- `author__in` array for role scoping (passed in by controllers)
- Dynamic `tax_query` entries built from all FormFieldMap entries with `storage.taxonomy` set
- Standard WP `s` search (works because post title contains customer name)
- `date_query` for `date_from` / `date_to`
- Pagination: `posts_per_page` + `paged`
- `orderby`: `date` / `title` / `post_status`

**Returns:** `{ cases: int[], total: int, total_pages: int, page: int, per_page: int }`

### `UserRepository`

Wraps `WP_User_Query`. Returns user IDs only (`fields: 'ID'`).

**`getUsers(args)`** supports:
- Role filter
- Status filter via `meta_query` on `_user_status` — `active` (NOT EXISTS or != inactive), `inactive`, or `all`
- `include` array for manager-scoped visibility (passed in by `UserController`)
- Wildcard search: `'*' . $search . '*'` on login, nicename, email, display_name
- `orderby` mapping: `date` → `user_registered`, `name` → `display_name`

### `NotificationRepository`

Raw `$wpdb` queries. Uses `$wpdb->prepare()` with spread operator for all queries.

- **`getNotifications(user_id, args)`** — SELECT with optional `is_read` filter, `ORDER BY created_at DESC`, paginated
- **`markAsRead(id, user_id)`** — UPDATE with dual ownership check (`id = X AND user_id = Y`)
- **`markAllAsRead(user_id)`** — bulk UPDATE for all unread
- **`getUnreadCount(user_id)`** — COUNT query

---

## 13. DTO & Response Layer

### `DTOMapper`

Single class responsible for all data shaping. Reads `FormFieldMap` in constructor for dynamic field appending.

**`toCaseListItem(case_id, case_raw)`**

Base fields: `id`, `title`, `status`, `progress`, `current_step`, `total_steps`, `author { id, full_name, role }`, `reviewer { id, full_name }`, `created_at`, `updated_at`, `submitted_at`

Dynamic fields appended for each FormFieldMap entry with `display.in_list: true`:
- If `storage.taxonomy` → fetches first term name via `wp_get_post_terms()`
- If `storage.meta_key` → fetches via `get_post_meta()`
- Key name derived from taxonomy slug, meta key (stripped of `_case_` prefix), or sanitized label

**`toCaseDetail(case_id, case_raw, permissions)`**

All list item fields plus: `gf_form_id`, `form_data` (raw JSON), `taxonomies` (grouped by taxonomy slug, full term objects), `meta_fields` (all FormFieldMap meta keys), `review_message`, `review_history[]`, `permissions {}`.

**`toUser(user_id)`**

`id`, `full_name`, `email`, `role`, `status`, `avatar_url` (from `get_avatar_url()`), `supervisor { id, full_name }`, `agents[]` (decoded from `_assigned_agent_ids`), `cases_count` (stub object), `created_at`

**`toNotification(notif_raw)`**

`id`, `type`, `case_id`, `case_title` (resolved from post), `message`, `is_read` (cast to bool), `created_at` (ISO 8601 via `gmdate('c', ...)`)

### `ApiResponse`

```php
// Both return WP_REST_Response
ApiResponse::success($data = null, $message = '', $meta = null, $status = 200)
ApiResponse::error($code, $message, $status = 400, $data = null)
```

---

## 14. Notifications System

Triggered from `CaseStatusService` after every status transition.

### Notification Types

| Type | Trigger | Recipient(s) |
|---|---|---|
| `case_submitted` | Case submitted → in_review | Assigned reviewer (`supervisor_id`) |
| `case_approved` | Case approved | Case author |
| `case_approved_global` | Case approved | All administrators (excluding author) |
| `case_rejected` | Case rejected | Case author |
| `case_rejected_global` | Case rejected | All administrators (excluding author) |
| `case_returned` | Case returned for revision | Case author only |

Each notification is simultaneously:
1. Inserted into `{prefix}csp_notifications` table via `$wpdb->insert()`
2. Sent via `wp_mail()` to the recipient's `user_email`

---

## 15. Database Schema

### Custom Table: `{prefix}csp_notifications`

Created on plugin activation by `Migrations::up()` using `dbDelta()`:

```sql
CREATE TABLE {prefix}csp_notifications (
    id          BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
    user_id     BIGINT UNSIGNED   NOT NULL,
    type        VARCHAR(50)       NOT NULL,
    case_id     BIGINT UNSIGNED   NOT NULL,
    message     TEXT              NOT NULL,
    is_read     TINYINT(1)        NOT NULL DEFAULT 0,
    created_at  DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY user_id (user_id),
    KEY case_id (case_id),
    KEY is_read  (is_read)
) ENGINE=InnoDB;
```

---

## 16. Post Meta Schema

### System Meta Keys (always present, managed by the plugin)

| Meta Key | Type | Written by | Description |
|---|---|---|---|
| `hm_form_id` | int | `CaseService::createDraftCase` | Gravity Forms form ID |
| `hm_form_data` | JSON string | `CaseFormDataService::saveFormData` | Full field map `{ "field_id": value }` — source of truth |
| `total_steps` | int | `CaseService::createDraftCase` | Total steps from form schema |
| `current_step` | int | `CaseFormDataService::saveFormData` | Last step user was on |
| `author_id` | int | `CaseService::createDraftCase` | Creator's WP user ID |
| `supervisor_id` | int\|null | `CaseService::createDraftCase` | Assigned reviewer's WP user ID |
| `return_reason` | string | `CaseStatusService` | Most recent reject/return message |
| `_case_submitted_at` | datetime string | `CaseStatusService::submit` | Timestamp of final submit |
| `_case_review_history` | JSON string | (planned) | Array of `{ status, changed_by, changed_at, message }` |

### Config-Driven Meta Keys (defined in `FormFieldMap.php`)

| Meta Key | Source Field | Label | in_card |
|---|---|---|---|
| `_case_customer_name` | 100 | Customer Name | ✅ |
| `_case_customer_id` | 99 | Customer ID | — |
| `_case_city` | 2 | City | ✅ |
| `_case_state` | 4 | State | ✅ |

> These keys are created on first write by `update_post_meta()` — no migration required. The list grows automatically as entries are added to `FormFieldMap.php`.

---

## 17. User Meta Schema

| Meta Key | Set on role | Description |
|---|---|---|
| `_assigned_manager_id` | `hm_field_agent` | ID of the agent's assigned manager |
| `_assigned_agent_ids` | `hm_manager` | JSON-encoded array of subordinate agent IDs. Auto-rebuilt by `UserHooks` on any `_assigned_manager_id` change. |
| `_user_status` | any | `active` (default; absence also = active) or `inactive` (soft delete). Filtered in all `UserRepository` queries by default. |

---

## 18. Access Control Matrix

### Field Agent (own cases only)

| Action | draft | in_review | returned | approved | rejected |
|---|---|---|---|---|---|
| View | ✅ | ✅ | ✅ | ✅ | ✅ |
| Edit | ✅ | ❌ | ✅ | ❌ | ❌ |
| Submit | ✅ | — | ✅ | — | — |
| Delete (soft) | ✅ | ❌ | ❌ | ❌ | ❌ |
| Approve / Reject / Return | ❌ | ❌ | ❌ | ❌ | ❌ |

### Manager (own cases: same as agent above; subordinate agent cases:)

| Action | draft | in_review | returned | approved | rejected |
|---|---|---|---|---|---|
| View | ✅ | ✅ | ✅ | ✅ | ✅ |
| Edit | ❌ | ✅ | ✅ | ❌ | ❌ |
| Approve | ❌ | ✅ | ✅ | ❌ | ❌ |
| Reject | ❌ | ✅ | ✅ | ❌ | ❌ |
| Return | ❌ | ✅ | ❌ | ❌ | ❌ |

### Administrator (non-own cases)

| Action | any status |
|---|---|
| View | ✅ always |
| Edit | in_review, returned only |
| Approve / Reject | in_review, returned only |
| Return | in_review only |
| Delete (soft) | ✅ always |
| Status Override | ✅ any → any, no validation |

### Marketing

View all cases in all statuses. All write operations blocked at `CasePermissionService` level (`is_marketing` check returns `false` for all mutating methods).

---

## 19. Hooks

### `UserHooks` (`src/Hooks/UserHooks.php`)

Registered via `UserHooks::register()` in `Plugin::boot()`. Keeps the `_assigned_agent_ids` inverse index in sync automatically.

| WP Hook | Callback | Trigger |
|---|---|---|
| `updated_user_meta` | `onUserMetaChanged` | After any user meta update |
| `added_user_meta` | `onUserMetaChanged` | After new user meta added |
| `deleted_user_meta` | `onUserMetaChanged` | After user meta deleted |
| `update_user_metadata` (filter) | `captureOldManagerBeforeUpdate` | Before meta update — stores old manager ID |
| `delete_user_metadata` (filter) | `captureOldManagerBeforeDelete` | Before meta delete — stores old manager ID |

**Logic in `onUserMetaChanged`:**

When `_assigned_manager_id` changes: rebuilds `_assigned_agent_ids` for the **new** manager, and also for the **old** manager (recovered from the `$csp_old_manager_ids` global captured by the pre-update filter) — ensuring both managers' agent lists stay accurate.

When `_user_status` changes: rebuilds the agent's manager's `_assigned_agent_ids` to exclude/include the now-inactive/active user.

---

## 20. Exception Handling

Three typed exception classes in `src/Exceptions/`:

| Class | Extends | Extra properties |
|---|---|---|
| `ApiException` | `RuntimeException` | `$errorCode` (string), `$httpStatus` (int), `$data` (mixed) |
| `ValidationException` | — | (stub, for future use) |
| `PermissionException` | — | (stub, for future use) |

`Router::handle()` catch block:

```php
catch (ApiException $e) {
    return ApiResponse::error($e->getErrorCode(), $e->getMessage(), $e->getHttpStatus(), $e->getData());
}
catch (\Throwable $e) {
    return ApiResponse::error(ErrorCodes::INTERNAL_ERROR, 'Server error: ' . $e->getMessage(), 500);
}
```

---

## 21. Extension Guide

### Adding a New Taxonomy

1. Create `src/Taxonomies/MyNewTaxonomy.php` extending `AbstractTaxonomy`:

```php
namespace CSP\Taxonomies;

class MyNewTaxonomy extends AbstractTaxonomy {
    public function get_taxonomy(): string  { return 'hm_my_new'; }
    public function get_singular_label(): string { return 'My New'; }
    public function get_plural_label(): string   { return 'My News'; }
}
```

2. Register it in `Plugin::boot()`:

```php
(new \CSP\Taxonomies\MyNewTaxonomy())->register();
```

3. Add an entry to `FormFieldMap.php`:

```php
[
    'field_id'  => 42,
    'gf_type'   => 'select',
    'label'     => 'My New Field',
    'storage'   => ['taxonomy' => 'hm_my_new', 'meta_key' => null],
    'display'   => ['in_list' => true, 'in_card' => true, 'in_filters' => true],
    'sync_on'   => 'submit',
]
```

Zero other changes required. Terms are auto-created by `TaxonomyService` on first case submit with that value.

---

### Adding a New Meta Filter Field

1. Add an entry to `FormFieldMap.php`:

```php
[
    'field_id'  => 55,
    'gf_type'   => 'text',
    'label'     => 'Contract Number',
    'storage'   => ['taxonomy' => null, 'meta_key' => '_case_contract_number'],
    'display'   => ['in_list' => true, 'in_card' => true, 'in_filters' => false],
    'sync_on'   => 'submit',
]
```

- If `in_filters: true` → automatically queryable via `GET /cases?_case_contract_number=value` and returned in `GET /dashboard/filters`.
- If `in_list: true` → automatically included in list API responses via `DTOMapper::toCaseListItem()`.
- No migration needed — `update_post_meta()` creates the key on first write.

---

### Adding a New API Endpoint

1. Add route in `Router::registerRoutes()`:

```php
$this->addRoute($ns, 'GET', '/my-resource', MyController::class, 'index');
```

2. Create `src/API/Controllers/MyController.php` with constructor-injected dependencies.

3. Register as singleton in `Plugin::registerBindings()`:

```php
$this->container->singleton(\CSP\API\Controllers\MyController::class, function ($c) {
    return new \CSP\API\Controllers\MyController(
        $c->get(\CSP\Repositories\MyRepository::class)
    );
});
```

The full middleware pipeline (sanitize → auth → permission) runs automatically for all routes registered via `addRoute()`.