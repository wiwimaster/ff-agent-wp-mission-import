# AI Coding Agent Instructions for ff-agent-wp-mission-import

Concise, project-specific guidance to become productive fast. Focus on THIS repo’s current patterns (no aspirational rules).

## Big Picture
WordPress plugin that imports "Einsatz" (mission) data from the external FF Agent JSON API into a custom post type `mission`, stores structured metadata, and attaches remote images (first as featured image, rest as gallery). Admin UI currently minimal and hard‑wired to trigger a single mission import for development/demo.

## Load / Bootstrap Flow
1. Main plugin file `ff-agent-wp-mission-import.php` defines constants (API root, widget UID, paths) and loads `classes/class_autoloader.php`.
2. Autoloader: Any class beginning with prefix `ffami_` → strips prefix, builds `class_<rest>.php`, recursively searches under `classes/` (keep filenames `class_<name>.php`). When creating new classes, follow this naming to ensure autoloading.
3. Instantiates `ffami_plugin` which:
   - Creates `ffami_vars` (defines derived constants like `FFAMI_FILE_MAIN`).
   - Registers CPT via `ffami_mission_post_type`.
   - Loads backend hooks via `ffami_backend` (admin menu + panel).

## Core Domain Objects
- `ffami_mission` (in `classes/basics/class_mission.php`): In‑memory representation plus logic to parse raw API mission JSON. After import, it stores metadata (post meta keys: `ffami_mission_id`, `_url`, `_duration`, `_location`, `_person_count`, `_type`, `_vehicles`, `_hash`). Duration parsing expects formats like `2 h 15 min` or `45 min`; throws on mismatch.
- Integrity / change detection uses MD5 hashes, but there is inconsistency: stored meta key is `ffami_mission_hash`; lookup in `ffami_single_mission_import` uses `ffami_mission_md5_hash` (bug). Maintain or fix consciously.

## Import Process (`ffami_single_mission_import`)
Input: mission ID string (timestamp-like) and mission URL fragment beginning with `/hpWidget/<UID>/mission/<uuid>`.
Flow:
1. Build full URL: `FFAMI_DATA_ROOT . $mission->url`; fetch JSON via `wp_remote_get` (timeout 10s, basic error handling / admin notices).
2. Decode JSON and call `$mission->import_mission_data()` to populate fields + compute MD5 hash of entire raw mission array.
3. Determine new vs existing:
   - `is_new_mission()` WP_Query on meta key `ffami_mission_id`.
   - `is_updated_mission()` compares new hash (currently of serialized $mission object, not raw data) against stored hash (meta key mismatch issue).
4. Save: create or (intended) update `mission` post with slug: `Y-m-d-h-i_<sanitized title>`; then call `$mission->store_mission_metadata()`.
5. If images present, `ffami_image_import` handles media.

## Image Handling (`ffami_image_import`)
- Accepts `$mission->image_urls` (first becomes featured image; remainder appended as gallery shortcode).
- Adds `?a=.jpg` to each remote URL so WP treats it as image.
- Avoids duplicate imports by checking attachment meta `_external_image_url`.
- Potential bug: uses `$date` variable (undefined) when deciding dated upload paths and when updating attachment dates; should likely derive from `$this->mission->datetime`.

## Custom Post Type
Defined in `class_mission_post_type.php` with slug `mission`; supports `title`, `editor`, `thumbnail`, `has_archive` true, public.

## Constants / Configuration
- `FFAMI_UID`, `FFAMI_DATA_ROOT`, `FFAMI_DATA_PATH` currently hard-coded. Mission URLs in code are manually specified for testing. Future automation could iterate `/years` index JSON (commented logic in admin panel hints at hierarchical year data files).
- `FFAMI_FILE_MAIN` defined dynamically in `ffami_vars` as `FFAMI_DATA_PATH . FFAMI_UID` (remote index file root).

## Admin UI (`ffami_admin_panel`)
- Registers a top-level menu. Current `render_admin_page()` hard-codes instantiation of a single mission import and contains large commented exploratory code for iterating year JSONs. Treat as development sandbox; refactor before exposing to end users.

## Key Known Issues / Inconsistencies (handle deliberately)
- Meta key mismatch: previously stored only `ffami_mission_hash` while code read `ffami_mission_md5_hash` → now both keys written; import reads either for backward compatibility.
- Hash comparison unified: mission hash now computed from raw JSON via stable `json_encode` instead of mixed `serialize` usage.
- Undefined `$date` in image import removed; timestamp derived from `mission->datetime`.
- `save_mission_data()` stray parameter usage removed; update logic now skips unchanged missions (returns existing post ID without rewriting).

When modifying, either fix comprehensively with migration strategy or keep consistent with existing behavior and document.

## Extending / Adding Features
- New import types: follow prefix `ffami_` and place class file under `classes/` (subfolders allowed). Ensure filename matches `class_<name>.php` pattern.
- Network: already using `wp_remote_get`; for bulk add caching / transient and maybe retries on `WP_Error`.
- For batch import, build an index reader using `FFAMI_FILE_MAIN` JSON (structure hinted by commented code in admin panel: expects `years` object with nested URLs). Cache results to avoid repeated remote fetches.

## Development / Testing Notes
- No build step; pure PHP plugin. Activate in a WordPress instance (PHP >= 8.1 recommended due to typed properties & `DateInterval` usage).
- Create sample mission JSONs by saving remote responses to speed offline iteration if needed.
- Edge cases to test when changing parser: missing fields (null-safe fallbacks), unexpected duration strings, empty image arrays.

## Safe Change Guidance
- Before renaming meta keys, provide backward compatibility lookup to preserve existing sites.
- Wrap remote fetches with error handling and bail gracefully—do not fatal within admin page render.
- Keep autoloader recursion performance acceptable (avoid very deep directory trees or name collisions).

## Quick Reference (Examples)
- Create new mission import: `new ffami_single_mission_import($id, '/hpWidget/' . FFAMI_UID . '/mission/' . $uuid);`
- Access mission meta in theme: `get_post_meta($post_id, 'ffami_mission_location', true);`

End of instructions. Keep additions concise and aligned with observed code. If adding conventions, ensure they are first implemented in code.
